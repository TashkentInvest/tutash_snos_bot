<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreatePanelUser extends Command
{
    protected $signature = 'panel:user {email} {--name= : Ism-familiya} {--viewer : Kuzatuvchi — faqat obyektlarni ko\'radi} {--checkins : Faqat «Soatlik qayd» jadvali} {--attendance : Faqat «Ertalabki davomat» (ko\'rish)} {--leases : Faqat «Ijara shartnomalari» (ko\'rish, tuzatish; o\'chirmaydi)} {--sites-edit : Qo\'shimcha: obyektlarni ko\'rish va tahrirlash} {--admin : To\'liq admin} {--generate : Parolni avtomatik yaratib, bir marta ko\'rsatish}';

    protected $description = 'Admin panel foydalanuvchisini yaratadi yoki parol/rolini yangilaydi (parol yashirin so\'raladi).';

    public function handle(): int
    {
        if (count(array_filter([$this->option('viewer'), $this->option('admin'), $this->option('checkins'), $this->option('attendance'), $this->option('leases')])) !== 1) {
            $this->error('--viewer, --checkins, --attendance, --leases yoki --admin dan bittasini tanlang.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => mb_strtolower(trim($this->argument('email')))]);
        $user->name = $this->option('name') ?: ($user->name ?: $user->email);

        $password = $this->option('generate')
            ? \Illuminate\Support\Str::password(14, symbols: false)
            : (string) $this->secret('Parol (kamida 10 belgi'.($user->exists ? ', bo\'sh qoldirilsa — o\'zgarmaydi' : '').')');
        if ($password !== '' || ! $user->exists) {
            if (mb_strlen($password) < 10) {
                $this->error('Parol kamida 10 belgidan iborat bo\'lishi kerak.');

                return self::FAILURE;
            }
            $user->password = $password;
        }

        $user->is_admin = (bool) $this->option('admin');
        $user->is_viewer = (bool) $this->option('viewer');
        $user->checkins_only = (bool) $this->option('checkins');
        $user->attendance_only = (bool) $this->option('attendance');
        $user->leases_only = (bool) $this->option('leases');
        $user->sites_edit = (bool) $this->option('sites-edit');
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Yaratildi' : 'Yangilandi').": {$user->email} — "
            .match (true) {
                (bool) $user->is_admin => 'admin',
                (bool) $user->checkins_only => 'faqat soatlik qayd',
                (bool) $user->attendance_only => 'faqat ertalabki davomat',
                (bool) $user->leases_only => 'faqat ijara shartnomalari (o‘chira olmaydi)',
                default => 'kuzatuvchi (faqat obyektlar)',
            }.($user->sites_edit ? ' + obyektlarni tahrirlash' : ''));
        $this->option('generate') && $this->line("Parol: {$password}");

        return self::SUCCESS;
    }
}
