<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\DownloadResource\Pages;
use App\Models\Dizayn\Download;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\User;
use App\Support\Latin;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Dizayn-kod botidan yuklab olingan konsepsiya fayllari jurnali: kim, qachon, qaysi tuman va ko'cha. */
class DownloadResource extends Resource
{
    protected static ?string $model = Download::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Yuklab olishlar';

    protected static ?string $modelLabel = 'yuklab olish';

    protected static ?string $pluralModelLabel = 'Yuklab olishlar';

    protected static ?string $slug = 'dizayn/yuklab-olishlar';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('Kim')->state(fn (Download $r) => $r->user?->displayName() ?? (string) $r->user_id)
                    ->description(fn (Download $r) => \App\Support\Phone::format($r->user?->phone))
                    ->url(fn (Download $r) => $r->user ? BotUserResource::getUrl('view', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('district')->label('Tuman')->formatStateUsing(fn ($state) => Latin::of($state)),
                Tables\Columns\TextColumn::make('street')->label('Ko‘cha')->formatStateUsing(fn ($state) => Latin::of($state))->wrap(),
                Tables\Columns\TextColumn::make('file_name')->label('Fayl')->limit(40)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => Download::query()->distinct()->orderBy('district')->pluck('district')->mapWithKeys(fn ($d) => [$d => Latin::of($d)])->all()),
                Tables\Filters\Filter::make('date')->form([
                    Forms\Components\DatePicker::make('from')->label('Sanadan')->native(false)->displayFormat('d.m.Y'),
                    Forms\Components\DatePicker::make('to')->label('Sanagacha')->native(false)->displayFormat('d.m.Y'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('sent_at', '>=', \Carbon\Carbon::parse($d, 'Asia/Tashkent')->startOfDay()->utc()))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->where('sent_at', '<=', \Carbon\Carbon::parse($d, 'Asia/Tashkent')->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Sanadan: '.\Carbon\Carbon::parse($data['from'])->format('d.m.Y') : null,
                        ($data['to'] ?? null) ? 'Sanagacha: '.\Carbon\Carbon::parse($data['to'])->format('d.m.Y') : null,
                    ])),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDownloads::route('/')];
    }
}
