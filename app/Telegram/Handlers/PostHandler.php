<?php

namespace App\Telegram\Handlers;

use App\Models\PostDelivery;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use SergiX44\Nutgram\Nutgram;

/** Post ostidagi «✅ Tanishdim» — panel statistikasida sanaladi; tugma «Tanishdingiz» bo'lib qoladi. */
class PostHandler
{
    public function ack(Nutgram $bot, string $id): void
    {
        $staff = Staff::where('telegram_id', $bot->userId())->first();
        $d = $staff ? PostDelivery::where('post_id', (int) $id)->where('staff_id', $staff->id)->first() : null;
        if ($d && ! $d->ack_at) {
            $d->update(['ack_at' => now()]);
        }
        $staff?->forceFill(['last_seen_at' => now()])->save();
        $bot->answerCallbackQuery(['text' => Script::apply('Rahmat!')]);
        try {
            $bot->editMessageReplyMarkup(['reply_markup' => Ui::markup([[Ui::btn(Script::apply('✅ Tanishdingiz'), 'noop')]])]);
        } catch (\Throwable) {
            // xabar eskirgan bo'lishi mumkin — sanaldi, yetarli
        }
    }
}
