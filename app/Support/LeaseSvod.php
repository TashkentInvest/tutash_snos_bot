<?php

namespace App\Support;

use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\LeaseContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Gastronomik ko'chalar → ijara shartnomalari svodi: tumanlar, ko'chalar va xodimlar kesimida — shartnomalar soni,
 * maydon, oylik va joriy yil to'lovi, to'liq yil hajmi.
 * Faqat Word fayli yasalganlar hisoblanadi (xato paytidagi yarim yozuvlar emas).
 * «Takrorlarsiz»: bir ijarachi + kadastr bo'yicha qayta tuzilgan shartnomalardan faqat oxirgisi.
 * «Faqat joyida»: ko'chadan ruxsat etilgan masofadan uzoqda (cheklov o'chiq paytda, masalan sinov uchun) tuzilganlar chiqariladi.
 */
class LeaseSvod
{
    /** @return array{rows: list<array>, total: array, streets: list<array>, staff: list<array>, duplicates: int, remote: int, period: string} */
    public static function data(?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $unique = true, bool $onsite = false): array
    {
        $query = LeaseContract::query()->whereNotNull('docx_path')->with(['street:id,name', 'staff:id,full_name', 'district:id,name']);
        $from && $query->where('created_at', '>=', $from->copy()->startOfDay());
        $to && $query->where('created_at', '<=', $to->copy()->endOfDay());
        $all = $query->orderBy('id')->get();

        // bir xil ijarachi + kadastr (tuman ichida) — oxirgisi qoladi
        $key = fn (LeaseContract $c) => $c->district_id.'|'.mb_strtolower(trim((string) $c->tenant_name)).'|'.preg_replace('/\s+/', '', (string) $c->cadastre);
        $deduped = $all->groupBy($key)->map->last()->values();
        $duplicates = $all->count() - $deduped->count();
        $list = $unique ? $deduped : $all;

        $radius = Limits::leaseRadius();
        $isRemote = fn (LeaseContract $c) => (int) $c->street_distance_m > $radius;
        $remote = $list->filter($isRemote)->count();
        $onsite && $list = $list->reject($isRemote)->values();

        $tariffs = GastroResponsible::with('staff:id,full_name')->get()->keyBy('district_id');
        $streetCounts = GastroStreet::selectRaw('district_id, count(*) as n')->groupBy('district_id')->pluck('n', 'district_id');
        $byDistrict = $list->groupBy('district_id');

        $rows = District::orderBy('name')->get(['id', 'name'])->map(function (District $d) use ($byDistrict, $tariffs, $streetCounts, $isRemote) {
            $r = $tariffs[$d->id] ?? null;

            return ['name' => $d->name, 'responsibles' => $r?->staff->pluck('full_name')->join(', ') ?: '—',
                'streets' => (int) ($streetCounts[$d->id] ?? 0), 'tariff' => $r?->tariff]
                + self::sums($byDistrict[$d->id] ?? collect(), $isRemote);
        })->all();

        $total = ['name' => 'JAMI', 'responsibles' => '', 'streets' => array_sum(array_column($rows, 'streets')), 'tariff' => null]
            + self::sums($list, $isRemote);

        $streets = $list->groupBy('gastro_street_id')->map(fn (Collection $g) => [
            'district' => $g->first()->district?->name ?? '', 'name' => $g->first()->street?->name ?? '—'] + self::sums($g, $isRemote))
            ->sortByDesc('monthly')->values()->all();

        $staff = $list->groupBy('staff_id')->map(fn (Collection $g) => [
            'name' => $g->first()->staff?->full_name ?? '—',
            'districts' => $g->map(fn ($c) => $c->district?->name)->unique()->filter()->join(', '),
            'last' => $g->max('created_at')?->format('d.m.Y H:i')] + self::sums($g, $isRemote))
            ->sortByDesc('count')->values()->all();

        $period = $from || $to ? trim(($from ? $from->format('d.m.Y') : '…').' – '.($to ? $to->format('d.m.Y') : 'bugun')) : 'barcha davr';

        return compact('rows', 'total', 'streets', 'staff', 'duplicates', 'remote', 'period');
    }

