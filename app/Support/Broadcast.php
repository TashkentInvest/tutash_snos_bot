<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use Throwable;

/**
 * Admin paneldan (bosh sahifa → «Xabar yuborish») botda xabarnoma: bo'limlar (obyekt xodimlari, kadastr, shartnoma,
 * gastronomik ko'chalar mas'ullari) va tumanlar bo'yicha tanlanadi, so'ng aniq oluvchilar belgilanadi. Aholiga yuborilmaydi.
 */
class Broadcast
{
    public const GROUPS = [
        Staff::ROLE_EMPLOYEE => 'Obyekt xodimlari',
        Staff::ROLE_CADASTRE => 'Kadastr',
        Staff::ROLE_CONTRACT => 'Shartnoma (davomat)',
        'gastro' => 'Gastronomik ko‘chalar mas’ullari',
    ];

    /** Aholi — faqat postlarda va faqat belgilansa (xabarnomada yo'q); tuman bo'yicha saralanmaydi. */
    public const CITIZENS = 'citizen';

    /** Botda faol, aholi bo'lmagan foydalanuvchilar. */
    private static function base(bool $citizens = false): Builder
    {
        return Staff::whereNotNull('telegram_id')->where(fn (Builder $q) => $q
            ->where(fn ($q) => $q->where('status', Staff::ACTIVE)->whereIn('role', Staff::DISTRICT_ROLES))
            ->when($citizens, fn ($q) => $q->orWhere(fn ($q) => $q->where('role', Staff::ROLE_CITIZEN)->where('status', Staff::CITIZEN))));
    }

    /**
     * Tanlangan bo'limlar (bo'sh — hammasi) va tumanlar (bo'sh — hammasi) bo'yicha oluvchilar.
     * Gastronomik mas'ullar tumani — «Gastronomik ko'chalar → Mas'ullar»dagi tumani (xodim «Tumanlar»i emas).
     */
    public static function recipients(array $districtIds = [], array $groups = []): Builder
    {
        $citizens = in_array(self::CITIZENS, $groups, true);
        $groups = array_values(array_intersect($groups ?: ($citizens ? [] : array_keys(self::GROUPS)), array_keys(self::GROUPS)));
        $roles = array_values(array_diff($groups, ['gastro']));
        $gastro = in_array('gastro', $groups, true);

        return self::base($citizens)->where(function (Builder $q) use ($roles, $gastro, $districtIds, $citizens) {
            $citizens && $q->orWhere('role', Staff::ROLE_CITIZEN);
            $roles && $q->orWhere(fn (Builder $q) => $q->whereIn('role', $roles)
                ->when($districtIds, fn ($q) => $q->whereHas('districts', fn ($q) => $q->whereIn('districts.id', $districtIds))));
            $gastro && $q->orWhereIn('id', fn ($q) => $q->select('gastro_responsible_staff.staff_id')->from('gastro_responsible_staff')
                ->join('gastro_responsibles', 'gastro_responsibles.id', '=', 'gastro_responsible_staff.gastro_responsible_id')
                ->when($districtIds, fn ($q) => $q->whereIn('gastro_responsibles.district_id', $districtIds)));
            $roles || $gastro || $citizens || $q->whereRaw('1 = 0');
        });
    }

    /** Tanlash ro'yxati uchun: id => «F.I.Sh · bo'lim · tumanlar». */
    public static function options(array $districtIds = [], array $groups = []): array
    {
        $gastro = \App\Models\GastroResponsible::with('district:id,name', 'staff:id')->get()
            ->flatMap(fn ($r) => $r->staff->map(fn ($s) => [$s->id, $r->district?->name]))->groupBy(0)->map(fn ($g) => $g->pluck(1)->filter()->all());

        return self::recipients($districtIds, $groups)->with('districts:id,name')->orderBy('full_name')->get()
            ->mapWithKeys(function (Staff $s) use ($gastro) {
                $where = $s->role === Staff::ROLE_CITIZEN ? [] : ($s->is_global ? ['barcha tumanlar'] : ($gastro[$s->id] ?? null ?: $s->districts->pluck('name')->all()));
                $where = array_map(fn ($n) => str_replace(' tumani', '', $n), $where);

                return [(string) $s->id => trim(($s->full_name ?: Phone::format($s->phone)).' · '
                    .(isset($gastro[$s->id]) ? 'gastro mas’ul' : ($s->role === Staff::ROLE_CITIZEN ? 'aholi' : mb_strtolower(\App\Filament\Resources\StaffResource::ROLES[$s->role] ?? $s->role)))
                    .($where ? ' · '.implode(', ', array_slice($where, 0, 3)).(count($where) > 3 ? '…' : '') : ''))];
            })->all();
    }

    /**
     * @param  array<int>  $staffIds  aniq oluvchilar (tanlash oynasidan); faqat botda faollarga boradi
     * @return array{sent: int, failed: int}
     */
    public static function send(string $text, array $staffIds, ?int $userId, array $meta = []): array
    {
        $bot = app(Nutgram::class);
        $html = Script::apply("📢 <b>Xabarnoma</b>\n\n".Ui::e(trim($text)));
        $sent = $failed = 0;

        foreach (self::base()->whereKey(array_map('intval', $staffIds))->get() as $staff) {
            try {
                $bot->sendMessage($html, ['chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'disable_web_page_preview' => true]);
                $sent++;
            } catch (Throwable) {
                $failed++; // xodim botni bloklagan bo'lishi mumkin
            }
            usleep(40_000); // Telegram cheklovi: ~30 xabar/soniya
        }

        AuditLog::record('staff.broadcast', null, ['by' => $userId, 'sent' => $sent, 'failed' => $failed, 'recipients' => count($staffIds)] + $meta);

        return ['sent' => $sent, 'failed' => $failed];
    }
}
