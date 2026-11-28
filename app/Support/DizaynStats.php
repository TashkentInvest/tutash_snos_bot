<?php

namespace App\Support;

use App\Models\Dizayn\BotUser;
use App\Models\Dizayn\DesignObject;
use App\Models\Dizayn\Download;
use App\Models\Dizayn\Feedback;
use App\Models\Dizayn\Inspection;
use App\Models\Dizayn\Staff;
use App\Models\Dizayn\Street;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dizayn-kod botining raqamlari (Dizayn panelidagi BotStats bilan bir xil hisob — ikkala panelda bir xil chiqadi).
 * Kun — Toshkent vaqti bo'yicha; bazada vaqt UTC'da.
 */
class DizaynStats
{
    public const TZ = 'Asia/Tashkent';

    /** Botdagi «tuman» o'rnidagi umumiy dizayn-kod kitobi (tillar bo'yicha) — tumanlar va ko'chalar reytingiga kirmaydi. */
    public const BOOK = 'Дизайн-код';

    private static function todayUtc(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay()->utc();
    }

    /** @return array<string, int|float> */
    public static function totals(): array
    {
        return Cache::remember('dizayn.stats.totals', 60, function (): array {
            $today = self::todayUtc();
            $planned = (int) Street::query()->sum('planned_objects');
            $objects = DesignObject::query()->where('is_active', true);
            $installed = (clone $objects)->where('status', DesignObject::INSTALLED)->count();
            $linked = Staff::query()->where('is_active', true)->whereNotNull('user_id');

            return [
                'users' => BotUser::count(),
                'registered' => BotUser::whereNotNull('registered_at')->count(),
                'new_today' => BotUser::where('first_seen', '>=', $today)->count(),
                'blocked' => BotUser::whereNotNull('blocked_at')->count(),
                'active_today' => BotUser::where('last_seen', '>=', $today)->count(),
                'downloads' => Download::count(),
                'downloads_today' => Download::where('sent_at', '>=', $today)->count(),
                'feedback' => Feedback::count(),
                'feedback_open' => Feedback::whereNull('answer')->count(),
                'feedback_today' => Feedback::where('created_at', '>=', $today)->count(),
                'staff' => Staff::where('is_active', true)->count(),
                'staff_linked' => (clone $linked)->count(),
                'objects' => (clone $objects)->count(),
                'objects_today' => (clone $objects)->where('created_at', '>=', $today)->count(),
                'removed' => (clone $objects)->where('status', DesignObject::REMOVED)->count(),
                'installed' => $installed,
                'planned' => $planned,
                'pct' => $planned ? round($installed / $planned * 100, 1) : 0.0,
                'review' => Inspection::whereIn('review', Inspection::OPEN)->count(),
                'confirming' => Inspection::where('review', Inspection::CONFIRMING)->count(),
                'staff_active_today' => \Illuminate\Support\Facades\DB::connection('dizayn')->table('staff_days')->where('day', CarbonImmutable::now(self::TZ)->format('Y-m-d'))->count(),
                'evidence_today' => Inspection::where('submitted_at', '>=', $today)->count(),
            ];
        });
    }

    /** @return array<string,int> 'Y-m-d' => soni, oxirgi $days kun (nol kunlar ham) */
    public static function perDay(string $what, int $days = 14): array
    {
        [$query, $column] = match ($what) {
            'downloads' => [Download::query(), 'sent_at'],
            'users' => [BotUser::query(), 'first_seen'],
            'feedback' => [Feedback::query(), 'created_at'],
            'inspections' => [Inspection::query()->whereNotNull('submitted_at'), 'submitted_at'],
        };

        return Cache::remember("dizayn.stats.perday.{$what}.{$days}", 120, function () use ($query, $column, $days): array {
            $from = CarbonImmutable::now(self::TZ)->startOfDay()->subDays($days - 1);
            $tz = self::TZ;
            $day = $query->getConnection()->getDriverName() === 'pgsql'
                ? "to_char(($column AT TIME ZONE 'UTC') AT TIME ZONE '$tz', 'YYYY-MM-DD')"
                : "strftime('%Y-%m-%d', $column, '+5 hours')";
            $counts = $query->where($column, '>=', $from->utc())->selectRaw("$day as day, COUNT(*) as n")->groupBy('day')->pluck('n', 'day');

            return collect(range(0, $days - 1))->mapWithKeys(fn ($i) => [$k = $from->addDays($i)->format('Y-m-d') => (int) ($counts[$k] ?? 0)])->all();
        });
    }

