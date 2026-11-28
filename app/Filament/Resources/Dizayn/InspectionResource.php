<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\InspectionResource\Pages;
use App\Models\Dizayn\DesignObject;
use App\Models\Dizayn\District;
use App\Models\Dizayn\Inspection;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\Dizayn\Staff;
use App\Models\User;
use App\Support\Dizayn\InspectionReview;
use App\Support\Latin;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Dizayn-kod: tuman xodimlari yuborgan dalillar (yechildi / qo'yildi) — tasdiqlash, jarayonli tasdiqlash, rad etish,
 * moderator jarayoni. Natija xodimga Dizayn-kod boti orqali boradi (outbox). Mantiq — App\Support\Dizayn\InspectionReview.
 */
class InspectionResource extends Resource
{
    protected static ?string $model = Inspection::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'Tekshiruvlar';

    protected static ?string $modelLabel = 'tekshiruv';

    protected static ?string $pluralModelLabel = 'Tekshiruvlar';

    protected static ?string $slug = 'dizayn/tekshiruvlar';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return ($n = Inspection::whereIn('review', Inspection::OPEN)->count()) ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tasdiqlash kutayotgan dalillar';
    }

    /** Ko'rib chiqish oynasining yuqori qismi: konsepsiya + dalil + oldingilari. */
    private static function card(): \Closure
    {
        return fn (Inspection $record) => view('filament.dizayn.inspection-review', ['record' => $record]);
    }

    private static function reasons(): array
    {
        return array_map(fn ($r) => $r[0], Inspection::PROCESS_REASONS);
    }

    private static function by(): ?string
    {
        return auth()->user()?->email;
    }

    private static function done(bool $ok, string $title): void
    {
        $ok ? Notification::make()->title($title.' — xodimga bot orqali xabar boradi')->success()->send()
            : Notification::make()->title('Bu dalil allaqachon ko‘rib chiqilgan')->warning()->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('review')->with(['object.street.district', 'staff']))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')->label('Yuborilgan')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->formatStateUsing(fn ($state) => Latin::of($state))->searchable(),
                Tables\Columns\TextColumn::make('object.street.district.name')->label('Tuman')
                    ->formatStateUsing(fn ($state) => preg_replace('/\s+tumani$/u', '', Latin::of($state))),
                Tables\Columns\TextColumn::make('object_label')->label('Obyekt')->wrap()
                    ->state(fn (Inspection $i) => $i->object?->label())
                    ->description(fn (Inspection $i) => Latin::of($i->object?->street?->name))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('object', fn ($o) => $o
                        ->where(fn ($w) => $w->whereRaw('LOWER(shop_name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhere('stir', 'like', "%$search%"))))
                    ->url(fn (Inspection $i) => $i->object ? DesignObjectResource::getUrl('view', ['record' => $i->object]) : null),
                Tables\Columns\TextColumn::make('kind')->label('Nima qilindi')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::KINDS[$state] ?? $state)
                    ->color(fn ($state) => $state === DesignObject::INSTALLED ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('comment')->label('Izoh')->limit(60)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('distance_m')->label('Xodim ↔ obyekt')->placeholder('—')->alignEnd()
                    ->formatStateUsing(fn ($state) => (int) $state.' m')
                    ->color(fn ($state) => $state !== null && $state > 100 ? 'danger' : null)
                    ->tooltip('Xodim turgan joy bilan u xaritada belgilagan obyekt joyi orasidagi masofa'),
                Tables\Columns\TextColumn::make('review')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::REVIEWS[$state] ?? $state)
                    ->color(fn ($state) => Inspection::REVIEW_COLORS[$state] ?? 'gray')
                    ->description(fn (Inspection $i) => $i->review_note ? \Illuminate\Support\Str::limit($i->review_note, 70) : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Nima qilindi')->options(Inspection::KINDS),
                Tables\Filters\SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => District::orderBy('sort_order')->pluck('name', 'id')->map(fn ($n) => Latin::of($n)))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('object', fn ($o) => $o->inDistrict((int) $data['value'])) : $query),
                Tables\Filters\SelectFilter::make('staff_id')->label('Xodim')->searchable()
                    ->options(fn () => Staff::orderBy('full_name')->pluck('full_name', 'id')->map(fn ($n) => Latin::of($n))),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label('Ko‘rish')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (Inspection $i) => Latin::of($i->object?->street?->name).' · '.$i->object?->label())
                    ->modalContent(self::card())->modalSubmitAction(false)->modalCancelActionLabel('Yopish')->modalWidth('5xl'),
                // hech narsa belgilanmasa — to'liq tasdiq; kamchilik belgilansa — «Jarayonli tasdiqlash»
                Tables\Actions\Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Inspection $i) => in_array($i->review, Inspection::LOCKED, true))
                    ->modalHeading('Dalilni tasdiqlash')->modalWidth('5xl')
                    ->modalDescription(fn (Inspection $i) => match (true) {
                        $i->kind !== DesignObject::INSTALLED => 'Tasdiqlansa, obyekt «Jarayonda»ga o‘tadi va xodimga bot orqali xabar boradi.',
                        $i->review === Inspection::CONFIRMING => 'To‘liq tasdiqlanadi: obyekt «Qo‘yildi» bo‘ladi. Xodimga bot orqali xabar boradi.',
                        default => 'Hech narsa belgilanmasa — to‘liq tasdiqlanadi: obyekt «Qo‘yildi» bo‘ladi. Kamchilik belgilansa — jarayonli tasdiqlanadi: '
                            .'obyekt «Jarayonda»ga o‘tadi, xodimga nimani tuzatish kerakligi boradi va u yangi dalil yuboradi.',
                    })
                    ->modalContent(self::card())
                    ->form([
                        Forms\Components\CheckboxList::make('reasons')->label('Kamchilik bo‘lsa belgilang (jarayonli tasdiqlash)')->options(self::reasons())
                            ->visible(fn (?Inspection $record) => $record?->kind === DesignObject::INSTALLED && in_array($record?->review, Inspection::OPEN, true)),
                        Forms\Components\Textarea::make('note')->label('Izoh (ixtiyoriy, xodimga ham boradi)')->rows(2)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Tasdiqlash')
                    ->action(function (Inspection $record, array $data) {
                        if (! empty($data['reasons'])) {
                            return self::done(InspectionReview::process($record, $data['reasons'], $data['note'] ?? null, self::by()), 'Jarayonli tasdiqlandi');
                        }
                        self::audit('approved', $record);
                        self::done(InspectionReview::approve($record, $data['note'] ?? null, self::by()), 'Tasdiqlandi');
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('reasons')->label('Sababni tahrirlash')->icon('heroicon-o-pencil-square')->color('warning')
                        ->visible(fn (Inspection $i) => $i->review === Inspection::CONFIRMING)
                        ->modalHeading('Nimani tuzatish kerak')->modalWidth('5xl')->modalContent(self::card())
                        ->fillForm(function (Inspection $record) {
                            [$reasons, $note] = InspectionReview::readReasons($record->review_note);

                            return ['reasons' => $reasons, 'note' => $note, 'notify' => true];
                        })
                        ->form([
                            Forms\Components\CheckboxList::make('reasons')->label('Nimani tuzatish kerak')->required()->options(self::reasons()),
                            Forms\Components\Textarea::make('note')->label('Qo‘shimcha izoh (ixtiyoriy)')->rows(2)->maxLength(500),
                            Forms\Components\Toggle::make('notify')->label('Xodimga botda qayta yuborish'),
                        ])
                        ->modalSubmitActionLabel('Saqlash')
                        ->action(fn (Inspection $record, array $data) => InspectionReview::editReasons($record, $data['reasons'] ?? [], $data['note'] ?? null,
                            self::by(), (bool) ($data['notify'] ?? false))
                            ? Notification::make()->success()->title(($data['notify'] ?? false) ? 'Saqlandi, xodimga yuboriladi' : 'Saqlandi')->send()
                            : Notification::make()->warning()->title('Saqlanmadi: dalil endi jarayonda emas')->send()),
                    Tables\Actions\Action::make('toProcess')->label('Jarayonga o‘tkazish')->icon('heroicon-o-arrow-path')->color('warning')
                        ->visible(fn (Inspection $i) => InspectionReview::canGoToProcess($i))
                        ->modalHeading('Jarayonga o‘tkazish')->modalWidth('5xl')->modalContent(self::card())
                        ->modalDescription('Obyekt «Jarayonda»ga o‘tadi: xodim uni «Jarayondagilar»da nimani tuzatish kerakligi bilan ko‘radi va qayta yuboradi.')
                        ->fillForm(fn (Inspection $record) => ['reasons' => InspectionReview::guessReasons($record->review_note), 'note' => $record->review_note, 'notify' => true])
                        ->form([
                            Forms\Components\CheckboxList::make('reasons')->label('Nimani tuzatish kerak')->required()->options(self::reasons()),
                            Forms\Components\Textarea::make('note')->label('Izoh (xodimga ham boradi)')->rows(2)->maxLength(500),
                            Forms\Components\Toggle::make('notify')->label('Xodimga botda hozir yuborish'),
                        ])
                        ->modalSubmitActionLabel('Jarayonga o‘tkazish')
                        ->action(fn (Inspection $record, array $data) => InspectionReview::rejectedToProcess($record, $data['reasons'] ?? [], $data['note'] ?? null,
                            self::by(), (bool) ($data['notify'] ?? false))
                            ? Notification::make()->success()->title('Jarayonga o‘tkazildi')->send()
                            : Notification::make()->warning()->title('O‘tkazilmadi: bu obyekt bo‘yicha yangiroq dalil bor')->send()),
                    Tables\Actions\Action::make('hold')->label('Moderator jarayoniga')->icon('heroicon-o-pause-circle')->color('info')
                        ->visible(fn (Inspection $i) => $i->review === Inspection::PENDING)
                        ->modalHeading('Moderator jarayoniga o‘tkazish')->modalWidth('5xl')->modalContent(self::card())
                        ->modalDescription('Dalil tasdiqlanmaydi, obyekt tugallangan deb belgilanmaydi. Izoh bilan kerakli muddat shu holatda turadi, '
                            .'xodim bu obyektga yangi dalil yubora olmaydi. Keyin «Xodimdan so‘rash», «Tasdiqlash» yoki «Rad etish».')
                        ->form([Forms\Components\Textarea::make('note')->label('Izoh (nima uchun, nima kutilyapti) — xodimga ham boradi')->required()->minLength(5)->rows(3)->maxLength(500)])
                        ->modalSubmitActionLabel('Moderator jarayoniga')
                        ->action(fn (Inspection $record, array $data) => self::done(InspectionReview::hold($record, $data['note'], self::by()), 'Moderator jarayonida')),
                    Tables\Actions\Action::make('ask')->label('Xodimdan so‘rash')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                        ->visible(fn (Inspection $i) => $i->review === Inspection::MODERATION)
                        ->modalHeading(fn (Inspection $i) => 'Xodimdan so‘rash: '.$i->object?->label())
                        ->modalDescription('Xodimga botda obyekt nomi, ko‘chasi va xaritadagi joyi bilan birga yuboriladi.')
                        ->fillForm(fn (Inspection $record) => ['question' => 'Shu obyekt joylashuvidagi muammo nima bo‘ldi? Hal bo‘lgan bo‘lsa, yozing.'
                            .($record->review_note ? "\n(Izoh: {$record->review_note})" : '')])
                        ->form([Forms\Components\Textarea::make('question')->label('Savol')->required()->minLength(5)->rows(4)->maxLength(1000)])
                        ->modalSubmitActionLabel('Yuborish')
                        ->action(fn (Inspection $record, array $data) => InspectionReview::ask($record, $data['question'])
                            ? Notification::make()->success()->title('Xodimga yuborildi')->send()
                            : Notification::make()->warning()->title('Yuborilmadi: xodim botga bog‘lanmagan yoki dalil endi moderator jarayonida emas')->send()),
                    Tables\Actions\Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                        // xato tasdiqni ham qaytarish mumkin: obyekt holati qolgan tasdiqlangan dalillarga qarab qaytadi
                        ->visible(fn (Inspection $i) => in_array($i->review, [...Inspection::LOCKED, Inspection::APPROVED], true))
                        ->modalHeading(fn (Inspection $i) => $i->review === Inspection::APPROVED ? 'Tasdiqlangan dalilni rad etish' : 'Dalilni rad etish')
                        ->modalDescription(fn (Inspection $i) => $i->review === Inspection::APPROVED
                            ? 'Tasdiq bekor qilinadi: obyekt holati qolgan tasdiqlangan dalillarga qarab qaytariladi (boshqasi bo‘lmasa — «Kutilmoqda»). Xodimga sababi bilan xabar boradi.' : null)
                        ->modalWidth('5xl')->modalContent(self::card())
                        ->form([Forms\Components\Textarea::make('reason')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->rows(3)->maxLength(500)])
                        ->modalSubmitActionLabel('Rad etish')
                        ->action(function (Inspection $record, array $data) {
                            self::audit('rejected', $record);
                            self::done(InspectionReview::reject($record, $data['reason'], self::by()), 'Rad etildi');
                        }),
                ])->label('Yana')->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('toProcess')->label('Jarayonga o‘tkazish')->icon('heroicon-o-arrow-path')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Belgilangan rad etilgan «qo‘yildi» dalillari «Jarayonda»ga o‘tadi (har bir obyektning eng oxirgisi, yangiroq dalili bo‘lmasa). '
                        .'Nimani tuzatish kerakligi rad etish izohidan olinadi; xodimlar ertalabki eslatmada ko‘radi.')
                    ->action(function (Collection $records) {
                        $n = $records->filter(fn (Inspection $i) => InspectionReview::rejectedToProcess($i, InspectionReview::guessReasons($i->review_note), $i->review_note, self::by()))->count();
                        Notification::make()->success()->title("Jarayonga o‘tkazildi: $n ta")->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->poll('30s')
            ->emptyStateHeading('Bu bosqichda dalil yo‘q');
    }

    private static function audit(string $what, Inspection $i): void
    {
        \App\Models\AuditLog::record('dizayn.inspection.'.$what, null, ['inspection' => $i->id, 'object' => $i->object_id, 'by' => auth()->id()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInspections::route('/')];
    }
}
