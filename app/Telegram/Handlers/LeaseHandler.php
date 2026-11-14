<?php

namespace App\Telegram\Handlers;

use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use SergiX44\Nutgram\Nutgram;

/**
 * Ijara shartnomasi (botda tugmalar bilan, obyektlar kabi): tuman → gastronomik ko'cha → obyekt oldida «Shartnoma to'ldirish»
 * (Mini App: tuman va ko'cha tanlangan, joylashuv faqat GPS dan — xodim joyiga chiqib to'ldiradi).
 */
class LeaseHandler
{
    private function staff(Nutgram $bot): ?Staff
    {
        $staff = Staff::where('telegram_id', $bot->userId())->where('status', Staff::ACTIVE)->first();
        if (! $staff || ! GastroResponsible::districtIdsFor($staff)) {
            $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => Ui::t('Ijara shartnomasi uchun sizga tuman biriktirilmagan.'), 'show_alert' => true]);

            return null;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();

        return $staff;
    }

    /** "ls": tumanlar (bitta bo'lsa — to'g'ri ko'chalariga). */
    public function start(Nutgram $bot): void
    {
        if (! $staff = $this->staff($bot)) {
            return;
        }
        $districts = District::whereIn('id', GastroResponsible::districtIdsFor($staff))->withCount('gastroStreets')->orderBy('name')->get();
        if ($districts->count() === 1) {
            $this->district($bot, (string) $districts->first()->id, $staff);

            return;
        }
        $rows = $districts->map(fn ($d) => [Ui::btn("{$d->name} · {$d->gastro_streets_count} ko‘cha", "ld:{$d->id}")])->all();
        $rows[] = [Ui::btn('⬅️ Orqaga', 'home')];
        Ui::show($bot, "📄 <b>Ijara shartnomasi</b>\n\nTumanni tanlang:", Ui::markup($rows));
    }

    /** "ld:{id}": tumanning gastronomik ko'chalari. */
    public function district(Nutgram $bot, string $id, ?Staff $staff = null): void
    {
        if (! $staff ??= $this->staff($bot)) {
            return;
        }
        if (! in_array((int) $id, GastroResponsible::districtIdsFor($staff), true)) {
            return;
        }
        $district = District::find((int) $id);
        $streets = GastroStreet::where('district_id', $district->id)->orderBy('name')->get()->unique('name');
        $rows = $streets->map(fn ($s) => [Ui::btn('🍽 '.$s->name, "lst:{$s->id}")])->values()->all();
        $rows[] = [Ui::btn('⬅️ Orqaga', count(GastroResponsible::districtIdsFor($staff)) > 1 ? 'ls' : 'home')];
        Ui::show($bot, '📄 <b>'.Ui::e($district->name)."</b> — gastronomik ko‘chalar\n\nObyekt qaysi ko‘chada? Tanlang:", Ui::markup($rows));
    }

    /** "lst:{id}": ko'cha tanlandi — obyekt oldida to'ldirish tugmasi. */
    public function street(Nutgram $bot, string $id): void
    {
        if (! $staff = $this->staff($bot)) {
            return;
        }
        $street = GastroStreet::with('district')->find((int) $id);
        if (! $street || ! in_array($street->district_id, GastroResponsible::districtIdsFor($staff), true)) {
            return;
        }
        Ui::show($bot, '📄 <b>'.Ui::e($street->district->name).' · '.Ui::e($street->name)."</b>\n\n"
            ."Obyekt oldiga boring va tugmani bosing — joylashuv telefon GPS'idan olinadi (ko‘chadan "
            .(int) config('tutash.max_distance_m').' m ichida bo‘lishi kerak). Keyin ijarachi va obyekt ma’lumotlarini kiriting.',
            Ui::markup([[BrowseHandler::leaseFormButton('📝 Shartnoma to‘ldirish', $street->id)], [Ui::btn('⬅️ Orqaga', "ld:{$street->district_id}")]]));
    }
}