    /** Guruh bo'yicha yig'indilar. */
    private static function sums(Collection $g, \Closure $isRemote): array
    {
        // oylik to'lov: shartnomadagi tarif × maydon (tarifsiz eski shartnoma — joriy yil to'lovi / oylar)
        $monthly = $g->sum(fn (LeaseContract $c) => $c->tariff
            ? round($c->area_m2 * $c->tariff, 2)
            : $c->annual_amount / max(13 - (int) $c->start_date?->month, 1));
        $area = (float) $g->sum('area_m2');

        return [
            'count' => $g->count(),
            'legal' => $g->where('party', LeaseContract::LEGAL)->count(),
            'individual' => $g->where('party', LeaseContract::INDIVIDUAL)->count(),
            'area' => round($area, 2),
            'avg_area' => $g->count() ? round($area / $g->count(), 1) : 0,
            'monthly' => round($monthly, 2),
            'annual' => round((float) $g->sum('annual_amount'), 2), // joriy yil (boshlanish oyidan dekabrgacha)
            'yearly' => round($monthly * 12, 2),                     // to'liq yil hajmi (12 oy)
            'remote' => $g->filter($isRemote)->count(),
        ];
    }

    /** Excel: 3 varaq — tumanlar, ko'chalar, xodimlar. */
    public static function xlsx(?CarbonInterface $from, ?CarbonInterface $to, bool $unique, bool $onsite): string
    {
        $data = self::data($from, $to, $unique, $onsite);
        $note = 'Davr: '.$data['period'].($unique ? ' · takrorlarsiz' : '').($onsite ? ' · faqat joyida tuzilganlar' : '');
        $line = fn (array $r) => [$r['name'], $r['responsibles'], $r['streets'], $r['count'], $r['legal'], $r['individual'], $r['area'], $r['avg_area'],
            $r['tariff'] ?? '', $r['monthly'], $r['annual'], $r['yearly'], $r['remote']];

        $book = (new XlsxBook)->sheet('Tumanlar', [5, 24, 34, 10, 13, 9, 9, 15, 15, 14, 18, 18, 20, 11])
            ->row(['Ijara shartnomalari — tumanlar kesimida svod'], XlsxBook::TITLE)
            ->row([$note])
            ->row(['№', 'Tuman', 'Mas’ullar', 'Ko‘chalar', 'Shartnomalar', 'Yuridik', 'Jismoniy', 'Jami maydon, m²', 'O‘rtacha maydon, m²',
                'Tarif (1 m² / oy)', 'Oylik to‘lov, so‘m', 'Joriy yil to‘lovi, so‘m', 'To‘liq yil hajmi (12 oy), so‘m', 'Uzoqdan tuzilgan'], XlsxBook::HEAD)
            ->row(['', ...$line($data['total'])], XlsxBook::TOTAL);
        foreach ($data['rows'] as $i => $r) {
            $book->row([$i + 1, ...$line($r)]);
        }

        $book->sheet('Ko‘chalar', [22, 30, 13, 15, 18, 18, 20])
            ->row(['Tuman', 'Ko‘cha', 'Shartnomalar', 'Jami maydon, m²', 'Oylik to‘lov, so‘m', 'Joriy yil to‘lovi, so‘m', 'To‘liq yil hajmi, so‘m'], XlsxBook::HEAD);
        foreach ($data['streets'] as $r) {
            $book->row([$r['district'], $r['name'], $r['count'], $r['area'], $r['monthly'], $r['annual'], $r['yearly']]);
        }

        $book->sheet('Xodimlar', [34, 30, 13, 15, 18, 18, 18])
            ->row(['Xodim', 'Tumanlar', 'Shartnomalar', 'Jami maydon, m²', 'Oylik to‘lov, so‘m', 'Joriy yil to‘lovi, so‘m', 'Oxirgi shartnoma'], XlsxBook::HEAD);
        foreach ($data['staff'] as $r) {
            $book->row([$r['name'], $r['districts'], $r['count'], $r['area'], $r['monthly'], $r['annual'], $r['last']]);
        }

        return $book->binary();
    }
}
