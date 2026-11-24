<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GastroResponsibleResource\Pages;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\Staff;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Gastronomik ko'chalar → «Mas'ullar»: har tuman ijara shartnomalari kimga tegishli («Shartnoma tushum» jadvali).
 * Xodim formasidagi «Tumanlar»dan alohida — davomat va obyektlarga ta'sir qilmaydi.
 */
class GastroResponsibleResource extends Resource
{
    protected static ?string $model = GastroResponsible::class;

    protected static ?string $navigationGroup = 'Gastronomik ko‘chalar';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Mas’ullar';

    protected static ?string $modelLabel = 'mas’ul';

    protected static ?string $pluralModelLabel = 'Shartnoma mas’ullari';

    protected static ?string $slug = 'gastro-responsibles';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    public static function canCreate(): bool
    {
        return false; // har tuman uchun bitta qator — avtomatik
    }

    public static function table(Table $table): Table
    {
        $counts = GastroStreet::selectRaw('district_id, count(*) as n')->groupBy('district_id')->pluck('n', 'district_id');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['district', 'staff']))
            ->columns([
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('staff_list')->label('Mas’ul xodimlar (shartnomalar)')->badge()
                    ->state(fn (GastroResponsible $r) => $r->staff->map(fn (Staff $s) => $s->full_name.' · '.\App\Support\Phone::format($s->phone))->all())
                    ->placeholder('Tanlanmagan'),
                // 1 m² uchun oylik ijara tarifi: shartnoma to'lovi = maydon × tarif × oylar (yangi shartnomalarga ta'sir qiladi)
                Tables\Columns\TextColumn::make('tariff')->label('Tarif, 1 m² / oy (so‘m)')->placeholder('kiritilmagan')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', ' '))->alignEnd(),
                Tables\Columns\TextColumn::make('phone')->label('Jadvaldagi raqam')->formatStateUsing(fn ($state) => \App\Support\Phone::format($state))->placeholder('—')
                    ->description(fn (GastroResponsible $r) => $r->staff->isEmpty() && $r->phone ? 'botga hali kirmagan' : null)->toggleable(),
                Tables\Columns\TextColumn::make('streets')->label('Ko‘chalar')
                    ->state(fn (GastroResponsible $r) => (int) ($counts[$r->district_id] ?? 0))
                    ->url(fn () => \App\Filament\Pages\GastroMap::getUrl()),
            ])
            ->actions([
                // xodimlarni tumanga biriktirgan kabi: qidirib, bir nechtasini tanlash
                Tables\Actions\EditAction::make()->label('O‘zgartirish')->modalHeading(fn (GastroResponsible $r) => $r->district?->name.' — shartnoma mas’ullari')
                    ->form([
                        Forms\Components\Select::make('staff')->label('Mas’ul xodimlar — qidirib tanlang')->multiple()->searchable()->preload()
                            ->relationship('staff', 'full_name', fn ($query) => $query->where('status', Staff::ACTIVE)->whereNotNull('telegram_id')
                                ->whereIn('role', Staff::DISTRICT_ROLES)->orderBy('full_name'))
                            ->getOptionLabelFromRecordUsing(fn (Staff $s) => $s->full_name.' · '.AppSupportPhone::format($s->phone))
                            ->helperText('Botda ro‘yxatdan o‘tgan xodimlar: ism yoki telefon bo‘yicha qidiring. Har biri shu tuman ko‘chalarida shartnoma tuzadi. Xodimning «Tumanlar»iga (davomat, obyektlar) ta’sir qilmaydi.'),
                        Forms\Components\TextInput::make('tariff')->label('Tarif, 1 m² / oy (so‘m)')->numeric()->minValue(0)->maxValue(100000000)->step('0.01')
                            ->helperText('Faqat yangi shartnomalarga ta’sir qiladi; tuzilgan shartnomalar summasi o‘zgarmaydi.'),
                    ])
                    ->before(function (GastroResponsible $record, array $data) {
                        $old = $record->tariff;
                        if (array_key_exists('tariff', $data) && (float) $old !== (float) $data['tariff']) {
                            \App\Models\AuditLog::record('gastro.tariff.updated', null, ['district' => $record->district?->name, 'old' => $old, 'new' => $data['tariff'], 'by' => auth()->id()]);
                        }
                    }),
            ])
            ->defaultSort('district_id')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGastroResponsibles::route('/')];
    }
}
