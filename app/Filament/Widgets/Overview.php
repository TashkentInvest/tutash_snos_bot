<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\LeaseContractResource;
use App\Filament\Resources\SiteResource;
use App\Filament\Resources\StaffResource;
use App\Models\Attendance;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\Svod;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Bosh sahifa yuqori qismi (2-variant dizayn): 4 ta asosiy ko'rsatkich (14 kunlik chiziq va ulush), tumanlar kesimida
 * bajarilish grafigi, davomat doirasi, bot foydalanuvchilari / ijara shartnomalari / Dizayn-kod kartochkalari.
 */
class Overview extends Widget
{
    // sahifa bilan birga chiziladi: alohida so'rov (sekin tarmoqda har biri ~0,3–1 s) bo'lmaydi
    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected static ?int $sort = -10;

    protected static string $view = 'filament.widgets.overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null; // sekin o'zgaradi — sahifa yangilanganda

    public static function canView(): bool
    {
        return User::currentIsAdmin();
    }

    /** Oxirgi 14 kun: har kun oxiridagi qiymat (chiziqcha uchun). */
    private static function series(callable $valueAt): array
    {
        return collect(range(13, 0))->map(fn ($i) => $valueAt(CarbonImmutable::today()->subDays($i)->endOfDay()))->all();
    }

    /** Chiziqcha (sparkline) SVG yo'li: 0..100 x 0..32 maydonda. */
    public static function spark(array $values): array
    {
        $min = min($values);
        $max = max($values);
        $n = max(count($values) - 1, 1);
        $pts = collect($values)->values()->map(fn ($v, $i) => [round($i * 100 / $n, 2), round(30 - ($max > $min ? ($v - $min) / ($max - $min) * 26 : 13), 2)]);
        $line = $pts->map(fn ($p, $i) => ($i ? 'L' : 'M').$p[0].' '.$p[1])->join(' ');

        return ['line' => $line, 'area' => $line.' L100 32 L0 32 Z'];
    }

