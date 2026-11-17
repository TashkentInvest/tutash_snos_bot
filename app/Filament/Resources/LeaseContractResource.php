<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaseContractResource\Pages;
use App\Models\LeaseContract;
use App\Models\User;
use App\Support\LeaseContractDocument;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Gastronomik ko'chalar → «Ijara shartnomalari»: botdan tayyorlangan shartnomalar (hisob uchun), Word/PDF yuklab olish. */
class LeaseContractResource extends Resource
{
    protected static ?string $model = LeaseContract::class;

    protected static ?string $navigationGroup = 'Gastronomik ko‘chalar';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Ijara shartnomalari';

    protected static ?string $modelLabel = 'ijara shartnomasi';

    protected static ?string $pluralModelLabel = 'Ijara shartnomalari';

    protected static ?string $slug = 'lease-contracts';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    public static function canCreate(): bool
    {
        return false; // botda (Mini App) shartnoma mas'uli tayyorlaydi
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['district', 'street', 'staff']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Tayyorlangan')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('street.name')->label('Ko‘cha')
                    ->description(fn (LeaseContract $r) => collect([$r->mfy, filled($r->house) ? $r->house.'-uy' : null])->filter()->join(', ')),
                Tables\Columns\TextColumn::make('tenant_name')->label('Ijarachi')->searchable()->wrap()
                    ->description(fn (LeaseContract $r) => LeaseContract::PARTIES[$r->party] ?? $r->party),
                Tables\Columns\TextColumn::make('area_m2')->label('Maydon')->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2, ',', ' '), '0'), ',').' m²'),
                Tables\Columns\TextColumn::make('annual_amount')->label('Joriy yil to‘lovi')->formatStateUsing(fn ($state) => LeaseContractDocument::money((float) $state).' so‘m'),
                Tables\Columns\TextColumn::make('start_date')->label('Muddat')
                    ->formatStateUsing(fn (LeaseContract $record) => $record->start_date->format('d.m.Y').' – '.$record->end_date->format('d.m.Y')),
                Tables\Columns\TextColumn::make('activity')->label('Faoliyat')->toggleable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Tayyorlagan')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district_id')->label('Tuman')->relationship('district', 'name'),
                Tables\Filters\SelectFilter::make('party')->label('Turi')->options(LeaseContract::PARTIES),
                // faqat shartnoma tuzganlar; ismi bo'sh xodim (botda ro'yxatdan to'liq o'tmagan) — telefoni bilan (null nom Filament'ni 500 ga tushirardi)
                Tables\Filters\SelectFilter::make('staff_id')->label('Tayyorlagan')
                    ->options(fn () => \App\Models\Staff::withTrashed()->whereIn('id', LeaseContract::query()->select('staff_id')->distinct())
                        ->orderBy('full_name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->full_name ?: ($s->phone ?: '№'.$s->id)])->all()),
                Tables\Filters\TrashedFilter::make()->label('O‘chirilganlar')
                    ->placeholder('Faqat amaldagilar')->trueLabel('Hammasi (o‘chirilganlar bilan)')->falseLabel('Faqat o‘chirilganlar'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ko‘rish'),
                Tables\Actions\Action::make('docx')->label('Word')->icon('heroicon-o-arrow-down-tray')
                    ->hidden(fn (LeaseContract $r) => $r->trashed())
                    ->url(fn (LeaseContract $r) => route('admin.lease-file', ['contract' => $r, 'format' => 'docx'])),
                Tables\Actions\Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->visible(fn (LeaseContract $r) => (bool) $r->pdf_path)
                    ->url(fn (LeaseContract $r) => route('admin.lease-file', ['contract' => $r, 'format' => 'pdf'])),
                // namuna o'zgarsa yoki fayl yo'qolsa — shu ma'lumotdan qayta yasash
                Tables\Actions\Action::make('rebuild')->label('Qayta yasash')->icon('heroicon-o-arrow-path')->color('gray')
                    ->requiresConfirmation()->modalDescription('Fayl shu ma’lumotlardan joriy namuna bo‘yicha qayta yasaladi.')
                    ->action(function (LeaseContract $r) {
                        [$docx, $pdf] = LeaseContractDocument::render($r);
                        $r->update(['docx_path' => $docx, 'pdf_path' => $pdf]);
                        \Filament\Notifications\Notification::make()->title('Qayta yasaldi')->success()->send();
                    })->hidden(fn (LeaseContract $r) => $r->trashed()),
                // keraksiz (sinov, takror) — «o'chirilganlar»ga: svod va hisobotlarga kirmaydi, har doim tiklash mumkin
                // (butunlay o'chirish yo'q — hech bir shartnoma va uning fayllari yo'qolmaydi)
                Tables\Actions\DeleteAction::make()->label('O‘chirish')
                    ->modalHeading(fn (LeaseContract $r) => '№'.$r->id.' shartnomani o‘chirish')
                    ->modalDescription('Shartnoma ro‘yxat, svod va hisobotlardan olib tashlanadi. «O‘chirilganlar» filtridan tiklash mumkin.')
                    ->after(fn (LeaseContract $r) => \App\Models\AuditLog::record('lease.deleted', null, ['contract' => $r->id, 'by' => auth()->id()])),
                Tables\Actions\RestoreAction::make()->label('Tiklash')
                    ->after(fn (LeaseContract $r) => \App\Models\AuditLog::record('lease.restored', null, ['contract' => $r->id, 'by' => auth()->id()])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Tanlanganlarni o‘chirish')
                        ->modalDescription('Tanlangan shartnomalar ro‘yxat, svod va hisobotlardan olib tashlanadi. Tiklash mumkin.')
                        ->after(fn ($records) => \App\Models\AuditLog::record('lease.deleted', null, ['contracts' => $records->pluck('id')->all(), 'by' => auth()->id()])),
                    Tables\Actions\RestoreBulkAction::make()->label('Tanlanganlarni tiklash'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    /** «O'chirilganlar» filtri ishlashi uchun (standart — faqat amaldagilar). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeaseContracts::route('/'), 'view' => Pages\ViewLeaseContract::route('/{record}')];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $e = fn (string $k, string $label) => Infolists\Components\TextEntry::make($k)->label($label)->placeholder('—');

        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                $e('tenant_name', 'Ijarachi')->columnSpan(2),
                $e('party', 'Turi')->formatStateUsing(fn ($state) => LeaseContract::PARTIES[$state] ?? $state),
                $e('staff.full_name', 'Tayyorlagan'),
                $e('district.name', 'Tuman'),
                $e('street.name', 'Gastronomik ko‘cha'),
                $e('mfy', 'MFY')->formatStateUsing(fn ($state, LeaseContract $record) => $state.(filled($record->house) ? ', '.$record->house.'-uy' : '')),
                $e('cadastre', 'Kadastr raqami'),
                $e('area_m2', 'Maydon (shartnomada)')->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2, ',', ' '), '0'), ',').' m²'),
                $e('polygon_area_m2', 'Maydon (chizmadan)')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').' m²'),
                $e('activity', 'Faoliyat'),
                $e('annual_amount', 'Joriy yil to‘lovi')->formatStateUsing(fn ($state) => LeaseContractDocument::money((float) $state).' so‘m'),
                $e('tariff', 'Tarif (1 m² / oy)')->formatStateUsing(fn ($state, LeaseContract $record) => LeaseContractDocument::money((float) $state).' so‘m · oyiga '
                    .LeaseContractDocument::money(round($record->area_m2 * (float) $state, 2)).' so‘m'),
                $e('start_date', 'Muddat')->formatStateUsing(fn (LeaseContract $record) => $record->start_date->format('d.m.Y').' – '.$record->end_date->format('d.m.Y')),
                $e('street_distance_m', 'Xodim ko‘chadan')->formatStateUsing(fn ($state, LeaseContract $record) => $state.' m · GPS ±'.round((float) $record->gps_accuracy).' m'),
                $e('created_at', 'Tayyorlangan')->dateTime('d.m.Y H:i'),
            ]),
            Infolists\Components\Section::make('Tutash hudud va joyida olingan rasmlar')->schema([
                Infolists\Components\ViewEntry::make('map')->hiddenLabel()->view('filament.lease-contract-map'),
            ]),
        ]);
    }
}
