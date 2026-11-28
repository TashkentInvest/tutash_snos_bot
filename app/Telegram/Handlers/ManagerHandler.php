<?php

namespace App\Telegram\Handlers;

use App\Jobs\BotSendReport;
use App\Jobs\SendPost;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\PostDelivery;
use App\Models\Staff;
use App\Support\Broadcast;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;

/**
 * Botda boshqaruv («Botda boshqaruv» belgisi yoqilgan xodim): panelga kirmasdan
 *  — hisobotlarni yuborish (faqat o'ziga yoki sozlamadagi oluvchilarga);
 *  — xodimlarga xabar / post (matn, rasm yoki video + izoh) — bo'lim tanlanadi, yuborishdan oldin tasdiq;
 *    post «Postlar» sahifasida statistikasi bilan ko'rinadi («Tanishdim» tugmasi bilan).
 */
class ManagerHandler
{
    /** Post oluvchilari — bo'limlar to'plami. */
    public const AUDIENCES = [
        'staff' => ['Barcha xodimlar', ['employee', 'cadastre', 'contract', 'gastro']],
        'employee' => ['Obyekt xodimlari', ['employee']],
        'cadastre' => ['Kadastr', ['cadastre']],
        'contract' => ['Shartnoma xodimlari', ['contract']],
        'gastro' => ['Gastro mas’ullar', ['gastro']],
        'everyone' => ['Hammaga (aholi ham)', ['employee', 'cadastre', 'contract', 'gastro', 'citizen']],
    ];

    private const AWAIT = 'tg-await:';

    private const DRAFT = 'tg-post-draft:';

