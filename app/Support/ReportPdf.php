<?php

namespace App\Support;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Staff;
use App\Telegram\Support\Script;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * Kunlik PDF hisobot (namuna: «Tutash hudud arizalari — kunlik hisobot»): sarlavha (gerb, tizim), «Hurmatli ...!» murojaati,
 * tumanlar kesimida svod va har tuman bo'yicha hisobot davridagi tekshiruvlar (natija belgisi bilan), oxirida QR.
 * Har oluvchiga — o'z ismi bilan.
 */
class ReportPdf
{
    /** Natija → [matn, fon, rang] (namunadagi belgilar uslubida). */
    public const BADGES = [
        'Buzilgan' => ['Buzilgan', '#e8f6ec', '#15803d'],
        'Buzilgan (kelganda)' => ['Buzilgan (kelganda)', '#e8f6ec', '#15803d'],
        'Jarayonda' => ['Jarayonda', '#fff4d6', '#a16207'],
        'Buzilmagan' => ['Buzilmagan', '#fde8e8', '#b91c1c'],
        'Sud qarori bekor' => ['Sud qarori bekor', '#e8effd', '#1d4ed8'],
        'Boshqa muammo' => ['Boshqa muammo', '#eef0f3', '#475569'],
        'Oldingi holat' => ['Oldingi holat', '#eef0f3', '#475569'],
    ];

    public static function data(CarbonInterface $day): array
    {
        $period = ReportPeriod::for($day);
        $svod = Svod::data($day);
        $ids = District::orderBy('name')->pluck('id')->all();
        $inspections = $period->inspections()->with(['site.district', 'site.mahalla', 'staff'])->orderBy('submitted_at')->get()
            ->filter(fn (Inspection $i) => $i->site)->groupBy(fn (Inspection $i) => $i->site->district_id);

        $rows = collect($svod['rows'])->map(function ($r, $i) use ($ids, $inspections) {
            $p = Svod::progress($r);
            $list = $inspections[$ids[$i] ?? 0] ?? collect();

            return $r + ['id' => $ids[$i] ?? null, 'p' => $p, 'inspections' => $list->values(),
                'checked' => $list->count(), 'demolished_now' => $list->where('is_demolished', true)->count()];
        });

        return ['period' => $period, 'rows' => $rows, 'total' => $svod['total'] + ['p' => Svod::progress($svod['total']),
            'checked' => $rows->sum('checked'), 'demolished_now' => $rows->sum('demolished_now')]];
    }

    /** PDF (bayt); $name — «Hurmatli ...!» uchun, bo'sh bo'lsa umumiy murojaat. */
    public static function render(CarbonInterface $day, ?string $name = null, ?array $data = null): string
    {
        $data ??= self::data($day);
        $qr = (new QRCode(new QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG, 'scale' => 4, 'outputBase64' => true, 'addQuietzone' => false])))
            ->render(rtrim((string) config('app.url'), '/').'/admin');
        $gerb = is_file($f = public_path('images/hokimlik-gerb.png')) ? 'data:image/png;base64,'.base64_encode(file_get_contents($f)) : null;

        $html = view('pdf.daily-report', $data + [
            'day' => $day, 'name' => $name, 'qr' => $qr, 'gerb' => $gerb, 'now' => now(),
            'badge' => fn (Inspection $i) => self::BADGES[\App\Filament\Resources\InspectionResource::result($i)] ?? [\App\Filament\Resources\InspectionResource::result($i), '#eef0f3', '#475569'],
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->setOption(['defaultFont' => 'DejaVu Serif', 'isRemoteEnabled' => false, 'dpi' => 110, 'isFontSubsettingEnabled' => true])->output();
    }

    public static function filename(CarbonInterface $day): string
    {
        return 'hisobot-'.$day->toDateString().'.pdf';
    }

    /** Oluvchilarga — har biriga o'z ismi bilan PDF; yuborilganlar soni. */
    public static function send(Nutgram $bot, CarbonInterface $day, Collection $to): int
    {
        $data = self::data($day);
        $t = $data['total'];
        $caption = Script::apply("📄 <b>Kunlik hisobot (PDF)</b> — {$day->format('d.m.Y')}\n"
            ."Jami: <b>{$t['p']['all']}</b> · buzilgan: <b>{$t['p']['demolished']}</b> · qolgan: <b>{$t['left']}</b> · bajarilish {$t['p']['pct']}%\n"
            ."Hisobot davrida tekshirilgan: <b>{$t['checked']}</b>");
        $sent = 0;
        foreach ($to as $staff) {
            /** @var Staff $staff */
            try {
                $fh = fopen('php://temp', 'r+');
                fwrite($fh, self::render($day, $staff->full_name, $data));
                rewind($fh);
                $bot->sendDocument(InputFile::make($fh, self::filename($day)), ['chat_id' => $staff->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                $sent++;
            } catch (\Throwable $e) {
                report($e); // oluvchi botni bloklagan bo'lishi mumkin
            }
        }

        return $sent;
    }
}
