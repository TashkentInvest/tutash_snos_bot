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
        $contract = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        Staff::create(['telegram_id' => 777002, 'full_name' => 'Oddiy xodim', 'phone' => '900000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $absent = Staff::create(['telegram_id' => 777003, 'full_name' => 'Kasal', 'phone' => '900000003', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
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
        $clip = fn () => UploadedFile::fake()->createWithContent('v.webm', "\x1A\x45\xDF\xA3".random_bytes(30000));
        // izoh majburiy: yo'q yoki juda qisqa bo'lsa — qabul qilinmaydi, qayd ochiq qoladi
        $this->tg('/tg/checkin-video', ['video' => $clip(), 'duration' => 6])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->tg('/tg/checkin-video', ['video' => $clip(), 'duration' => 6, 'comment' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame(Checkin::RECORDING, $row->refresh()->status);
        $this->tg('/tg/checkin-video', ['video' => $clip(), 'duration' => 6, 'comment' => '  Obyektdaman, buzish ishlarini kuzatyapman  '])->assertOk();
        $row->refresh();
        $this->assertSame(Checkin::PRESENT, $row->status);
        $this->assertSame('Obyektdaman, buzish ishlarini kuzatyapman', $row->comment);
        $this->get('/tg/checkin')->assertOk()->assertSee('3/3. Izoh');
        $this->assertSame(41.3, $row->lat);
        Storage::disk('local')->assertExists($row->video_path);
        $this->tg('/tg/checkin-state')->assertJsonPath('step', 'done');

        // 11:00 dagi javobsiz qolgani 12:00 da yopiladi
        $this->travelTo(today()->setTime(11, 5));
        $this->artisan('checkins:run')->assertSuccessful();
        $this->travelTo(today()->setTime(12, 0));
        $this->artisan('checkins:run')->expectsOutputToContain('yopildi 1')->assertSuccessful();
        $this->assertSame(Checkin::MISSED, Checkin::where('slot', '11:00')->value('status'));
        // 13:00 — tushlik tanaffusi yo'q, so'rov yuboriladi; 19:30 — kun tugagan
        $this->travelTo(today()->setTime(13, 30));
        $this->artisan('checkins:run')->expectsOutputToContain('Soat 13:00')->assertSuccessful();
        $this->assertSame(1, Checkin::where('slot', '13:00')->count());
        $this->travelTo(today()->setTime(19, 30));
        $this->artisan('checkins:run')->expectsOutputToContain('vaqti emas')->assertSuccessful();

        // admin: kunlik jadval va ro'yxat
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin/checkin-board')->assertOk()->assertSee('Aslanov Sanjar')->assertSee('10:20')->assertSee('yo‘q', false);
        $this->get('/admin/checkins')->assertOk()->assertSee('Qayd etildi');
        $this->get(route('admin.checkin-video', $row))->assertOk();
    }

    /** Ikkinchi vazifa: oddiy xodim «Soatlik qayd» belgisi bilan so'rov oladi, obyektlar bilan ishlashda davom etadi. */
    public function test_employee_with_hourly_checkin_flag_gets_requests_and_shows_on_board(): void
    {
        $both = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Ikki vazifali xodim', 'phone' => '950507174', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_EMPLOYEE, 'hourly_checkin' => true]);
        Staff::create(['telegram_id' => 777002, 'full_name' => 'Oddiy xodim', 'phone' => '900000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $contract = Staff::create(['telegram_id' => 777003, 'full_name' => 'Shartnoma xodimi', 'phone' => '900000003', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $citizen = Staff::create(['telegram_id' => 777004, 'full_name' => 'Aholi', 'phone' => '900000004', 'status' => Staff::CITIZEN, 'role' => Staff::ROLE_CITIZEN, 'hourly_checkin' => true]);

        $this->assertTrue($both->doesHourlyCheckin());
        $this->assertTrue($both->canBrowse()); // obyektlar bilan ishlash saqlanadi
        $this->assertFalse($citizen->doesHourlyCheckin());
        $this->assertEqualsCanonicalizing([$both->id, $contract->id], Staff::hourlyCheckin()->pluck('id')->all()); // aholi belgi bilan ham kirmaydi

        $this->travelTo(today()->setTime(10, 0));
        $this->artisan('checkins:run')->expectsOutputToContain('yuborildi 2')->assertSuccessful();
        $this->assertEqualsCanonicalizing([self::UID, 777003], $this->sentTo());

        $this->travelTo(today()->setTime(10, 15));
        $this->tg('/tg/checkin-location', ['lat' => 41.3, 'lng' => 69.25, 'accuracy' => 8])->assertOk();

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin/checkin-board')->assertOk()->assertSee('Ikki vazifali xodim')->assertSee('Shartnoma xodimi')->assertDontSee('Oddiy xodim');
        $this->get('/admin/checkin-board?who=employee')->assertOk()->assertSee('Ikki vazifali xodim')->assertDontSee('Shartnoma xodimi');
        $this->get('/admin/checkin-board?who=contract')->assertOk()->assertSee('Shartnoma xodimi')->assertDontSee('Ikki vazifali xodim');

        // admin xodimlar ro'yxatida belgini o'chiradi — so'rov to'xtaydi
        $both->update(['hourly_checkin' => false]);
        $this->travelTo(today()->setTime(11, 0));
        $this->artisan('checkins:run')->expectsOutputToContain('yuborildi 1')->assertSuccessful();
    }

    /** Old kamera (yuz) lavhasi ham saqlanadi va asosiy video bilan birlashtirishga navbatga qo'yiladi; jadvalda ikkalasi ko'rinadi. */
    public function test_checkin_face_video_is_kept_merged_and_linked_on_board(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Staff::create(['telegram_id' => self::UID, 'full_name' => 'Yuzli Xodim', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $this->travelTo(today()->setTime(10, 0));
        $this->artisan('checkins:run')->assertSuccessful();
        $this->tg('/tg/checkin-location', ['lat' => 41.3, 'lng' => 69.25, 'accuracy' => 8])->assertOk();
        $webm = "\x1A\x45\xDF\xA3".random_bytes(30000);
        $this->tg('/tg/checkin-video', ['comment' => 'Ofisdaman, yig‘ilishdaman', 'duration' => 6, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm),
            'face' => UploadedFile::fake()->createWithContent('f.webm', "\x1A\x45\xDF\xA3".random_bytes(8000))])->assertOk();

        $row = Checkin::first();
        $this->assertNotNull($row->face_video_path);
        Storage::disk('local')->assertExists($row->face_video_path);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\MergeCheckinVideo::class, fn ($job) => $job->checkinId === $row->id);

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin/checkin-board')->assertOk()->assertSee('face=1', false);
        $this->get(route('admin.checkin-video', ['checkin' => $row, 'face' => 1]))->assertOk();
    }

    /** Soatlik hisobot H:59:59 da; oxirgi soatdan (18:00) keyin — o'sha jarayonda, soatlik hisobotdan KEYIN kun yakuni. */
    public function test_hourly_and_day_end_checkin_reports_go_to_configured_recipients(): void
    {
        $a = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $b = Staff::create(['telegram_id' => 777011, 'full_name' => 'Boboyev Bobur', 'phone' => '900000011', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $boss = Staff::create(['telegram_id' => 777099, 'full_name' => 'Rahbar', 'phone' => '900000099', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true]);
        $this->receivesReports([$boss], [\App\Models\ReportSetting::CHECKIN_HOURLY, \App\Models\ReportSetting::CHECKIN_DAILY]);

        foreach (['10:00', '11:00', '15:00'] as $slot) {
            $this->travelTo(today()->setTimeFromTimeString($slot));
            $this->artisan('checkins:run')->assertSuccessful();
        }
        // A — 10:00 va 15:00 da qayd etdi, B — hech qachon
        Checkin::where('staff_id', $a->id)->whereIn('slot', ['10:00', '15:00'])->update(['status' => Checkin::PRESENT, 'responded_at' => now(), 'comment' => 'Obyektdaman']);

        // 15:59:59 — 15:00 soat hisoboti faqat rahbarga
        $this->travelTo(today()->setTime(15, 59, 59));
        $before = count(app(Nutgram::class)->getRequestHistory());
        $this->artisan('checkins:report')->expectsOutputToContain('Soat 15:00: yuborildi 1')->doesntExpectOutputToContain('Kun yakuni')->assertSuccessful();
        $sent = collect(app(Nutgram::class)->getRequestHistory())->slice($before)->map(fn ($r) => (string) array_values($r)[0]->getBody())->implode("\n");
        $this->assertStringContainsString('sendPhoto', collect(app(Nutgram::class)->getRequestHistory())->slice($before)->map(fn ($r) => (string) array_values($r)[0]->getUri())->implode(' '));
        $this->assertStringContainsString('777099', $sent);
        $this->assertStringContainsString('Soatlik qayd — 15:00', $sent);
        $this->assertStringContainsString('Qayd etdi: <b>1</b> / 2', $sent);
        $this->assertStringContainsString('Javob bermadi: <b>1</b>', $sent);

        // 13:00 ham soat (tushlik tanaffusi yo'q); 19:59 — so'rov yo'q
        $this->assertContains('13:00', Checkin::slots());
        $this->travelTo(today()->setTime(13, 59, 59));
        $this->artisan('checkins:report')->expectsOutputToContain('Soat 13:00')->assertSuccessful();
        $this->travelTo(today()->setTime(19, 59, 59));
        $this->artisan('checkins:report')->expectsOutputToContain('so‘rov yo‘q')->assertSuccessful();

        // 17:59:59 — faqat soatlik (kun yakuni hali emas)
        $this->travelTo(today()->setTime(17, 59, 59));
        $this->artisan('checkins:report')->expectsOutputToContain('Soat 17:00')->doesntExpectOutputToContain('Kun yakuni')->assertSuccessful();

        // 18:59:59 — avval 18:00 soatlik, so'ng kun yakuni (barcha 9 soat)
        $this->travelTo(today()->setTime(18, 59, 59));
        $before = count(app(Nutgram::class)->getRequestHistory());
        $this->artisan('checkins:report')->expectsOutputToContain('Soat 18:00: yuborildi 1')->expectsOutputToContain('Kun yakuni: yuborildi 1')->assertSuccessful();
        $captions = collect(app(Nutgram::class)->getRequestHistory())->slice($before)->map(fn ($r) => (string) array_values($r)[0]->getBody())->values();
        $this->assertCount(2, $captions);
        $this->assertStringContainsString('Soatlik qayd — 18:00', $captions[0]);   // birinchi — soatlik
        $this->assertStringContainsString('kun yakuni', $captions[1]);             // ikkinchi — kun yakuni
        $day = \App\Telegram\Support\CheckinReport::daily(today(), now());
        $this->assertStringStartsWith("\x89PNG", $day['png']);
        $this->assertStringContainsString('Qayd etildi: <b>2</b> / 18', $day['caption']); // 2 kishi × 9 soat (10..18)
        ($out = getenv('CHECKIN_PNG_OUT')) && file_put_contents($out, $day['png'])
            && file_put_contents(str_replace('.png', '-hourly.png', $out), \App\Telegram\Support\CheckinReport::hourly(today(), '15:00')['png']);

        // o'chirilgan hisobot yuborilmaydi
        \App\Models\ReportSetting::for(\App\Models\ReportSetting::CHECKIN_HOURLY)->update(['enabled' => false]);
        $this->travelTo(today()->setTime(16, 59, 59));
        $this->artisan('checkins:report')->expectsOutputToContain('o‘chirilgan')->assertSuccessful();

        // admin: namuna rasm
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin-checkin-report.png?type=daily')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/admin-checkin-report.png?type=hourly&slot=15:00')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_staff_edit_form_turns_hourly_checkin_on_and_shows_it_for_contract(): void
    {
        $emp = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Oddiy Xodim', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $contract = Staff::create(['telegram_id' => 777009, 'full_name' => 'Shartnoma', 'phone' => '900000009', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Resources\StaffResource\Pages\ManageStaff::class)
            ->mountTableAction('edit', $emp)
            ->assertTableActionDataSet(['role' => Staff::ROLE_EMPLOYEE, 'hourly_checkin' => false])
            ->setTableActionData(['hourly_checkin' => true])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertTrue($emp->refresh()->doesHourlyCheckin());

        \Livewire\Livewire::test(\App\Filament\Resources\StaffResource\Pages\ManageStaff::class)
            ->mountTableAction('edit', $contract)
            ->assertTableActionDataSet(['hourly_checkin' => true]) // rol bo'yicha doim yoqilgan
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertTrue($contract->refresh()->doesHourlyCheckin());
    }

    public function test_invite_can_give_employee_hourly_checkin(): void
    {
        \App\Support\StaffInvites::add('950507174', 'Taklif xodim', Staff::ROLE_EMPLOYEE, [], null, true);
        \App\Support\StaffInvites::add('900000009', 'Shartnoma', Staff::ROLE_CONTRACT, [], null, true);
        $this->assertTrue(\App\Models\StaffInvite::where('phone', '950507174')->value('hourly_checkin'));
        $this->assertFalse(\App\Models\StaffInvite::where('phone', '900000009')->value('hourly_checkin')); // shartnomada rol bo'yicha

        $staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'X', 'status' => Staff::AWAITING_PHONE]);
        $this->assertTrue(\App\Support\StaffInvites::applyOnRegistration($staff, '950507174'));
        $this->assertTrue($staff->fresh()->doesHourlyCheckin());
        $this->assertSame(Staff::ROLE_EMPLOYEE, $staff->fresh()->role);
    }

    /** Testlarda CSRF o'chiq — shuning uchun istisnolar ro'yxati alohida tekshiriladi (Mini App'da sessiya cookie'si yo'q). */
    public function test_all_mini_app_post_endpoints_are_excluded_from_csrf(): void
    {
        $middleware = app(\App\Http\Middleware\VerifyCsrfToken::class);
        $check = new \ReflectionMethod($middleware, 'inExceptArray');
        $paths = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('POST', $r->methods(), true) && str_starts_with($r->uri(), 'tg/'))
            ->map->uri()->values();
        $this->assertNotEmpty($paths);
        $this->assertContains('tg/checkin-location', $paths);
        foreach ($paths as $path) {
            $this->assertTrue($check->invoke($middleware, \Illuminate\Http\Request::create('/'.$path, 'POST')), "CSRF istisnosi yo'q: {$path}");
        }
    }

    public function test_board_reminds_staff_who_have_not_answered(): void
    {
        $late = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Javob bermagan', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $done = Staff::create(['telegram_id' => 777005, 'full_name' => 'Yuborgan', 'phone' => '900000005', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
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