    /** Eng ko'p yuklab olingan ko'chalar: [tuman, ko'cha, soni] — nomlar lotinchada. */
    public static function topStreets(int $limit = 8): Collection
    {
        return Download::query()->where('district', '!=', self::BOOK)->selectRaw('district, street, COUNT(*) as total')->groupBy('district', 'street')
            ->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['district' => Latin::of($r->district), 'street' => Latin::of($r->street), 'total' => (int) $r->total]);
    }

    /** Tumanlar bo'yicha yuklab olishlar: lotincha nom => soni. */
    public static function byDistrict(): Collection
    {
        return Download::query()->where('district', '!=', self::BOOK)->selectRaw('district, COUNT(*) as total')->groupBy('district')->orderByDesc('total')->get()
            ->mapWithKeys(fn ($r) => [Latin::of($r->district) => (int) $r->total]);
    }

    /** Umumiy dizayn-kod kitobi (tuman emas): til => yuklab olishlar soni. */
    public static function book(): Collection
    {
        return Download::query()->where('district', self::BOOK)->selectRaw('street, COUNT(*) as total')->groupBy('street')->orderByDesc('total')->get()
            ->mapWithKeys(fn ($r) => [Latin::of($r->street) => (int) $r->total]);
    }

    /**
     * Ko'chalar xatlovi — Dizayn-kod bazasidagi ko'chalardan (rasmiy ro'yxat u yerga avval kiritilgan): har bir ko'chada
     * noturar obyektlar soni (streets.nonres_objects), tuman xodimlari kiritgan tadbirkorlar va dizayn-kod bo'yicha qo'yilganlar.
     *
     * @return array{streets: int, nonres: int, entered: int, installed: int, districts: list<array>}
     */
    public static function xatlov(): array
    {
        return Cache::remember('dizayn.stats.xatlov', 60, function (): array {
            $counts = DesignObject::query()->where('is_active', true)
                ->selectRaw("street_id, COUNT(*) AS entered, SUM(CASE WHEN status='installed' THEN 1 ELSE 0 END) AS installed")
                ->groupBy('street_id')->get()->keyBy('street_id');

            $districts = \App\Models\Dizayn\District::query()->orderBy('sort_order')->orderBy('id')
                ->with(['streets' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id')])->get()
                ->map(function ($d) use ($counts) {
                    $streets = $d->streets->map(fn (Street $s) => ['name' => Latin::of($s->name), 'nonres' => $s->nonres_objects,
                        'entered' => (int) ($counts[$s->id]->entered ?? 0), 'installed' => (int) ($counts[$s->id]->installed ?? 0)]);

                    return ['name' => preg_replace('/\s+tumani$/u', '', Latin::of($d->name)), 'streets' => $streets->all(), 'count' => $streets->count(),
                        'nonres' => (int) $streets->sum('nonres'), 'entered' => (int) $streets->sum('entered'), 'installed' => (int) $streets->sum('installed')];
                })->filter(fn ($d) => $d['count'] > 0)->values();

            return ['streets' => (int) $districts->sum('count'), 'nonres' => (int) $districts->sum('nonres'),
                'entered' => (int) $districts->sum('entered'), 'installed' => (int) $districts->sum('installed'), 'districts' => $districts->all()];
        });
    }
}
