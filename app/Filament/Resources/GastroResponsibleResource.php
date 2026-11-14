<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GastroResponsibleResource\Pages;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\Staff;
use App\Models\User;
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
                Tables\Columns\SelectColumn::make('staff_id')->label('Mas’ul xodim (shartnomalar)')
                    ->options(fn () => Staff::where('status', Staff::ACTIVE)->whereNotNull('telegram_id')->whereIn('role', Staff::DISTRICT_ROLES)
                        ->orderBy('full_name')->get()->mapWithKeys(fn (Staff $s) => [$s->id => $s->full_name.' · '.$s->phone])->all())
                    ->placeholder('— tanlanmagan —'),
                Tables\Columns\TextColumn::make('phone')->label('Jadvaldagi raqam')->placeholder('—')
                    ->description(fn (GastroResponsible $r) => ! $r->staff_id && $r->phone ? 'botga hali kirmagan' : null)->toggleable(),
                Tables\Columns\TextColumn::make('streets')->label('Ko‘chalar')
                    ->state(fn (GastroResponsible $r) => (int) ($counts[$r->district_id] ?? 0))
                    ->url(fn () => \App\Filament\Pages\GastroMap::getUrl()),
            ])
            ->defaultSort('district_id')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGastroResponsibles::route('/')];
    }
}
