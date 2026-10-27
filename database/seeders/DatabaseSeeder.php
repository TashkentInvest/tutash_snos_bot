<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DistrictSeeder::class);

        // Admin ADMIN_EMAIL / ADMIN_PASSWORD .env qiymatlaridan olinadi (kodda parol saqlanmaydi).
        if ($email = env('ADMIN_EMAIL')) {
            $user = User::firstOrNew(['email' => $email]);
            $user->name = env('ADMIN_NAME', 'Administrator');
            if (! $user->exists) {
                $user->password = env('ADMIN_PASSWORD') ?: throw new \RuntimeException('ADMIN_PASSWORD .env da berilmagan.');
            }
            $user->is_admin = true;
            $user->save();
        }
    }
}
