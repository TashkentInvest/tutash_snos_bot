<?php

namespace App\Support;

use App\Models\Inspection;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hisobot davri: "kun" = oldingi kun 18:00 dan shu kun 18:00 gacha (DAILY_REPORT_AT).
 * 18:00 dagi hisobot shu davr natijasini yuboradi; undan keyin dashboard keyingi davrga o'tadi va hisoblagichlar 0 dan boshlanadi.
 */
class ReportPeriod
{
    public function __construct(public readonly CarbonImmutable $day, public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    public static function for(Carbon|CarbonImmutable|string $day): self
    {
        $day = CarbonImmutable::parse($day)->startOfDay();
        $to = $day->setTimeFromTimeString((string) config('tutash.daily_report_at', '18:00'));

        return new self($day, $to->subDay(), $to);
    }

    /** Joriy davr: hisobot vaqti o'tgan bo'lsa — ertangi kun davri (hisoblagichlar 0 dan boshlanadi). */
    public static function current(): self
    {
        $today = self::for(today());

        return now()->greaterThanOrEqualTo($today->to) ? self::for(today()->addDay()) : $today;
    }

    /** Dashboard filtridagi sana (bo'sh bo'lsa — joriy davr). */
    public static function fromFilter(?string $day): self
    {
        try {
            return $day ? self::for($day) : self::current();
        } catch (\Throwable) {
            return self::current();
        }
    }

    /** Davr ichida yuborilgan natijalar (rad etilganlar va izoh bo'yicha tasdiqlar hisobga olinmaydi). */
    public function inspections(): Builder
    {
        return Inspection::counted()->where('submitted_at', '>', $this->from)->where('submitted_at', '<=', $this->to);
    }

    /** Davr hali tugamagan (joriy davr). */
    public function isOpen(): bool
    {
        return now()->lessThan($this->to);
    }

    public function label(): string
    {
        return $this->from->format('d.m H:i').' — '.$this->to->format('d.m H:i');
    }

    /**
     * Davr natijalari: tekshiruvlar soni, buzilgan (tasdiqlangan / kutilmoqda), jarayonda, sud qarori bekor, boshqa.
     * Bir obyekt bo'yicha bir nechta natija bo'lsa — oxirgisi olinadi.
     *
     * @return array{inspections: int, demolished: int, approved: int, pending: int, progress: int, court: int, other: int}
     */
    public function summary(): array
    {
        $rows = $this->inspections()->orderBy('submitted_at')->get(['id', 'site_id', 'is_demolished', 'in_progress', 'exempt_type', 'review_status']);
        $last = $rows->keyBy('site_id'); // har obyekt bo'yicha oxirgi natija

        $demolished = $last->where('is_demolished', true);

        return [
            'inspections' => $rows->count(),
            'demolished' => $demolished->count(),
            'approved' => $demolished->where('review_status', Inspection::REVIEW_APPROVED)->count(),
            'pending' => $demolished->where('review_status', Inspection::REVIEW_PENDING)->count(),
            'progress' => $last->where('is_demolished', false)->whereNull('exempt_type')->where('in_progress', true)->count(),
            'court' => $last->where('is_demolished', false)->where('exempt_type', Inspection::EXEMPT_COURT)->count(),
            'other' => $last->where('is_demolished', false)->filter(fn ($i) => $i->exempt_type === Inspection::EXEMPT_OTHER || (! $i->exempt_type && ! $i->in_progress))->count(),
        ];
    }
}
