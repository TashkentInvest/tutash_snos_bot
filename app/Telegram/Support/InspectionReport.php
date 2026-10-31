<?php

namespace App\Telegram\Support;

use App\Models\Inspection;
use App\Models\Site;
use App\Support\Svod;
use Carbon\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * Kunlik tekshiruvlar (buzilish) hisoboti: tumanlar kesimidagi svod jadval rasmi va qisqa izoh (xodim ismlari va manzillarsiz).
 * Davomat hisoboti oluvchilariga (adminlar) botda yuboriladi.
 */
class InspectionReport
{
    public static function caption(Carbon $day): string
    {
        // rad etilganlar (arxiv) hisobga olinmaydi
        $rows = Inspection::counted()->whereDate('submitted_at', $day)->get();

        $demolished = $rows->where('is_demolished', true);
        $court = $rows->where('is_demolished', false)->where('exempt_type', Inspection::EXEMPT_COURT)->count();
        $progress = $rows->where('is_demolished', false)->whereNull('exempt_type')->where('in_progress', true)->count();

        $total = Site::countable()->count();
        $done = Site::where('status', Site::DEMOLISHED)->count();
        $svod = Svod::data($day)['total'];

        $lines = [
            '📊 <b>Kunlik hisobot — '.$day->format('d.m.Y').'</b>',
            "Tekshiruvlar: <b>{$rows->count()}</b>",
            "🏚 Buzilgan: <b>{$demolished->count()}</b>",
            "🚧 Jarayonda: <b>{$progress}</b>",
        ];
        $court && $lines[] = "⚖️ Sud qarori bekor: <b>{$court}</b>";
        $lines[] = '';
        $lines[] = "Qoldiq: <b>{$svod['left']}</b> ta · ".Svod::area($svod['area']).' ga';
        $lines[] = "Umumiy bajarilish: <b>{$done}</b> / {$total}".($total ? ' ('.round($done / $total * 100, 1).'%)' : '');
        $rows->isEmpty() && $lines[] = "\nBugun tekshiruv natijasi kiritilmagan.";

        return implode("\n", $lines);
    }

    /** Svod jadval rasmi + izohni barcha oluvchilarga yuboradi; yuborilgan xabarlar soni qaytadi. */
    public static function send(Nutgram $bot, Carbon $day): int
    {
        $caption = Script::apply(self::caption($day));
        $file = tempnam(sys_get_temp_dir(), 'svod').'.png';
        file_put_contents($file, Svod::png($day, fn (string $s) => Script::apply($s)));

        $sent = 0;
        $photo = null; // birinchi yuborishdan keyin Telegram file_id qayta ishlatiladi
        try {
            foreach (AttendanceReport::recipients() as $admin) {
                try {
                    $message = $bot->sendPhoto($photo ?? InputFile::make(fopen($file, 'r'), 'svod.png'), [
                        'chat_id' => $admin->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML,
                    ]);
                    $photo ??= collect($message?->photo ?? [])->last()?->file_id;
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // admin botni bloklagan bo'lishi mumkin
                }
            }
        } finally {
            @unlink($file);
        }

        return $sent;
    }
}
