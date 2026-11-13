<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreatePanelUser extends Command
{
    protected $signature = 'panel:user {email} {--name= : Ism-familiya} {--viewer : Kuzatuvchi — faqat obyektlarni ko\'radi} {--checkins : Faqat «Soatlik qayd» jadvali} {--admin : To\'liq admin} {--generate : Parolni avtomatik yaratib, bir marta ko\'rsatish}';

    protected $description = 'Admin panel foydalanuvchisini yaratadi yoki parol/rolini yangilaydi (parol yashirin so\'raladi).';

    public function handle(): int
    {
        if (count(array_filter([$this->option('viewer'), $this->option('admin'), $this->option('checkins')])) !== 1) {
            $this->error('--viewer, --checkins yoki --admin dan bittasini tanlang.');

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
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Yaratildi' : 'Yangilandi').": {$user->email} — "
            .($user->is_admin ? 'admin' : ($user->checkins_only ? 'faqat soatlik qayd' : 'kuzatuvchi (faqat obyektlar)')));
        $this->option('generate') && $this->line("Parol: {$password}");

        return self::SUCCESS;
    }
}
