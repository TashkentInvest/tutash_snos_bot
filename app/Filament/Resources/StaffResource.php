<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    // Kuzatuvchi (is_viewer) bu bo'limni ko'rmaydi — faqat admin.
    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $modelLabel = 'xodim';

    protected static ?string $pluralModelLabel = 'Xodimlar';

    private const STATUSES = [
        Staff::ACTIVE => 'Faol',
        Staff::REGISTERING => 'Ro\'yxatdan o\'tmoqda',
        Staff::AWAITING_PHONE => 'Telefon kutilmoqda',
        Staff::REJECTED => 'Rad etilgan',
        Staff::BLOCKED => 'Bloklangan',
        Staff::CITIZEN => 'Aholi',
    ];

    public const ROLES = [
        Staff::ROLE_EMPLOYEE => 'Xodim',
        Staff::ROLE_CONTRACT => 'Shartnoma',
        Staff::ROLE_CITIZEN => 'Aholi',
    ];

    /** Xodim — obyektlar bilan ishlaydi; Shartnoma — faqat davomat va har soatlik qayd. */
    public const ROLE_HINT = 'Xodim — obyektlar bilan ishlaydi (tekshiruv). Shartnoma — faqat ertalabki davomat va ish kuni davomida har soatda joylashuv va video (10:00–12:00, 14:00–18:00), obyektlar bilan ishlamaydi.';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('full_name')->label('F.I.SH')->required(),
            Forms\Components\TextInput::make('phone')->label('Telefon (9 raqam)')->disabled(),
            Forms\Components\Select::make('status')->label('Holat')->options(self::STATUSES)->required(),
            Forms\Components\Select::make('role')->label('Rol')->options(self::ROLES)->helperText(self::ROLE_HINT),
            Forms\Components\TextInput::make('failed_attempts')->label('Noto‘g‘ri urinishlar (0 — qayta ochish)')->numeric()->minValue(0),
            Forms\Components\Toggle::make('attendance_required')->label('Kunlik davomat yuritiladi'),
            Forms\Components\Toggle::make('is_global')->label('Barcha tumanlarni ko‘radi (admin)'),
            Forms\Components\Toggle::make('receives_reports')->label('Hisobot oladi')
                ->helperText('Kunlik buzilish (svod) va davomat hisobotlari botda yuboriladi. Faqat shu belgi qo‘yilganlarga boradi (admin bo‘lsa ham).'),
            // telefon orqali biriktiriladi (Staff::assignDistricts): tanlov tumanlarning «Qo'shimcha xodimlar» ro'yxatiga yoziladi
            Forms\Components\Select::make('district_ids')->label('Biriktirilgan tumanlar')->multiple()
                ->options(fn () => \App\Models\District::orderBy('name')->pluck('name', 'id'))
                ->afterStateHydrated(fn ($component, ?Staff $record) => $component->state($record?->districts()->pluck('districts.id')->all() ?? []))
                ->disabled(fn (?Staff $record) => ! $record?->phone || $record->is_global)
                ->helperText(fn (?Staff $record) => match (true) {
                    ! $record?->phone => 'Xodim telefoni yo‘q — avval botda ro‘yxatdan o‘tishi kerak.',
                    (bool) $record->is_global => 'Admin barcha tumanlarni ko‘radi.',
                    default => 'Xodim telefoni tanlangan tumanlarning «Qo‘shimcha xodimlar» ro‘yxatiga qo‘shiladi (Tumanlar sahifasida ham ko‘rinadi). '
                        .'Mas’ul xodim sifatida biriktirilgan tuman faqat Tumanlar sahifasida o‘zgartiriladi.',
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('F.I.SH')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rol')->badge()->formatStateUsing(fn ($state) => self::ROLES[$state] ?? '—')
                    ->color(fn ($state) => match ($state) { Staff::ROLE_CONTRACT => 'warning', Staff::ROLE_CITIZEN => 'gray', default => 'primary' }),
                // barcha tumanlarni ko'radigan adminda 12 ta nishon o'rniga bitta yozuv; boshqalarda 3 tadan keyin "yana N ta"
                Tables\Columns\TextColumn::make('districts.name')->label('Tumanlar')->badge()
                    ->state(fn (Staff $record) => $record->is_global ? ['Barcha tumanlar'] : $record->districts->pluck('name')->all())
                    ->color(fn (string $state) => $state === 'Barcha tumanlar' ? 'success' : 'primary')
                    ->limitList(3)->expandableLimitedList(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Staff::ACTIVE => 'success',
                        Staff::BLOCKED, Staff::REJECTED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('receives_reports')->label('Hisobot')->boolean()
                    ->tooltip('Bosing: hisobot yuborishni yoqish / o‘chirish')
                    ->action(fn (Staff $record) => $record->update(['receives_reports' => ! $record->receives_reports])),
                Tables\Columns\TextColumn::make('inspections_count')->label('Tekshiruvlar')->counts('inspections'),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Oxirgi faollik')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('role')->label('Rol')->options(self::ROLES),
                Tables\Filters\Filter::make('reports')->label('Hisobot oladi')
                    ->query(fn ($query) => $query->where('receives_reports', true)),
                // o'chirilganlar (arxiv): natijalari, davomati va videolari saqlangan
                Tables\Filters\TrashedFilter::make()->label('O‘chirilganlar')
                    ->placeholder('O‘chirilmaganlar')->trueLabel('Hammasi')->falseLabel('Faqat o‘chirilganlar'),
            ])
            ->actions([
                Tables\Actions\RestoreAction::make()->label('Tiklash')
                    ->modalDescription('Xodim ro‘yxatga qaytariladi (holati va tumanlari o‘zgarmaydi).'),
                Tables\Actions\EditAction::make()->hidden(fn (Staff $record) => $record->trashed())->using(function (Staff $record, array $data) {
                    $ids = $data['district_ids'] ?? null;
                    unset($data['district_ids']);
                    $record->update($data);
                    $ids !== null && ! $record->is_global && $record->assignDistricts($ids);

                    return $record;
                }),
                Tables\Actions\Action::make('block')->label('Bloklash')->color('danger')->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()->visible(fn (Staff $record) => $record->status !== Staff::BLOCKED && ! $record->trashed())
                    ->action(fn (Staff $record) => $record->update(['status' => Staff::BLOCKED])),
                Tables\Actions\DeleteAction::make()->label('O‘chirish')
                    ->modalDescription('Xodim ro‘yxatdan olib tashlanadi va botdan foydalana olmaydi. Natijalari, davomati va videolari arxivda saqlanadi — «O‘chirilganlar» filtri orqali topiladi va tiklanadi. Botga qayta kirsa, yangidan ro‘yxatdan o‘tadi.')
                    ->successNotificationTitle('Xodim o‘chirildi (arxivga o‘tkazildi)')
                    ->using(fn (Staff $record) => self::deleteStaff($record)),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    /** O'chirish — arxivga o'tkazish (soft delete): faqat tugallanmagan qoralamalar o'chadi, tarix saqlanadi. */
    public static function deleteStaff(Staff $staff): bool
    {
        DB::transaction(function () use ($staff) {
            $staff->inspections()->where('status', \App\Models\Inspection::DRAFT)->get()->each->delete(); // qoralamalar fayllari bilan
            $staff->delete();
        });

        return true;
    }

    /** O'chirilganlar (arxiv) ham filtr orqali ko'rinishi uchun. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class]);
    }

    public static function canCreate(): bool
    {
        return false; // xodimlar botda o'zi ro'yxatdan o'tadi
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageStaff::route('/')];
    }
}
