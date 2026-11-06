<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Support\ErrorAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/** Tizim xatoliklari DEBUG_ALERT_PHONES dagi xodimga Telegram orqali boradi. */
class ErrorAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nutgram.token' => '123:TEST', 'tutash.debug_alert_phones' => '+998 99 790 66 99']);
        $this->app->instance(Nutgram::class, Nutgram::fake());
        Cache::flush();
    }

    private function sent(): array
    {
        return collect(app(Nutgram::class)->getRequestHistory())
            ->map(fn ($r) => json_decode((string) array_values($r)[0]->getBody(), true))->filter()->values()->all();
    }

    public function test_reported_exception_is_sent_once_per_window(): void
    {
        Staff::create(['telegram_id' => 555001, 'full_name' => 'Debug', 'phone' => '997906699', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);

        $e = new \RuntimeException('Nimadir buzildi <b>');
        report($e);
        report($e); // bir xil xato 10 daqiqa ichida qayta yuborilmaydi

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(555001, $sent[0]['chat_id']);
        $this->assertStringContainsString('Debug: tizimda xatolik mavjud', $sent[0]['text']);
        $this->assertStringContainsString('RuntimeException', $sent[0]['text']);
        $this->assertStringContainsString('Nimadir buzildi &lt;b&gt;', $sent[0]['text']);
        $this->assertStringContainsString('ErrorAlertTest.php', $sent[0]['text']);

        $this->travel(11)->minutes();
        report($e);
        $this->assertCount(2, $this->sent());
    }

    public function test_no_alert_when_phone_not_registered_or_disabled(): void
    {
        report(new \RuntimeException('x'));
        $this->assertCount(0, $this->sent());

        Staff::create(['telegram_id' => 555001, 'full_name' => 'Debug', 'phone' => '997906699', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        config(['tutash.debug_alert_phones' => '']);
        report(new \LogicException('y'));
        $this->assertCount(0, $this->sent());
    }

    public function test_csrf_mismatch_outside_admin_is_alerted(): void
    {
        Staff::create(['telegram_id' => 555001, 'full_name' => 'Debug', 'phone' => '997906699', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $handler->render(\Illuminate\Http\Request::create('/admin/staff', 'POST'), new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));
        $this->assertCount(0, $this->sent()); // admin panelda sessiya tugashi — odatiy hol
        $handler->render(\Illuminate\Http\Request::create('/', 'POST'), new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));
        $this->assertCount(0, $this->sent()); // "POST //" — internet skanerlari, tizim xatosi emas

        $handler->render(\Illuminate\Http\Request::create('/tg/something', 'POST'), new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('CSRF token mismatch.', $this->sent()[0]['text']);
        $this->assertStringContainsString('POST /tg/something', $this->sent()[0]['text']);
    }
}
