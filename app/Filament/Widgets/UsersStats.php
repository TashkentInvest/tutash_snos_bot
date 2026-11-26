<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StaffResource;
use App\Models\Staff;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Bosh sahifa: bot foydalanuvchilari — bo'limlar bo'yicha, bugun faol, yangi qo'shilganlar. */
class UsersStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '120s';

    protected ?string $heading = 'Bot foydalanuvchilari';

    public static function canView(): bool
    {
        return User::currentIsAdmin();
    }

    protected function getStats(): array
    {
        $n = fn ($v) => number_format($v, 0, '', ' ');
        $base = Staff::whereNotNull('telegram_id');
        $role = fn (string $r) => Staff::where('role', $r)->where('status', $r === Staff::ROLE_CITIZEN ? Staff::CITIZEN : Staff::ACTIVE)->count();
        $total = (clone $base)->whereIn('status', [Staff::ACTIVE, Staff::CITIZEN])->count();
        $today = (clone $base)->where('last_seen_at', '>=', today())->count();
        $week = (clone $base)->where('created_at', '>=', now()->subDays(7))->count();
        $pending = (clone $base)->whereIn('status', [Staff::REGISTERING, Staff::AWAITING_PHONE])->count();
        $blocked = Staff::where('status', Staff::BLOCKED)->count();
        $url = fn (array $f = []) => StaffResource::getUrl('index', $f ? ['tableFilters' => $f] : []);
        $byRole = fn (string $r) => $url(['role' => ['value' => $r]]);

        return [
            Stat::make('Jami foydalanuvchilar', $n($total))->icon('heroicon-o-users')->url($url())
                ->description("Bugun faol: {$n($today)} · 7 kunda yangi: {$n($week)}"),
            Stat::make('Obyekt xodimlari', $n($role(Staff::ROLE_EMPLOYEE)))->icon('heroicon-o-user-group')->color('primary')->url($byRole(Staff::ROLE_EMPLOYEE)),
            Stat::make('Shartnoma', $n($role(Staff::ROLE_CONTRACT)))->icon('heroicon-o-document-text')->color('warning')->url($byRole(Staff::ROLE_CONTRACT))
                ->description('Gastro mas’ullar: '.$n(\Illuminate\Support\Facades\DB::table('gastro_responsible_staff')->distinct()->count('staff_id'))),
            Stat::make('Kadastr', $n($role(Staff::ROLE_CADASTRE)))->icon('heroicon-o-map')->color('info')->url($byRole(Staff::ROLE_CADASTRE)),
            Stat::make('Aholi', $n($role(Staff::ROLE_CITIZEN)))->icon('heroicon-o-home')->color('gray')->url($byRole(Staff::ROLE_CITIZEN)),
            Stat::make('Ro‘yxatdan o‘tmoqda', $n($pending))->icon('heroicon-o-clock')->color($pending ? 'warning' : 'gray')
                ->description('Bloklangan: '.$n($blocked)),
        ];
    }
}
