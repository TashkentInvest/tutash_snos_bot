<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InspectionResource\Pages;
use App\Models\Inspection;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Tekshiruvlar arxivi: faqat ko'rish (bot orqali yuborilgan ma'lumot o'zgartirilmaydi). */
class InspectionResource extends Resource
{
    protected static ?string $model = Inspection::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $modelLabel = 'tekshiruv';

    protected static ?string $pluralModelLabel = 'Tekshiruvlar arxivi';

    protected static ?int $navigationSort = 2;

    private const REVIEW = [
        Inspection::REVIEW_PENDING => 'Tasdiq kutilmoqda',
        Inspection::REVIEW_APPROVED => 'Tasdiqlangan',
        Inspection::REVIEW_REJECTED => 'Rad etilgan',
    ];

    /** Menyudagi belgi: moderator tasdig'ini kutayotgan "buzilgan" natijalar soni. */
    public static function getNavigationBadge(): ?string
    {
        $n = Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Moderator tasdig‘ini kutayotgan tekshiruvlar';
    }

    /** Tasdiqlash / rad etish amallari (ro'yxatda ham, ko'rish sahifasida ham). */
    public static function reviewActions(): array
    {
        return [
            Tables\Actions\Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                ->requiresConfirmation()->modalHeading('Obyekt buzilganini tasdiqlaysizmi?')
                ->modalDescription('Tasdiqlangach obyekt «Buzilgan» holatiga o‘tadi va hisoblarga qo‘shiladi.')
                ->visible(fn (Inspection $record) => $record->review_status === Inspection::REVIEW_PENDING)
                ->action(function (Inspection $record) {
                    $ok = \App\Support\InspectionReview::approve($record, auth()->id());
                    Notification::make()->title($ok ? 'Tasdiqlandi: obyekt «Buzilgan» deb belgilandi' : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                }),
            Tables\Actions\Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                ->modalHeading('Natijani rad etish')
                ->form([Forms\Components\Textarea::make('note')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->maxLength(500)])
                ->visible(fn (Inspection $record) => $record->review_status === Inspection::REVIEW_PENDING)
                ->action(function (Inspection $record, array $data) {
                    $ok = \App\Support\InspectionReview::reject($record, auth()->id(), $data['note']);
                    Notification::make()->title($ok ? 'Natija rad etildi, obyekt holati o‘zgarmadi' : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                }),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('status', Inspection::SUBMITTED);
    }

    public static function result(Inspection $in): string
    {
        return $in->is_demolished ? 'Buzilgan' : ($in->in_progress ? 'Jarayonda' : 'Buzilmagan');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['site.district', 'staff']))
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('site.district.name')->label('Tuman'),
                Tables\Columns\TextColumn::make('site.address')->label('Obyekt')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                Tables\Columns\TextColumn::make('result')->label('Natija')->badge()
                    ->state(fn (Inspection $record) => self::result($record))
                    ->color(fn (Inspection $record) => $record->is_demolished ? 'success' : ($record->in_progress ? 'info' : 'warning')),
                Tables\Columns\TextColumn::make('review_status')->label('Tasdiq')->badge()
                    ->formatStateUsing(fn ($state) => self::REVIEW[$state] ?? '—')
                    ->color(fn ($state) => match ($state) {
                        Inspection::REVIEW_PENDING => 'danger',
                        Inspection::REVIEW_APPROVED => 'success',
                        Inspection::REVIEW_REJECTED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('distance_m')->label('Masofa (m)')->placeholder('—'),
                Tables\Columns\IconColumn::make('suspicious')->label('Shubhali')->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')->trueColor('danger')->falseIcon('')->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district')->label('Tuman')
                    ->relationship('site.district', 'name'),
                Tables\Filters\SelectFilter::make('result')->label('Natija')
                    ->options(['demolished' => 'Buzilgan', 'in_progress' => 'Jarayonda', 'standing' => 'Buzilmagan'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'demolished' => $query->where('is_demolished', true),
                        'in_progress' => $query->where('is_demolished', false)->where('in_progress', true),
                        'standing' => $query->where('is_demolished', false)->where('in_progress', false),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('review_status')->label('Tasdiq')->options(self::REVIEW),
                Tables\Filters\TernaryFilter::make('suspicious')->label('Shubhali'),
                Tables\Filters\Filter::make('date')->label('Sana')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dan'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Gacha'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $v) => $query->whereDate('submitted_at', '>=', $v))
                        ->when($data['to'] ?? null, fn ($query, $v) => $query->whereDate('submitted_at', '<=', $v))),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...self::reviewActions()])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tekshiruv')->columns(3)->schema([
                Infolists\Components\TextEntry::make('site.district.name')->label('Tuman'),
                Infolists\Components\TextEntry::make('site.address')->label('Obyekt'),
                Infolists\Components\TextEntry::make('staff.full_name')->label('Xodim'),
                Infolists\Components\TextEntry::make('result')->label('Natija')->badge()
                    ->state(fn (Inspection $record) => self::result($record))
                    ->color(fn (Inspection $record) => $record->is_demolished ? 'success' : ($record->in_progress ? 'info' : 'warning')),
                Infolists\Components\TextEntry::make('review_status')->label('Tasdiq holati')->badge()
                    ->formatStateUsing(fn ($state) => self::REVIEW[$state] ?? 'Talab etilmaydi')
                    ->color(fn ($state) => $state === Inspection::REVIEW_PENDING ? 'danger' : ($state === Inspection::REVIEW_APPROVED ? 'success' : 'gray')),
                Infolists\Components\TextEntry::make('review_note')->label('Rad etish sababi')->visible(fn (Inspection $record) => (bool) $record->review_note),
                Infolists\Components\TextEntry::make('submitted_at')->label('Yuborilgan')->dateTime('d.m.Y H:i'),
                Infolists\Components\TextEntry::make('distance_m')->label('Obyektdan masofa (m)')->placeholder('—'),
                Infolists\Components\TextEntry::make('device')->label('Qurilma')->formatStateUsing(fn ($state) => \App\Support\Device::label($state))->columnSpanFull(),
                Infolists\Components\TextEntry::make('reason')->label(fn (Inspection $record) => $record->in_progress ? 'Buzish jarayoni haqida izoh' : 'Buzilmaganlik sababi')->columnSpanFull()->visible(fn (Inspection $record) => ! $record->is_demolished),
                Infolists\Components\TextEntry::make('flags')->label('Ogohlantirishlar')->badge()->color('danger')
                    ->formatStateUsing(fn ($state) => \App\Support\Gps::flagLabel($state))->visible(fn (Inspection $record) => $record->suspicious),
            ]),
            Infolists\Components\Section::make('Rasm va videolar')->schema([
                Infolists\Components\ViewEntry::make('media')->hiddenLabel()->view('filament.infolists.inspection-media'),
            ]),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInspections::route('/'),
            'view' => Pages\ViewInspection::route('/{record}'),
        ];
    }
}
