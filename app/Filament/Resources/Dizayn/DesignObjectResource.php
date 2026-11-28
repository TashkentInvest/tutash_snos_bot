<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\DesignObjectResource\Pages;
use App\Models\Dizayn\DesignObject;
use App\Models\Dizayn\District;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\Dizayn\Street;
use App\Models\User;
use App\Support\Latin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dizayn-kod: ko'chalardagi tadbirkorlik obyektlari (tuman xodimlari Mini App'da kiritadi) va ularning holati.
 * Holat odatda dalil tasdiqlanganda o'zgaradi («Tekshiruvlar»); bu yerda — ko'rish va xatoni tuzatish.
 */
class DesignObjectResource extends Resource
{
    protected static ?string $model = DesignObject::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Obyektlar';

    protected static ?string $modelLabel = 'obyekt';

    protected static ?string $pluralModelLabel = 'Obyektlar (tadbirkorlar)';

    protected static ?string $slug = 'dizayn/obyektlar';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false; // obyektni tuman xodimi joyida kiritadi
    }

    public static function canDelete($record): bool
    {
        return false; // o'chirilmaydi — «Hisobga olinsin» belgisi olinadi
    }

    private static function district(?string $name): string
    {
        return (string) preg_replace('/\s+tumani$/u', '', Latin::of($name));
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('shop_name')->label('Do‘kon / firma nomi')->required()->maxLength(150),
            Forms\Components\TextInput::make('address')->label('Manzil')->maxLength(200),
            Forms\Components\TextInput::make('owner_name')->label('Tadbirkor F.I.Sh.')->maxLength(150),
            Forms\Components\TextInput::make('owner_phone')->label('Tadbirkor telefoni')->tel()->maxLength(30),
            Forms\Components\TextInput::make('stir')->label('STIR')->regex('/^\d{9}$|^\d{14}$/')->validationMessages(['regex' => 'STIR: 9 ta raqam (JShShIR: 14).']),
            Forms\Components\Select::make('status')->label('Holat')->options(DesignObject::STATUSES)->required()->native(false)
                ->helperText('Odatda holat xodim dalili tasdiqlanganda o‘zgaradi. Bu yerda faqat xatoni tuzatish uchun.'),
            Forms\Components\Toggle::make('is_active')->label('Hisobga olinsin')
                ->helperText('Xato yoki takror kiritilgan bo‘lsa, belgini o‘chiring: svod va xodim ro‘yxatidan chiqadi.'),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->columns(['default' => 1, 'lg' => 3])->schema([
            Infolists\Components\Group::make()->columnSpan(['default' => 1, 'lg' => 2])->schema([
                Infolists\Components\Section::make('Obyekt')->icon('heroicon-o-building-storefront')->columns(['default' => 2, 'md' => 4])->schema([
                    Infolists\Components\TextEntry::make('street.district.name')->label('Tuman')->formatStateUsing(fn ($state) => Latin::of($state)),
                    Infolists\Components\TextEntry::make('street.name')->label('Ko‘cha')->formatStateUsing(fn ($state) => Latin::of($state)),
                    Infolists\Components\TextEntry::make('creator.full_name')->label('Kiritgan xodim')->formatStateUsing(fn ($state) => Latin::of($state))->placeholder('admin'),
                    Infolists\Components\TextEntry::make('status')->label('Holat')->badge()
                        ->formatStateUsing(fn ($state) => DesignObject::STATUSES[$state] ?? $state)->color(fn ($state) => DesignObject::STATUS_COLORS[$state] ?? 'gray'),
                    Infolists\Components\TextEntry::make('shop_name')->label('Do‘kon / firma')->placeholder('—'),
                    Infolists\Components\TextEntry::make('address')->label('Manzil')->placeholder('—'),
                    Infolists\Components\TextEntry::make('owner_name')->label('Tadbirkor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('owner_phone')->label('Telefon')->formatStateUsing(fn ($state) => \App\Support\Phone::format($state) ?? $state)->placeholder('—'),
                    Infolists\Components\TextEntry::make('stir')->label('STIR')->placeholder('—')->copyable(),
                    Infolists\Components\TextEntry::make('point')->label('Joylashuv')->placeholder('Hali tasdiqlangan dalil yo‘q')
                        ->state(fn (DesignObject $o) => $o->latitude ? round($o->latitude, 6).', '.round($o->longitude, 6) : null)
                        ->url(fn (DesignObject $o) => $o->latitude ? "https://maps.google.com/?q={$o->latitude},{$o->longitude}" : null, true)->color('primary'),
                    Infolists\Components\TextEntry::make('created_at')->label('Kiritilgan')->dateTime('d.m.Y H:i'),
                    Infolists\Components\IconEntry::make('is_active')->label('Hisobga olinadi')->boolean(),
                ]),
                Infolists\Components\Section::make('Xodim dalillari')->icon('heroicon-o-camera')->schema([
                    Infolists\Components\ViewEntry::make('inspections')->hiddenLabel()->view('filament.dizayn.object-inspections'),
                ]),
            ]),
            Infolists\Components\Group::make()->columnSpan(1)->extraAttributes(['style' => 'position:sticky;top:5rem;align-self:start'])->schema([
                Infolists\Components\Section::make('Ko‘chaning konsepsiyasi (PDF)')->icon('heroicon-o-document-text')->schema([
                    Infolists\Components\ViewEntry::make('street_files')->hiddenLabel()->view('filament.dizayn.street-files'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['street.district', 'pendingInspection']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№')->sortable(),
                Tables\Columns\TextColumn::make('street.district.name')->label('Tuman')->formatStateUsing(fn ($state) => self::district($state)),
                Tables\Columns\TextColumn::make('street.name')->label('Ko‘cha')->formatStateUsing(fn ($state) => Latin::of($state))->wrap(),
                Tables\Columns\TextColumn::make('shop_name')->label('Do‘kon / firma')->placeholder('—')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->whereRaw('LOWER(shop_name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhere('stir', 'like', "%$search%")
                        ->orWhereRaw('LOWER(owner_name) LIKE ?', ['%'.mb_strtolower($search).'%'])))
                    ->description(fn (DesignObject $o) => trim(implode(' · ', array_filter([$o->address, $o->owner_name, $o->stir ? 'STIR '.$o->stir : null]))) ?: null),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => DesignObject::STATUSES[$state] ?? $state)->color(fn ($state) => DesignObject::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (DesignObject $o) => $o->pendingInspection ? '⏳ dalil tekshiruvda' : null),
                Tables\Columns\TextColumn::make('updated_at')->label('O‘zgargan')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => District::orderBy('sort_order')->pluck('name', 'id')->map(fn ($n) => Latin::of($n)))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->inDistrict((int) $data['value']) : $query),
                Tables\Filters\SelectFilter::make('street_id')->label('Ko‘cha')->searchable()
                    ->options(fn () => Street::orderBy('name')->pluck('name', 'id')->map(fn ($n) => Latin::of($n))),
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(DesignObject::STATUSES),
                Tables\Filters\Filter::make('in_review')->label('Dalili tekshiruvda')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('inspections', fn ($i) => $i->whereIn('review', \App\Models\Dizayn\Inspection::OPEN))),
                Tables\Filters\TernaryFilter::make('is_active')->label('Hisobga olinadi')->default(true),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Ko‘rish'), Tables\Actions\EditAction::make()->label('Tuzatish')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDesignObjects::route('/'),
            'view' => Pages\ViewDesignObject::route('/{record}'),
            'edit' => Pages\EditDesignObject::route('/{record}/edit'),
        ];
    }
}
