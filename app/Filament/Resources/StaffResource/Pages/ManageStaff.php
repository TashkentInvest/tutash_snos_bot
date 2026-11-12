<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Models\District;
use App\Support\Broadcast;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    /** «Xodim qo'shish» oynasidagi boshlang'ich rol (Kadastr xodimlari sahifasida — kadastr). */
    protected const DEFAULT_ROLE = \App\Models\Staff::ROLE_EMPLOYEE;

    protected function getHeaderActions(): array
    {
        return [
            // xodimni oldindan qo'shish: shu telefon bilan botga kirganda rol (va tumanlar) avtomatik beriladi
            // oldindan qo'shilib, botga hali kirmaganlar (avval menyuda alohida bo'lim edi)
            Action::make('invites')->color('gray')->icon('heroicon-o-clock')
                ->label(fn () => 'Kutilayotganlar ('.\App\Models\StaffInvite::whereNull('used_at')->count().')')
                ->url(fn () => \App\Filament\Resources\StaffInviteResource::getUrl()),
            Action::make('invite')->label('Xodim qo‘shish')->icon('heroicon-o-user-plus')
                ->modalHeading('Xodim qo‘shish')->modalSubmitActionLabel('Qo‘shish')
                ->modalDescription('Xodim botda /start bosib, shu telefon bilan kirganda avtomatik tasdiqlanadi va rol beriladi. Botda allaqachon bo‘lsa — roli darhol yangilanadi.')
                ->form([
                    Forms\Components\TextInput::make('full_name')->label('F.I.SH')->required()->maxLength(150),
                    Forms\Components\TextInput::make('phone')->label('Telefon (9 raqam)')->required()->placeholder('901234567')
                        ->regex('/^\+?(998)?\s*\d{2}\s*\d{3}\s*\d{2}\s*\d{2}$/')->validationMessages(['regex' => 'Telefon raqami noto‘g‘ri (masalan: 901234567).']),
                    Forms\Components\Radio::make('role')->label('Rol')->required()->live()->default(static::DEFAULT_ROLE)
                        ->options([\App\Models\Staff::ROLE_EMPLOYEE => 'Xodim — obyektlar bilan ishlaydi', \App\Models\Staff::ROLE_CONTRACT => 'Shartnoma — davomat va har soatlik qayd',
                            \App\Models\Staff::ROLE_CADASTRE => 'Kadastr — o‘z tumaniga yangi obyekt qo‘shadi'])
                        ->helperText(StaffResource::ROLE_HINT),
                    Forms\Components\Select::make('district_ids')->label('Tumanlar')->multiple()
                        ->options(District::orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Forms\Get $get) => in_array($get('role'), \App\Models\Staff::DISTRICT_ROLES, true))
                        ->required(fn (Forms\Get $get) => in_array($get('role'), [\App\Models\Staff::ROLE_EMPLOYEE, \App\Models\Staff::ROLE_CADASTRE], true)),
                    Forms\Components\Toggle::make('hourly_checkin')->label('Soatlik qayd ham yuritiladi')
                        ->helperText(StaffResource::CHECKIN_HINT)
                        ->visible(fn (Forms\Get $get) => $get('role') === \App\Models\Staff::ROLE_EMPLOYEE),
                ])
                ->action(function (array $data) {
                    $text = \App\Support\StaffInvites::add($data['phone'], $data['full_name'], $data['role'], $data['district_ids'] ?? [], auth()->id(), (bool) ($data['hourly_checkin'] ?? false));
                    Notification::make()->title($text)->success()->send();
                }),
            Action::make('broadcast')->label('Xabar yuborish')->icon('heroicon-o-megaphone')
                ->modalHeading('Xodimlarga xabarnoma')
                ->modalDescription('Xabar botda faol xodimlarga yuboriladi.')
                ->modalSubmitActionLabel('Yuborish')
                ->form([
                    Forms\Components\Select::make('districts')->label('Tumanlar')->multiple()
                        ->options(District::orderBy('name')->pluck('name', 'id'))
                        ->placeholder('Barcha xodimlar')->live()
                        ->helperText(fn (Forms\Get $get) => 'Oluvchilar: '.Broadcast::recipients(array_map('intval', $get('districts') ?? []))->count().' ta xodim'),
                    Forms\Components\Textarea::make('text')->label('Xabar matni')->required()->minLength(3)->maxLength(3500)->rows(6),
                ])
                ->action(function (array $data) {
                    $r = Broadcast::send($data['text'], array_map('intval', $data['districts'] ?? []), auth()->id());
                    Notification::make()->title("Yuborildi: {$r['sent']} ta xodimga".($r['failed'] ? ", yetkazilmadi: {$r['failed']}" : ''))
                        ->{$r['sent'] ? 'success' : 'warning'}()->send();
                }),
        ];
    }
}
