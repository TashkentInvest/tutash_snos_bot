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

    private const ROLES = [
        Staff::ROLE_EMPLOYEE => 'Xodim',
        Staff::ROLE_CITIZEN => 'Aholi',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('full_name')->label('F.I.SH')->required(),
            Forms\Components\TextInput::make('phone')->label('Telefon (9 raqam)')->disabled(),
            Forms\Components\Select::make('status')->label('Holat')->options(self::STATUSES)->required(),
            Forms\Components\Select::make('role')->label('Rol')->options(self::ROLES),
            Forms\Components\TextInput::make('failed_attempts')->label('Noto‘g‘ri urinishlar (0 — qayta ochish)')->numeric()->minValue(0),
            Forms\Components\Toggle::make('attendance_required')->label('Kunlik davomat yuritiladi'),
            Forms\Components\Toggle::make('is_global')->label('Barcha tumanlarni ko‘radi (admin)'),
            Forms\Components\Toggle::make('receives_reports')->label('Hisobot oladi')
                ->helperText('Kunlik buzilish (svod) va davomat hisobotlari botda yuboriladi. Faqat shu belgi qo‘yilganlarga boradi (admin bo‘lsa ham).'),
            Forms\Components\Select::make('districts')->label('Biriktirilgan tumanlar')
                ->relationship('districts', 'name')->multiple()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('F.I.SH')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rol')->badge()->formatStateUsing(fn ($state) => self::ROLES[$state] ?? '—'),
                Tables\Columns\TextColumn::make('districts.name')->label('Tumanlar')->badge(),
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
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('block')->label('Bloklash')->color('danger')->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()->visible(fn (Staff $record) => $record->status !== Staff::BLOCKED)
                    ->action(fn (Staff $record) => $record->update(['status' => Staff::BLOCKED])),
                Tables\Actions\DeleteAction::make()->label('O‘chirish')
                    ->modalDescription('Xodim, uning davomat yozuvlari va tugallanmagan qoralamalari o‘chiriladi. Qayta ro‘yxatdan o‘tishi mumkin.')
                    ->using(fn (Staff $record) => self::deleteStaff($record)),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    /** Yuborilgan natijasi bor xodim o'chirilmaydi (tarix saqlanadi) — uni bloklash kerak. */
    public static function deleteStaff(Staff $staff): bool
    {
        if ($staff->inspections()->where('status', '!=', 'draft')->exists()) {
            Notification::make()->title('O‘chirib bo‘lmaydi: xodimning yuborilgan natijalari bor')
                ->body('Natijalar tarixi saqlanishi uchun xodimni bloklang.')->danger()->send();

            return false;
        }

        $disk = Storage::disk(config('tutash.media_disk'));
        DB::transaction(function () use ($staff, $disk) {
            $staff->inspections()->get()->each->delete(); // qoralamalar fayllari bilan
            foreach ($staff->attendances()->get() as $a) {
                $disk->delete(array_filter([$a->video_path, $a->face_video_path]));
            }
            $staff->delete(); // davomat va tuman bog'lanishlari cascade
        });

        return true;
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
