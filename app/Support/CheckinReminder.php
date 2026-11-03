<?php

namespace App\Support;

use App\Console\Commands\RunCheckins;
use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use Throwable;

/** Shartnoma xodimlariga admin paneldan eslatma (kunlik jadval): hozirgi so'rovga javob bermaganlar yoki hammasi. */
class CheckinReminder
{
    public const PENDING = 'pending';
    public const ALL = 'all';

    public const DEFAULT_TEXT = 'Soatlik qaydni hali yubormadingiz. Hozir qayerdasiz? Iltimos, pastdagi tugma orqali joylashuv va videoni darhol yuboring.';

    /** Hozirgi (ochiq) so'rovi bor — joylashuv/video hali yuborilmagan — xodimlar. */
    public static function pending(): Collection
    {
        return Checkin::with('staff')->whereDate('date', today())->whereIn('status', Checkin::OPEN)->where('deadline_at', '>=', now())
            ->get()->filter(fn (Checkin $c) => $c->staff && ! $c->staff->trashed() && $c->staff->status === Staff::ACTIVE)
            ->keyBy('staff_id');
    }

    /** @return Collection<int, Staff> */
    public static function recipients(string $scope): Collection
    {
        return $scope === self::ALL
            ? Staff::where('status', Staff::ACTIVE)->where('is_contract', true)->whereNotNull('telegram_id')->orderBy('full_name')->get()
            : self::pending()->map->staff->values();
    }

    /** @return array{sent: int, failed: int} */
    public static function send(string $scope, string $text, ?int $userId): array
    {
        $bot = app(Nutgram::class);
        $open = self::pending();
        $sent = $failed = 0;

        foreach (self::recipients($scope) as $staff) {
            $row = $open[$staff->id] ?? null;
            $html = '🔔 <b>Eslatma</b>'.($row ? " — soatlik qayd {$row->slot}" : '')."\n\n".Ui::e(trim($text))
                .($row ? "\n\n<i>Javob muddati: soat ".$row->deadline_at->format('H:i').' gacha.</i>' : '');
            try {
                $bot->sendMessage(Script::apply($html), array_filter([
                    'chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML,
                    'reply_markup' => $row ? RunCheckins::markup() : null, // javob kutilayotgan bo'lsa — darhol yuborish tugmasi
                ]));
                $sent++;
            } catch (Throwable) {
                $failed++; // xodim botni bloklagan bo'lishi mumkin
            }
            usleep(40_000);
        }
        AuditLog::record('checkin.reminder', null, ['by' => $userId, 'scope' => $scope, 'sent' => $sent, 'failed' => $failed]);

        return ['sent' => $sent, 'failed' => $failed];
    }
}
