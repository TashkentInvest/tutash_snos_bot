<?php

namespace App\Console\Commands;

use App\Support\FollowupReminder;
use Illuminate\Console\Command;

class RemindFollowups extends Command
{
    protected $signature = 'followups:remind';

    protected $description = 'Bajarilmagan moderator izohlari bo‘yicha xodimlarga eslatma yuboradi (bugun eslatilganlar qayta yuborilmaydi).';

    public function handle(): int
    {
        $sent = FollowupReminder::sendAll(skipToday: true);
        $this->info("Eslatmalar yuborildi: {$sent}");

        return self::SUCCESS;
    }
}
