<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DistrictResource\Pages;
use App\Models\District;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DistrictResource extends Resource
{
    protected static ?string $model = District::class;

    // Kuzatuvchi (is_viewer) bu bo'limni ko'rmaydi — faqat admin.
    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $modelLabel = 'tuman';

    protected static ?string $pluralModelLabel = 'Tumanlar';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Tuman nomi')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('responsible_name')->label('Mas’ul xodim F.I.SH')->maxLength(100),
            Forms\Components\TextInput::make('phone')->label('Mas\'ul xodim telefoni (9 raqam)')
                ->helperText('Masalan: 992012272. Xodim shu raqam bilan botga kirganda tumanga biriktiriladi.')
                ->regex('/^\d{9}$/')->length(9),
            // botda bor xodimlar — qidirib tanlanadi (yozish shart emas); tanlansa shu tumanga darhol biriktiriladi
            Forms\Components\Select::make('staff_ids')->label('Qo‘shimcha xodimlar — qidirib tanlang')
                ->helperText('Botda ro‘yxatdan o‘tgan xodimlar: ism yoki telefon bo‘yicha qidiring, bir nechtasini tanlang. Saqlangach shu tumanga darhol biriktiriladi.')
                ->multiple()->searchable()->preload()
                ->options(fn () => self::linkableStaff()->get()->mapWithKeys(fn (Staff $s) => [(string) $s->id => $s->full_name.' · '
                    .(StaffResource::ROLES[$s->role] ?? $s->role).' · '.$s->phone])->all())
                ->afterStateHydrated(fn ($component, ?District $record) => $component->state($record
                    ? self::linkableStaff()->whereIn('phone', $record->phones()->pluck('phone'))->pluck('id')->map(fn ($id) => (string) $id)->all() : []))
                ->dehydrated(false)
                ->saveRelationshipsUsing(function (District $record, $state) {
                    $linkable = self::linkableStaff()->get(['id', 'phone', 'full_name']);
                    $chosen = $linkable->whereIn('id', array_map('intval', $state ?? []));
                    // olib tashlanganlar (o'chirish hodisasi xodim tumanlarini darhol yangilaydi)
                    $record->phones()->whereIn('phone', $linkable->pluck('phone'))->whereNotIn('phone', $chosen->pluck('phone'))->get()->each->delete();
                    $chosen->each(fn (Staff $s) => $record->phones()->firstOrCreate(['phone' => $s->phone], ['name' => $s->full_name]));
                })
                ->columnSpanFull(),
            // botga hali kirmagan odam — raqami qo'lda: egasi botda /start bosib, telefonini yuborganda tumanga biriktiriladi
            Forms\Components\Repeater::make('phones')
                ->relationship('phones', modifyQueryUsing: fn ($query) => $query->whereNotIn('phone', self::linkableStaff()->pluck('phone')))
                ->label('Botga hali kirmaganlar (telefon raqami bilan)')
                ->helperText('Ro‘yxatda topilmagan odam: raqam egasi botda /start bosib, telefonini yuborganda shu tumanga biriktiriladi.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('F.I.SH')->maxLength(100),
                    Forms\Components\TextInput::make('phone')->label('Telefon (9 raqam)')->required()
                        ->regex('/^\d{9}$/')->length(9)->placeholder('901234567')
                        ->distinct(),
                ])
                ->columns(2)->defaultItems(0)->addActionLabel('Xodim telefoni qo‘shish')->reorderable(false)
                ->columnSpanFull(),
        ]);
    }

    /** Tumanga biriktirsa bo'ladigan, botda bor faol xodimlar (xodim, shartnoma, kadastr). */
    public static function linkableStaff()
    {
        return Staff::where('status', Staff::ACTIVE)->whereNotNull('telegram_id')->whereNotNull('phone')
            ->whereIn('role', Staff::DISTRICT_ROLES)->orderBy('full_name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Tuman')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('responsible_name')->label('Mas’ul xodim')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->label('Telefon'),
                Tables\Columns\TextColumn::make('responsibleStaff.full_name')->label('Botda')->badge()
                    ->state(fn (District $record) => $record->responsibleStaff
                        ? $record->responsibleStaff->full_name
                        : ($record->phone ? 'Ro‘yxatdan o‘tmagan' : 'Telefon kiritilmagan'))
                    ->icon(fn (District $record) => $record->responsibleStaff ? 'fas-circle-check' : 'fas-circle-xmark')
                    ->color(fn (District $record) => $record->responsibleStaff ? 'success' : 'danger')
                    ->tooltip(fn (District $record) => $record->responsibleStaff?->registered_at
                        ? 'Ro‘yxatdan o‘tgan: '.$record->responsibleStaff->registered_at->format('d.m.Y H:i') : null),
                Tables\Columns\TextColumn::make('phones')->label('Qo‘shimcha xodimlar')->badge()->placeholder('—')
                    // ro'yxatdan o'tmaganlar "(kutilmoqda)" belgisi bilan — rang va ikonka shunga qarab
                    ->state(fn (District $record) => $record->phones->map(fn ($p) => $p->staff ? $p->staff->full_name : ($p->name ?: $p->phone).' (kutilmoqda)')->all())
                    ->icon(fn (string $state) => str_ends_with($state, '(kutilmoqda)') ? 'fas-clock' : 'fas-circle-check')
                    ->color(fn (string $state) => str_ends_with($state, '(kutilmoqda)') ? 'warning' : 'success')
                    ->tooltip('«kutilmoqda» — shu raqam bilan hali botda ro‘yxatdan o‘tilmagan'),
                Tables\Columns\TextColumn::make('sites_count')->label('Obyektlar')->counts('sites'),
                Tables\Columns\TextColumn::make('staff_count')->label('Xodimlar')->counts('staff'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['responsibleStaff', 'phones.staff']))
            ->filters([
                Tables\Filters\TernaryFilter::make('registered')->label('Mas’ul botda')
                    ->trueLabel('Ro‘yxatdan o‘tgan')->falseLabel('Ro‘yxatdan o‘tmagan')
                    ->queries(
                        true: fn ($query) => $query->whereHas('responsibleStaff'),
                        false: fn ($query) => $query->unregistered(),
                    ),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    /** Menyuda: mas'uli hali botda ro'yxatdan o'tmagan tumanlar soni. */
    public static function getNavigationBadge(): ?string
    {
        return ($n = District::unregistered()->count()) ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Mas’uli botda ro‘yxatdan o‘tmagan tumanlar';
    }

    /** "Ro'yxatdan o'tmagan" filtri yoqilgan ro'yxat (yuqori paneldagi ogohlantirishdan). */
    public static function unregisteredUrl(): string
    {
        return self::getUrl('index', ['tableFilters' => ['registered' => ['value' => 0]]]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDistricts::route('/')];
    }
}
