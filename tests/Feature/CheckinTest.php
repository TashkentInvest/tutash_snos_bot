<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\Staff;
use App\Models\User;
use App\Support\MediaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/** Shartnoma xodimlarining soatlik qaydi: so'rov, joylashuv + video, muddat, admin jadvali. */
class CheckinTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 777001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nutgram.token' => '123:TEST']);
        Storage::fake('local');
        $this->app->instance(Nutgram::class, Nutgram::fake());
        $this->app->bind(MediaTool::class, fn () => new class extends MediaTool {
            public function isBlack(string $file): bool
            {
                return false;
            }
        });
        $this->travelTo(now()->startOfWeek()->setTime(9, 30)); // dushanba
    }

    private function initData(): string
    {
        $data = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => self::UID])];
        ksort($data);
        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode(chr(10));
        $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', '123:TEST', 'WebAppData', true));

        return http_build_query($data);
    }

    private function tg(string $path, array $data = [])
    {
        return $this->postJson($path, ['initData' => $this->initData()] + $data);
    }

    private function sentTo(): array
    {
        return collect(app(Nutgram::class)->getRequestHistory())
            ->map(fn ($r) => json_decode((string) array_values($r)[0]->getBody(), true)['chat_id'] ?? null)->filter()->values()->all();
    }

    public function test_contract_staff_get_hourly_request_and_check_in_with_location_and_video(): void
    {
        $contract = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_contract' => true]);
        Staff::create(['telegram_id' => 777002, 'full_name' => 'Oddiy xodim', 'phone' => '900000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $absent = Staff::create(['telegram_id' => 777003, 'full_name' => 'Kasal', 'phone' => '900000003', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_contract' => true]);
        Attendance::create(['staff_id' => $absent->id, 'date' => today(), 'status' => Attendance::ABSENT, 'reason' => 'Kasal']);

        // 09:30 — soatlik so'rov vaqti emas
        $this->artisan('checkins:run')->expectsOutputToContain('vaqti emas')->assertSuccessful();
        $this->assertSame(0, Checkin::count());

        // 10:00 — faqat shartnoma xodimiga (sabab bilan yo'q bo'lganga emas); qayta ishga tushsa takror yubormaydi
        $this->travelTo(today()->setTime(10, 0));
        $this->artisan('checkins:run')->expectsOutputToContain('yuborildi 1')->assertSuccessful();
        $this->artisan('checkins:run')->expectsOutputToContain('yuborildi 0')->assertSuccessful();
        $this->assertSame([self::UID], $this->sentTo());
        $row = Checkin::first();
        $this->assertSame('10:00', $row->slot);
        $this->assertSame('10:59', $row->deadline_at->format('H:i'));

        // mini app: joylashuv -> video -> qayd etildi
        $this->get('/tg/checkin')->assertOk()->assertSee('checkin-location', false)->assertSee('Soatlik qayd');
        $this->travelTo(today()->setTime(10, 20));
        $this->tg('/tg/checkin-state')->assertOk()->assertJsonPath('step', 'location');
        $this->tg('/tg/checkin-video')->assertStatus(422); // video maydoni yo'q
        $this->tg('/tg/checkin-location', ['lat' => 41.3, 'lng' => 69.25, 'accuracy' => 8])->assertOk();
        $this->tg('/tg/checkin-state')->assertJsonPath('step', 'video');
        $this->tg('/tg/checkin-video', ['video' => UploadedFile::fake()->createWithContent('v.webm', "\x1A\x45\xDF\xA3".random_bytes(30000)), 'duration' => 6])->assertOk();
        $row->refresh();
        $this->assertSame(Checkin::PRESENT, $row->status);
        $this->assertSame(41.3, $row->lat);
        Storage::disk('local')->assertExists($row->video_path);
        $this->tg('/tg/checkin-state')->assertJsonPath('step', 'done');

        // 13:30 — tushlik, so'rov yo'q; 11:00 dagi javobsiz qolgani 12:00 da yopiladi
        $this->travelTo(today()->setTime(11, 5));
        $this->artisan('checkins:run')->assertSuccessful();
        $this->travelTo(today()->setTime(12, 0));
        $this->artisan('checkins:run')->expectsOutputToContain('yopildi 1')->assertSuccessful();
        $this->assertSame(Checkin::MISSED, Checkin::where('slot', '11:00')->value('status'));
        $this->travelTo(today()->setTime(13, 30));
        $this->artisan('checkins:run')->expectsOutputToContain('vaqti emas')->assertSuccessful();
        $this->assertSame(0, Checkin::where('slot', '13:00')->count());

        // admin: kunlik jadval va ro'yxat
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin/checkin-board')->assertOk()->assertSee('Aslanov Sanjar')->assertSee('10:20')->assertSee('yo‘q', false);
        $this->get('/admin/checkins')->assertOk()->assertSee('Qayd etildi');
        $this->get(route('admin.checkin-video', $row))->assertOk();
    }

    public function test_board_reminds_staff_who_have_not_answered(): void
    {
        $late = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Javob bermagan', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_contract' => true]);
        $done = Staff::create(['telegram_id' => 777005, 'full_name' => 'Yuborgan', 'phone' => '900000005', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_contract' => true]);
        $this->travelTo(today()->setTime(15, 10));
        $this->artisan('checkins:run')->assertSuccessful();
        Checkin::where('staff_id', $done->id)->update(['status' => Checkin::PRESENT, 'responded_at' => now()]);
        $this->assertSame([$late->id], \App\Support\CheckinReminder::pending()->keys()->all());

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $bot = app(Nutgram::class);
        $before = count($bot->getRequestHistory());

        // faqat javob bermaganga — matn va yuborish tugmasi bilan
        \Livewire\Livewire::test(\App\Filament\Pages\CheckinBoard::class)
            ->callAction('remind', ['scope' => 'pending', 'text' => 'Qayerdasiz? Nega yubormadingiz?'])
            ->assertNotified('Eslatma yuborildi: 1 ta xodimga');
        $sent = collect($bot->getRequestHistory())->slice($before)->map(fn ($r) => (string) array_values($r)[0]->getBody())->implode("\n");
        $this->assertStringContainsString((string) self::UID, $sent);
        $this->assertStringNotContainsString('777005', $sent);
        $this->assertStringContainsString('checkin', $sent); // mini app tugmasi
        $this->assertStringContainsString('15:00', $sent);

        // hammaga
        \Livewire\Livewire::test(\App\Filament\Pages\CheckinBoard::class)
            ->callAction('remind', ['scope' => 'all', 'text' => 'Ertaga 08:00 da yig‘ilish'])
            ->assertNotified('Eslatma yuborildi: 2 ta xodimga');
    }
}
