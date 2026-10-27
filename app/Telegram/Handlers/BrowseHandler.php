<?php

namespace App\Telegram\Handlers;

use App\Models\District;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Telegram\Support\Fuzzy;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

/** Tuman -> MFY -> obyekt ro'yxati, qidiruv va "yaqinimdagi" ko'rinishlari. */
class BrowseHandler
{
    /** Tasdiqlangan xodim yoki aholi (ko'rish huquqi) uchun. */
    public static function guard(Nutgram $bot): ?Staff
    {
        $staff = Staff::where('telegram_id', $bot->userId())->first();

        if (! $staff || ! $staff->canBrowse()) {
            $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => Ui::t('Avval /start buyrug‘i orqali tizimga kiring.'), 'show_alert' => true]);

            return null;
        }

        return $staff;
    }

    /** Faqat tasdiqlangan xodim uchun (tekshiruv, yaqin obyektlar). */
    public static function guardEmployee(Nutgram $bot): ?Staff
    {
        $staff = self::guard($bot);

        if ($staff && ! $staff->isActive()) {
            $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => Ui::t('Bu amal faqat tasdiqlangan xodimlar uchun.'), 'show_alert' => true]);

            return null;
        }

        return $staff;
    }

    public function home(Nutgram $bot): void
    {
        if (! $staff = self::guard($bot)) {
            return;
        }

        $districts = $staff->accessibleDistricts()
            ->withCount(['sites', 'sites as demolished_count' => fn ($query) => $query->where('status', Site::DEMOLISHED)])
            ->orderBy('name')->get();

        if ($districts->count() === 1) {
            $this->district($bot, (string) $districts->first()->id);

            return;
        }

        $rows = $districts->map(fn ($d) => [Ui::btn("{$d->name} · {$d->sites_count}/{$d->demolished_count}", "d:{$d->id}")])->all();
        $bot->callbackQuery() && $bot->answerCallbackQuery();

        $head = $staff->isCitizen()
            ? '<b>«Tutash snos» axborot tizimi</b>'
            : '👋 <b>'.Ui::e($staff->full_name).'</b>, xush kelibsiz.';
        Ui::show($bot, $head."\nTumanni tanlang. Tugmada: <i>jami obyektlar / shundan buzilganlari</i>.", Ui::markup($rows));
    }

    public function district(Nutgram $bot, string $id, string $page = '1'): void
    {
        if (! $staff = self::guard($bot)) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();

        $district = $staff->accessibleDistricts()->whereKey((int) $id)->first();
        if (! $district) {
            return;
        }

        $perPage = config('tutash.page_size');
        $mahallas = Mahalla::where('district_id', $district->id)
            ->withCount(['sites', 'sites as pending_count' => fn ($query) => $query->pending(), 'sites as demolished_count' => fn ($query) => $query->where('status', Site::DEMOLISHED)])
            ->orderByDesc('pending_count')->orderBy('name')
            ->get()->filter(fn ($m) => $m->sites_count > 0)->values();

        $pages = max((int) ceil($mahallas->count() / $perPage), 1);
        $page = min(max((int) $page, 1), $pages);

        $rows = $mahallas->forPage($page, $perPage)->map(fn ($m) => [Ui::btn(
            ($m->pending_count ? '⏳' : '✅')." ".Ui::cut($m->name, 30)." · {$m->sites_count}/{$m->demolished_count}",
            "m:{$m->id}:1:0"
        )])->all();

        if ($pages > 1) {
            $rows[] = $this->pager("dp:{$district->id}", $page, $pages);
        }
        $rows[] = $staff->isCitizen()
            ? [Ui::btn('🔍 Qidirish', "q:{$district->id}")]
            : [Ui::btn('🔍 Qidirish', "q:{$district->id}"), Ui::btn('📍 Yaqinimdagilar', "near:{$district->id}")];
        if ($staff->accessibleDistricts()->count() > 1) {
            $rows[] = [Ui::btn('⬅️ Orqaga', 'home')];
        }

        $text = '<b>'.Ui::e($district->name)."</b>\nMFYni tanlang. Tugmada: <i>jami obyektlar / shundan buzilganlari</i>.";
        if ($phone = $district->contactLine()) {
            $text .= "\n\n📞 Savol, taklif yoki e’tirozlaringiz bo‘lsa, ".Ui::e($district->name)." bo‘yicha mas’ul xodimga murojaat qiling:\n<b>".Ui::e($phone).'</b>';
        }

        Ui::show($bot, $text, Ui::markup($rows));
    }

    public function mahalla(Nutgram $bot, string $id, string $page = '1', string $all = '0'): void
    {
        if (! $staff = self::guard($bot)) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();

        $mahalla = Mahalla::find((int) $id);
        if (! $mahalla || ! $staff->canAccessDistrict($mahalla->district_id)) {
            return;
        }

        $query = Site::where('mahalla_id', $mahalla->id)->orderBy('address')->orderBy('id');
        if ($all !== '1') {
            $query->pending();
        }

        $perPage = config('tutash.page_size');
        $total = $query->count();
        $pages = max((int) ceil($total / $perPage), 1);
        $page = min(max((int) $page, 1), $pages);
        $sites = $query->forPage($page, $perPage)->get();

        $rows = $sites->map(fn ($s) => [$this->siteButton($s)])->all();
        if ($pages > 1) {
            $rows[] = $this->pager("m:{$mahalla->id}", $page, $pages, ":{$all}");
        }
        $rows[] = [$all === '1'
            ? Ui::btn('⏳ Faqat buzilmaganlar', "m:{$mahalla->id}:1:0")
            : Ui::btn('📋 Hammasini ko‘rsatish', "m:{$mahalla->id}:1:1")];
        $rows[] = [Ui::btn('⬅️ Orqaga', "d:{$mahalla->district_id}")];

        $head = '<b>'.Ui::e($mahalla->name)."</b>\n".($all === '1' ? 'Barcha obyektlar' : 'Buzilmagan obyektlar').": {$total} ta";
        Ui::show($bot, $total ? $head."\nObyektni tanlang:" : $head."\n\n✅ Ushbu MFYda buzilmagan obyekt qolmagan.", Ui::markup($rows));
    }

    public function site(Nutgram $bot, string $id): void
    {
        if (! $staff = self::guard($bot)) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();

        $site = Site::with(['mahalla', 'district', 'pendingReview'])->find((int) $id);
        if (! $site || ! $staff->canAccessDistrict($site->district_id)) {
            return;
        }

        $rows = [];
        if (! $staff->isCitizen()) {
            if (! $site->isDemolished() && ! $site->pendingReview) {
                $rows[] = [Ui::btn('▶️ Tekshiruvni boshlash', "go:{$site->id}")];
            }
            if ($site->lat && $site->lng) {
                $rows[] = [InlineKeyboardButton::make(Ui::t('🗺 Xaritada ko‘rish'), url: \App\Support\Maps::url($site->lat, $site->lng))];
            }
        }
        $rows[] = [Ui::btn('⬅️ Orqaga', $site->mahalla_id ? "m:{$site->mahalla_id}:1:0" : "d:{$site->district_id}")];

        Ui::show($bot, Ui::siteCard($site, $staff->isCitizen()), Ui::markup($rows));
    }

    /** Qidiruv rejimi: keyingi matn xabari qidiruv so'rovi sifatida qabul qilinadi. */
    public function askSearch(Nutgram $bot, string $districtId): void
    {
        if (! $staff = self::guard($bot)) {
            return;
        }
        $bot->answerCallbackQuery();

        if (! $staff->canAccessDistrict((int) $districtId)) {
            return;
        }

        Cache::put("tg-await:{$bot->userId()}", ['type' => 'search', 'district' => (int) $districtId], 600);
        Ui::say($bot, '🔍 Ko‘cha, MFY yoki huquqbuzar nomini yozing (masalan: <i>rustaveli</i>):');
    }

    public function search(Nutgram $bot, Staff $staff, array $await): void
    {
        Cache::forget("tg-await:{$bot->userId()}");
        $term = Ui::cut($bot->message()->text, 60);
        if (mb_strlen($term) < 3) {
            Ui::say($bot, '❗ Qidiruv uchun kamida 3 ta belgi kiriting.');
            Cache::put("tg-await:{$bot->userId()}", $await, 600);

            return;
        }

        $query = Fuzzy::normalize($term);
        $sites = Site::with('mahalla')->where('district_id', $await['district'])->get()
            ->map(fn ($s) => [$s, Fuzzy::score($query, Fuzzy::normalize($s->address.' '.$s->violator_name.' '.$s->mahalla?->name))])
            ->filter(fn ($p) => $p[1] > 0)
            ->sortBy([fn ($a, $b) => $b[1] <=> $a[1], fn ($a, $b) => $a[0]->isDemolished() <=> $b[0]->isDemolished()])
            ->take(15)->map(fn ($p) => $p[0])->values();

        $rows = $sites->map(fn ($s) => [$this->siteButton($s)])->all();
        $rows[] = [Ui::btn('⬅️ Orqaga', "d:{$await['district']}")];

        Ui::say($bot, $sites->isEmpty() ? "Hech narsa topilmadi: <b>".Ui::e($term).'</b>' : 'Topildi ('.$sites->count().'): <b>'.Ui::e($this->matchedName($sites->first(), $query) ?? $term).'</b>', Ui::markup($rows));
    }

    /** "Yaqinimdagilar": xodim lokatsiyasi so'raladi. */
    public function askNear(Nutgram $bot, string $districtId): void
    {
        if (! $staff = self::guardEmployee($bot)) {
            return;
        }
        $bot->answerCallbackQuery();

        if (! $staff->canAccessDistrict((int) $districtId)) {
            return;
        }

        Cache::put("tg-await:{$bot->userId()}", ['type' => 'near', 'district' => (int) $districtId], 600);
        $kb = ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(Ui::t('📍 Joylashuvni yuborish'), request_location: true));
        Ui::say($bot, 'Eng yaqin buzilmagan obyektlarni ko‘rsatish uchun joylashuvingizni yuboring:', $kb);
    }

    public function near(Nutgram $bot, Staff $staff, array $await): void
    {
        Cache::forget("tg-await:{$bot->userId()}");
        $loc = $bot->message()->location;

        $sites = Site::where('district_id', $await['district'])->pending()->whereNotNull('lat')->whereNotNull('lng')->get()
            ->map(fn ($s) => [$s, Site::distanceMeters($loc->latitude, $loc->longitude, $s->lat, $s->lng)])
            ->sortBy(1)->take(8);

        $rows = $sites->map(fn ($p) => [Ui::btn(
            Ui::statusEmoji($p[0]).' '.($p[1] >= 1000 ? round($p[1] / 1000, 1).' km' : $p[1].' m').' · '.Ui::cut($p[0]->address, 32),
            "s:{$p[0]->id}"
        )])->values()->all();
        $rows[] = [Ui::btn('⬅️ Orqaga', "d:{$await['district']}")];

        Ui::say($bot, '📍 Sizga eng yaqin obyektlar:', Ui::removeKeyboard());
        Ui::say($bot, $sites->isEmpty() ? 'Koordinatalari kiritilgan obyekt topilmadi.' : 'Obyektni tanlang:', Ui::markup($rows));
    }

    /** Qidiruvga eng mos kelgan maydonning to'liq nomi (MFY, manzil yoki huquqbuzar). */
    private function matchedName(Site $site, string $query): ?string
    {
        $best = null;
        $bestScore = 0.0;
        foreach ([$site->mahalla?->name, $site->address, $site->violator_name] as $field) {
            $score = $field ? Fuzzy::score($query, Fuzzy::normalize($field)) : 0.0;
            if ($score > $bestScore) {
                [$best, $bestScore] = [$field, $score];
            }
        }

        return $best ? Ui::cut($best, 60) : null;
    }

    private function siteButton(Site $s): InlineKeyboardButton
    {
        return Ui::btn(Ui::statusEmoji($s).' '.Ui::cut($s->address, 30).' · '.Ui::cut($s->violator_name ?: '—', 18), "s:{$s->id}");
    }

    private function pager(string $prefix, int $page, int $pages, string $suffix = ''): array
    {
        $row = [];
        if ($page > 1) {
            $row[] = Ui::btn('◀️', $prefix.':'.($page - 1).$suffix);
        }
        $row[] = Ui::btn("{$page}/{$pages}", 'noop');
        if ($page < $pages) {
            $row[] = Ui::btn('▶️', $prefix.':'.($page + 1).$suffix);
        }

        return $row;
    }
}
