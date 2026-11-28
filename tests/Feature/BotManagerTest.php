<?php

namespace Tests\Feature;

use App\Jobs\BotSendReport;
use App\Jobs\SendPost;
use App\Models\Post;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/** Botda boshqaruv: panelsiz hisobot yuborish va xodimlarga xabar / post yozish. */
class BotManagerTest extends TestCase
{
    use RefreshDatabase;

    private function bot(int $uid): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(id: $uid, is_bot: false, first_name: 'T'));
        $bot->setCommonChat(Chat::make(id: $uid, type: 'private'));

        return $bot;
    }

    private function sent(FakeNutgram $bot): string
    {
        return urldecode(collect($bot->getRequestHistory())->map(fn ($r) => (string) $r['request']->getBody())->join("\n"));
    }

    public function test_manager_sends_reports_and_posts_from_the_bot(): void
    {
        Queue::fake();
        config(['nutgram.token' => '123:TEST']);
        $mgr = Staff::create(['telegram_id' => 8001, 'full_name' => 'Abdusattorov Abdulaziz', 'phone' => '908000001', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_CADASTRE, 'bot_manager' => true]);
        Staff::create(['telegram_id' => 8002, 'full_name' => 'Xodim Bir', 'phone' => '908000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        Staff::create(['telegram_id' => 8003, 'full_name' => 'Xodim Ikki', 'phone' => '908000003', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $plain = Staff::create(['telegram_id' => 8004, 'full_name' => 'Oddiy', 'phone' => '908000004', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);

        // bosh sahifada tugma, menyu
        $bot = $this->bot(8001);
        $bot->hearText('/start')->reply();
        $this->assertStringContainsString('Boshqaruv', $this->sent($bot));
        $bot = $this->bot(8001);
        $bot->hearCallbackQueryData('mg')->reply();
        $this->assertStringContainsString('Hisobot yuborish', $this->sent($bot));

        // hisobot: PDF — faqat menga → navbatga
        $bot = $this->bot(8001);
        $bot->hearCallbackQueryData('mg:rs:pdf')->reply();
        $this->assertStringContainsString('Faqat menga', $this->sent($bot));
        $bot = $this->bot(8001);
        $bot->hearCallbackQueryData('mg:rt:pdf:me')->reply();
        Queue::assertPushed(BotSendReport::class, fn ($j) => $j->report === 'pdf' && $j->onlyMe && $j->staffId === $mgr->id);
        $job = new BotSendReport('pdf', true, $mgr->id);
        $fake = Nutgram::fake();
        $job->handle($fake);
        $this->assertStringContainsString('hisobot-', $this->sent($fake));

        // post: obyekt xodimlariga matn → tasdiq → Post + navbat
        $bot = $this->bot(8001);
        $bot->hearCallbackQueryData('mg:pa:employee')->reply();
        $bot = $this->bot(8001);
        $bot->hearText('Ertaga 08:00 da yig‘ilish')->reply();
        $this->assertStringContainsString('3 ta', $this->sent($bot)); // 3 ta obyekt xodimi (bloklanmagan)
        $bot = $this->bot(8001);
        $bot->hearCallbackQueryData('mg:ps')->reply();
        $post = Post::sole();
        $this->assertSame(['Ertaga 08:00 da yig‘ilish', null, 3, 'Abdusattorov Abdulaziz'], [$post->caption, $post->media_type, $post->total, $post->audience['by']]);
        Queue::assertPushed(SendPost::class);

        // belgisi yo'q xodim — menyu ochilmaydi
        $bot = $this->bot($plain->telegram_id);
        $bot->hearCallbackQueryData('mg')->reply();
        $this->assertStringContainsString('ochilmagan', $this->sent($bot));
        $this->assertSame(1, Post::count());
    }
}