    protected function getViewData(): array
    {
        $day = ReportPeriod::fromFilter($this->filters['day'] ?? null)->day;

        // 1. asosiy ko'rsatkichlar — joriy holat (sud qarori bekor qilinganlar jamiga kirmaydi)
        $total = Site::countable()->count();
        $demolished = Site::where('status', Site::DEMOLISHED)->count();
        $inProgress = Site::where('status', Site::IN_PROGRESS)->count();
        $left = $total - $demolished - $inProgress;
        $exempt = Site::where('status', Site::EXEMPT)->count();
        $created = fn ($at) => Site::countable()->where('created_at', '<=', $at)->count();
        $demolishedAt = fn ($at) => Site::where('status', Site::DEMOLISHED)->where(fn ($q) => $q->whereNull('demolished_at')->orWhere('demolished_at', '<=', $at))->count();
        $pct = fn ($n) => $total ? round($n * 100 / $total, 1) : 0;
        $url = fn (?string $status = null) => SiteResource::getUrl('index', $status ? ['tableFilters' => ['status' => ['value' => $status]]] : []);
        // 14 kunlik chiziqchalar (42 ta so'rov) — 10 daqiqa keshda; obyekt holati o'zgarsa kalit ham o'zgaradi
        $stamp = md5(json_encode([today()->toDateString(), $total, $demolished, Site::max('updated_at')]));
        $sparks = \Illuminate\Support\Facades\Cache::remember("overview:sparks:{$stamp}", 600, fn () => [
            'created' => self::series($created), 'demolished' => self::series($demolishedAt),
        ]);
        $kpis = [
            ['label' => 'Jami obyektlar', 'value' => $total, 'pct' => 100, 'color' => '#1d4ed8', 'url' => $url(),
                'note' => $exempt ? "Sud qarori bekor qilingan {$exempt} ta hisobga olinmagan" : null, 'spark' => self::spark($sparks['created'])],
            ['label' => 'Buzilgan', 'value' => $demolished, 'pct' => $pct($demolished), 'color' => '#16a34a', 'url' => $url(Site::DEMOLISHED), 'showPct' => true,
                'spark' => self::spark($sparks['demolished'])],
            ['label' => 'Jarayonda', 'value' => $inProgress, 'pct' => $pct($inProgress), 'color' => '#d97706', 'url' => $url(Site::IN_PROGRESS), 'spark' => null],
            ['label' => 'Buzilmagan', 'value' => $left, 'pct' => $pct($left), 'color' => '#dc2626', 'url' => $url(Site::NOT_DEMOLISHED),
                'spark' => self::spark(array_map(fn ($c, $d) => $c - $d, $sparks['created'], $sparks['demolished']))],
        ];

        // 2. tumanlar kesimida bajarilish (svod bilan bir xil hisob) va davomat
        $svod = Svod::data($day);
        $ids = \App\Models\District::orderBy('name')->pluck('id')->all();
        $districts = collect($svod['rows'])->map(fn ($r, $i) => ['name' => preg_replace('/\s+tumani$/u', '', $r['name']),
            'pct' => Svod::progress($r)['pct'], 'url' => DistrictProgress::sitesUrl($ids[$i] ?? null)])->all();
        $districtTotal = Svod::progress($svod['total']);

        $aDay = Dashboard::attendanceDay(ReportPeriod::fromFilter($this->filters['day'] ?? null));
        $att = fn (array $s) => Attendance::whereDate('date', $aDay->toDateString())->whereIn('status', $s)->count();
        $range = ['from' => $aDay->toDateString(), 'to' => $aDay->toDateString()];
        $aUrl = fn (?string $s = null) => AttendanceResource::getUrl('index', ['tableFilters' => ['date' => $range] + ($s ? ['status' => ['value' => $s]] : [])]);
        $attendance = [
            'day' => $aDay,
            'total' => Attendance::whereDate('date', $aDay->toDateString())->count(),
            'parts' => [
                ['label' => 'Keldi', 'value' => $att([Attendance::PRESENT]), 'color' => '#16a34a', 'url' => $aUrl(Attendance::PRESENT)],
                ['label' => 'Sabab bilan', 'value' => $att([Attendance::ABSENT]), 'color' => '#d97706', 'url' => $aUrl(Attendance::ABSENT)],
                ['label' => 'Javob bermagan', 'value' => $att([Attendance::MISSED, ...Attendance::OPEN]), 'color' => '#dc2626', 'url' => $aUrl(Attendance::MISSED)],
            ],
            'url' => $aUrl(),
        ];

        // 3. kartochkalar
        $role = fn (string $r) => Staff::whereNotNull('telegram_id')->where('role', $r)->where('status', $r === Staff::ROLE_CITIZEN ? Staff::CITIZEN : Staff::ACTIVE)->count();
        $users = ['total' => Staff::whereNotNull('telegram_id')->whereIn('status', [Staff::ACTIVE, Staff::CITIZEN])->count(),
            'today' => Staff::whereNotNull('telegram_id')->where('last_seen_at', '>=', today())->count(), 'url' => StaffResource::getUrl('index'),
            'rows' => collect([['Xodimlar', Staff::ROLE_EMPLOYEE], ['Shartnoma', Staff::ROLE_CONTRACT], ['Kadastr', Staff::ROLE_CADASTRE], ['Aholi', Staff::ROLE_CITIZEN]])
                ->map(fn ($x) => ['label' => $x[0], 'value' => $role($x[1]), 'url' => StaffResource::getUrl('index', ['tableFilters' => ['role' => ['value' => $x[1]]]])])->all()];

        $lease = \App\Support\LeaseSvod::data(null, null, true, false)['total'];
        $leases = ['count' => $lease['count'], 'monthly' => $lease['monthly'], 'annual' => $lease['annual'], 'url' => LeaseContractResource::getUrl('index')];

        $dk = null;
        try {
            if (\App\Support\DizaynKodStreets::configured()) {
                $t = \App\Support\DesignCodeSvod::build(\App\Support\DesignCodeSvod::DEFAULT_BUFFER)['total'];
                $dk = $t['streets'] ? ['streets' => $t['streets'], 'km' => $t['km'], 'illegal' => $t['snos']['total'], 'demolished' => $t['snos']['demolished'],
                    'buffer' => \App\Support\DesignCodeSvod::DEFAULT_BUFFER, 'url' => \App\Filament\Pages\DesignCodeSvodPage::getUrl()] : null;
            }
        } catch (\Throwable $e) {
            report($e); // Dizayn-kod API ishlamasa — kartochka chiqmaydi, sahifa buzilmaydi
        }

        return compact('kpis', 'districts', 'districtTotal', 'attendance', 'users', 'leases', 'dk', 'day');
    }

    /** 31 200 000 → «31,2 mln». */
    public static function money(float $v): string
    {
        return $v >= 1_000_000 ? str_replace('.', ',', (string) round($v / 1_000_000, 1)).' mln' : number_format($v, 0, ',', ' ');
    }
}