    public static function manager(Nutgram $bot): ?Staff
    {
        $staff = Staff::where('telegram_id', $bot->userId())->where('status', Staff::ACTIVE)->where('bot_manager', true)->first();
        if (! $staff) {
            $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => 'Bu bo‘lim sizga ochilmagan.']);
        }

        return $staff;
    }

    public function menu(Nutgram $bot): void
    {
        if (! self::manager($bot)) {
            return;
        }
        Cache::forget(self::AWAIT.$bot->userId());
        $bot->callbackQuery() && $bot->answerCallbackQuery();
        Ui::show($bot, "⚙️ <b>Boshqaruv</b>\n\nPanelga kirmasdan: hisobotlarni yuborish va xodimlarga xabar yoki post yozish.", Ui::markup([
            [Ui::btn('📊 Hisobot yuborish', 'mg:r')],
            [Ui::btn('📢 Xabar / post yozish', 'mg:p')],
            [Ui::btn('⬅️ Bosh sahifa', 'home')],
        ]));
    }

    // ---- hisobotlar

    public function reports(Nutgram $bot): void
    {
        if (! self::manager($bot)) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();
        $rows = collect(BotSendReport::REPORTS)->map(fn ($r, $k) => [Ui::btn($r[0], "mg:rs:{$k}")])->values()->all();
        Ui::show($bot, "📊 <b>Qaysi hisobot?</b>\n\nJoriy hisobot davri (18:00 gacha) bo‘yicha.", Ui::markup([...$rows, [Ui::btn('⬅️ Orqaga', 'mg')]]));
    }

    public function reportTarget(Nutgram $bot, string $report): void
    {
        if (! ($me = self::manager($bot)) || ! isset(BotSendReport::REPORTS[$report])) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();
        $all = BotSendReport::recipients($report, false, $me);
        Ui::show($bot, '📊 <b>'.Ui::e(BotSendReport::REPORTS[$report][0])."</b>\n\nKimga yuborilsin?"
            .($all->isNotEmpty() ? "\n\nOluvchilar (Sozlamalar → Hisobotlar): ".Ui::e($all->pluck('full_name')->join(', ')) : "\n\nSozlamada oluvchi belgilanmagan."),
            Ui::markup([
                [Ui::btn('👤 Faqat menga', "mg:rt:{$report}:me")],
                ...($all->isNotEmpty() ? [[Ui::btn("👥 Oluvchilarga ({$all->count()} ta)", "mg:rt:{$report}:all")]] : []),
                [Ui::btn('⬅️ Orqaga', 'mg:r')],
            ]));
    }

    public function reportSend(Nutgram $bot, string $report, string $target): void
    {
        if (! ($me = self::manager($bot)) || ! isset(BotSendReport::REPORTS[$report])) {
            return;
        }
        $bot->answerCallbackQuery(['text' => 'Tayyorlanmoqda…']);
        BotSendReport::dispatch($report, $target === 'me', $me->id);
        Ui::show($bot, '⏳ «'.Ui::e(BotSendReport::REPORTS[$report][0]).'» tayyorlanmoqda — '.($target === 'me' ? 'birozdan so‘ng sizga keladi.' : 'oluvchilarga yuboriladi, natijasini yozaman.'),
            Ui::markup([[Ui::btn('📊 Boshqa hisobot', 'mg:r')], [Ui::btn('⬅️ Boshqaruv', 'mg')]]));
    }

    // ---- xabar / post

    public function post(Nutgram $bot): void
    {
        if (! self::manager($bot)) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();
        $rows = collect(self::AUDIENCES)->map(fn ($a, $k) => [Ui::btn($a[0].' ('.Broadcast::recipients([], $a[1])->count().')', "mg:pa:{$k}")])->values()->all();
        Ui::show($bot, "📢 <b>Kimlarga yoziladi?</b>\n\nKeyin matn yoki rasm / video (izohi bilan) yuborasiz — yuborishdan oldin tasdiqlaysiz.", Ui::markup([...$rows, [Ui::btn('⬅️ Orqaga', 'mg')]]));
    }

    public function postAudience(Nutgram $bot, string $audience): void
    {
        if (! self::manager($bot) || ! isset(self::AUDIENCES[$audience])) {
            return;
        }
        $bot->callbackQuery() && $bot->answerCallbackQuery();
        Cache::put(self::AWAIT.$bot->userId(), ['type' => 'post', 'audience' => $audience], now()->addMinutes(30));
        Ui::show($bot, '✍️ <b>'.Ui::e(self::AUDIENCES[$audience][0])."</b> uchun xabarni yuboring:\n\n• matn;\n• yoki rasm / video — izohi (caption) bilan.\n\nYuborishdan oldin ko‘rib, tasdiqlaysiz.",
            Ui::markup([[Ui::btn('✖️ Bekor qilish', 'mg')]]));
    }

    /** MessageRouter: post kutilayotgan bo'lsa — qoralama va tasdiq so'rovi. true — xabar shu yerda qabul qilindi. */
    public static function capture(Nutgram $bot, Staff $staff): bool
    {
        $await = Cache::get(self::AWAIT.$bot->userId());
        if (($await['type'] ?? null) !== 'post' || ! $staff->bot_manager) {
            return false;
        }
        $m = $bot->message();
        $draft = match (true) {
            (bool) $m->photo => ['type' => 'photo', 'file_id' => collect($m->photo)->last()->file_id, 'caption' => $m->caption],
            (bool) $m->video => ['type' => 'video', 'file_id' => $m->video->file_id, 'caption' => $m->caption],
            filled($m->text) => ['type' => null, 'file_id' => null, 'caption' => $m->text],
            default => null,
        };
        if (! $draft) {
            Ui::say($bot, 'Matn, rasm yoki video yuboring.');

            return true;
        }
        if ($draft['type'] && mb_strlen((string) $draft['caption']) > 1000) {
            Ui::say($bot, 'Rasm / video izohi 1000 belgidan oshmasin (Telegram cheklovi). Qisqartirib qayta yuboring.');

            return true;
        }
        $draft['audience'] = $await['audience'];
        Cache::put(self::DRAFT.$bot->userId(), $draft, now()->addMinutes(30));
        Cache::forget(self::AWAIT.$bot->userId());
        $n = Broadcast::recipients([], self::AUDIENCES[$await['audience']][1])->count();
        Ui::say($bot, "👀 <b>Tekshiring:</b> yuqoridagi xabar <b>{$n} ta</b> oluvchiga («".Ui::e(self::AUDIENCES[$await['audience']][0])."») «✅ Tanishdim» tugmasi bilan yuboriladi.\n\nYuborilsinmi?",
            Ui::markup([[Ui::btn('✅ Yuborish', 'mg:ps'), Ui::btn('✖️ Bekor', 'mg')]]));

        return true;
    }

    public function postSend(Nutgram $bot): void
    {
        if (! ($me = self::manager($bot))) {
            return;
        }
        $draft = Cache::pull(self::DRAFT.$bot->userId());
        if (! $draft) {
            $bot->answerCallbackQuery(['text' => 'Qoralama topilmadi — qaytadan yozing.']);

            return;
        }
        $bot->answerCallbackQuery(['text' => 'Yuborilmoqda…']);
        $ids = Broadcast::recipients([], self::AUDIENCES[$draft['audience']][1])->pluck('id');
        $post = Post::create([
            'caption' => filled($draft['caption']) ? trim($draft['caption']) : null, 'media_type' => $draft['type'], 'file_id' => $draft['file_id'],
            'audience' => ['groups' => self::AUDIENCES[$draft['audience']][1], 'districts' => [], 'by_staff' => $me->id, 'by' => $me->full_name],
            'ack_button' => true, 'status' => Post::SENDING, 'total' => $ids->count(),
        ]);
        $now = now();
        foreach ($ids->chunk(500) as $chunk) {
            PostDelivery::insert($chunk->map(fn ($id) => ['post_id' => $post->id, 'staff_id' => $id, 'status' => PostDelivery::PENDING, 'created_at' => $now, 'updated_at' => $now])->all());
        }
        AuditLog::record('post.created.bot', $me->telegram_id, ['post' => $post->id, 'staff' => $me->id, 'recipients' => $ids->count()]);
        SendPost::dispatch($post->id);
        Ui::show($bot, "✅ Yuborilmoqda: <b>{$ids->count()} ta</b> oluvchi. Kim tanishgani — panelda «Postlar» sahifasida.",
            Ui::markup([[Ui::btn('📢 Yana yozish', 'mg:p')], [Ui::btn('⬅️ Boshqaruv', 'mg')]]));
    }
}
