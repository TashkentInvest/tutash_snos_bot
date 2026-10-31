<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\District;
use App\Models\Inspection;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Telegram\Support\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SergiX44\Hydrator\Hydrator;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class RolesAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 555001;

    private District $district;
    private Site $site;
    private Site $demolished;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->startOfWeek()->setTime(8, 50)); // dushanba 08:50

        $this->district = District::create(['name' => 'Yunusobod tumani', 'responsible_name' => 'Boqiyev Obidjon', 'phone' => '999075300']);
        District::create(['name' => 'Chilonzor tumani', 'phone' => '974100313']);
        $mahalla = Mahalla::create(['district_id' => $this->district->id, 'name' => 'Test MFY']);
        $base = ['district_id' => $this->district->id, 'mahalla_id' => $mahalla->id, 'violator_name' => 'TEST MCHJ', 'violator_passport' => 'AD1234567', 'area_m2' => 123, 'lat' => 41.3, 'lng' => 69.25];
        $this->site = Site::create($base + ['source_no' => 1, 'address' => 'Birinchi kocha 1']);
        $this->demolished = Site::create($base + ['source_no' => 2, 'address' => 'Ikkinchi kocha 2', 'status' => Site::DEMOLISHED]);
    }

    private function bot(): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(id: self::UID, is_bot: false, first_name: 'Test'));
        $bot->setCommonChat(Chat::make(id: self::UID, type: 'private'));

        return $bot;
    }

    private function hear(array $message): void
    {
        $update = (new Hydrator)->hydrate(Update::class, [
            'update_id' => random_int(1, 1_000_000_000),
            'message' => $message + [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'from' => ['id' => self::UID, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => self::UID, 'type' => 'private'],
            ],
        ]);
        $this->bot()->hearUpdate($update)->reply();
    }

    /** Botdan chiqqan so'rovlar tanasi (matn va tugmalar), JSON-escape'siz. */
    private function sent(): string
    {
        $out = '';
        foreach ($this->bot()->getRequestHistory() as $pair) {
            [$request] = array_values($pair);
            $body = (string) $request->getBody();
            $out .= (json_decode($body, true) !== null ? json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body)."\n";
        }

        return $out;
    }

    private function registerEmployee(): Staff
    {
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();
        $this->bot()->hearText('Aliyev Ali Aliyevich')->reply();
        $this->bot()->hearMessage(['contact' => ['phone_number' => '+998 99 907 53 00', 'first_name' => 'T', 'user_id' => self::UID]])->reply();

        return Staff::where('telegram_id', self::UID)->first();
    }

    public function test_new_user_chooses_role_once(): void
    {
        $this->bot()->hearText('/start')->reply();
        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertNull($staff->role);
        $this->assertStringContainsString('role:employee', $this->sent());

        $this->bot()->hearCallbackQueryData('role:citizen')->reply();
        $staff->refresh();
        $this->assertSame(Staff::ROLE_CITIZEN, $staff->role);
        $this->assertTrue($staff->isCitizen());

        // tasdiqlanmagan foydalanuvchiga har /start da kirish turi qayta taklif etiladi
        $this->bot()->hearText('/start')->reply();
        $this->assertStringContainsString('role:employee', $this->sent());
        $this->assertSame(Staff::ROLE_CITIZEN, $staff->refresh()->role);
    }

    public function test_confirmed_employee_goes_straight_home_and_locked_user_can_still_be_citizen(): void
    {
        $this->registerEmployee();
        $this->bot()->hearText('/start')->reply();
        $this->assertStringNotContainsString('role:citizen', $this->sent());

        $locked = Staff::create(['telegram_id' => 999, 'status' => Staff::REJECTED, 'role' => Staff::ROLE_EMPLOYEE, 'failed_attempts' => 3, 'full_name' => 'Locked User Test']);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(id: 999, is_bot: false, first_name: 'L'));
        $bot->setCommonChat(Chat::make(id: 999, type: 'private'));
        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('role:employee')->reply();
        $this->assertSame(Staff::REJECTED, $locked->refresh()->status);
        $bot->hearCallbackQueryData('role:citizen')->reply();
        $this->assertTrue($locked->refresh()->isCitizen());
    }

    public function test_citizen_sees_limited_card_counts_and_district_phone(): void
    {
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:citizen')->reply();

        // jami/buzilgan: 2 ta obyekt, 1 tasi buzilgan
        $this->assertStringContainsString('Yunusobod tumani · 2/1', $this->sent());

        $this->bot()->hearCallbackQueryData("d:{$this->district->id}")->reply();
        $this->assertStringContainsString('+998 99 907 53 00', $this->sent());
        $this->assertStringContainsString('Test MFY · 2/1', $this->sent());
        $this->assertStringNotContainsString('near:', $this->sent());

        $this->bot()->hearCallbackQueryData("s:{$this->site->id}")->reply();
        $card = $this->sent();
        $this->assertStringContainsString('TEST MCHJ', $card);
        $this->assertStringContainsString('Sud qarori', $card);
        $this->assertStringContainsString('Boqiyev Obidjon, +998 99 907 53 00', $card);
        $this->assertStringNotContainsString('AD1234567', $card);
        $this->assertStringNotContainsString('Egallangan maydon', $card);
        $this->assertStringNotContainsString('go:', $card);

        // aholi tekshiruv boshlay olmaydi
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->assertSame(0, Inspection::count());
    }

    public function test_employee_sees_full_passport(): void
    {
        $this->registerEmployee();
        $this->bot()->hearCallbackQueryData("s:{$this->site->id}")->reply();

        $this->assertStringContainsString('AD1234567', $this->sent());
        $this->assertStringContainsString('go:', $this->sent());
    }

    public function test_citizen_cannot_become_employee_without_phone_check(): void
    {
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:citizen')->reply();
        $this->bot()->hearText('/rol')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();

        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertFalse($staff->isActive());
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->assertSame(0, Inspection::count());
    }

    private function initData(int $userId): string
    {
        config(['nutgram.token' => '123:TEST']);
        $data = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => $userId])];
        ksort($data);
        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode(chr(10));
        $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', '123:TEST', 'WebAppData', true));

        return http_build_query($data);
    }

    public function test_attendance_present_flow(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->assertSame(Attendance::SENT, $row->status);
        $this->assertNotNull($row->sent_at);

        // qayta ishga tushirilsa takror yubormaydi
        $this->artisan('attendance:send')->assertSuccessful();
        $this->assertSame(1, Attendance::count());

        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);

        // forward qilingan joylashuv rad etiladi
        $this->hear(['location' => ['latitude' => 41.3, 'longitude' => 69.2], 'forward_date' => time(), 'forward_sender_name' => 'X']);
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);

        // «jonli» joylashuv ham rad etiladi
        $this->hear(['location' => ['latitude' => 41.3, 'longitude' => 69.2, 'live_period' => 3600]]);
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);

        // xabar sifatida kelgan har qanday joylashuv (aniqligi bor ham) rad etiladi
        $this->hear(['location' => ['latitude' => 41.3, 'longitude' => 69.2, 'horizontal_accuracy' => 12.5]]);
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);

        // Mini App: imzosiz / begona imzo rad etiladi, to'g'ri imzo qabul qilinadi
        $this->postJson('/tg/attendance-location', ['initData' => 'user=%7B%22id%22%3A1%7D&auth_date='.time().'&hash=bad', 'lat' => 41.3, 'lng' => 69.2])->assertStatus(403);
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.3, 'lng' => 69.2, 'accuracy' => 12.5])->assertOk();
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.4, 'lng' => 69.3])->assertStatus(409);
        $this->assertEqualsWithDelta(41.3, $row->lat, 0.0001);

        // chatga yuborilgan har qanday video (dumaloq, uzatilgan, oddiy) rad etiladi
        $this->hear(['video_note' => ['file_id' => 'a1', 'file_unique_id' => 'ua1', 'length' => 240, 'duration' => 8], 'forward_date' => time(), 'forward_sender_name' => 'X']);
        $this->hear(['video_note' => ['file_id' => 'a4', 'file_unique_id' => 'ua4', 'length' => 240, 'duration' => 7]]);
        $this->hear(['video' => ['file_id' => 'a3', 'file_unique_id' => 'ua3', 'width' => 1, 'height' => 1, 'duration' => 9]]);
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);

        // Mini App: qisqa video, noto'g'ri format rad etiladi; jonli video qabul qilinadi
        Storage::fake('local');
        $init = $this->initData(self::UID);
        $webm = "\x1A\x45\xDF\xA3".str_repeat('a', 30000);
        $this->post('/tg/attendance-video', ['initData' => $init, 'duration' => 1.2, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/tg/attendance-video', ['initData' => $init, 'duration' => 8, 'video' => UploadedFile::fake()->createWithContent('v.webm', 'not a video')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        $this->post('/tg/attendance-video', ['initData' => $init, 'duration' => 8, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm), 'face' => UploadedFile::fake()->createWithContent('f.webm', substr($webm, 0, 5000))], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(Attendance::PRESENT, $row->refresh()->status);
        $this->assertNotNull($row->responded_at);
        $this->assertNotNull($row->video_path);
        Storage::disk('local')->assertExists($row->video_path);
        Storage::disk('local')->assertExists($row->face_video_path);
        Queue::assertPushed(\App\Jobs\MergeAttendanceVideo::class, 1);

        // javob qaytadan o'zgartirilmaydi
        $this->bot()->hearCallbackQueryData('at:0')->reply();
        $this->assertSame(Attendance::PRESENT, $row->refresh()->status);

        // adminlar uchun xulosa: ism, tuman, vaqt va xarita havolasi
        $report = implode("\n", \App\Telegram\Support\AttendanceReport::chunks(today()));
        $this->assertStringContainsString('Aliyev Ali Aliyevich', $report);
        $this->assertStringContainsString('Yunusobod tumani', $report);
        $this->assertStringContainsString('yandex.com/maps/?pt=69.2,41.3', $report);
        $this->assertStringContainsString('ishda: <b>1</b>', $report);

        // adminga yuboriladi
        Staff::create(['telegram_id' => 888, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'full_name' => 'Admin Test Adminov', 'phone' => '901756545']);
        $this->artisan('attendance:report')->expectsOutputToContain('Yuborilgan xabarlar: 1')->assertSuccessful();
    }

    public function test_attendance_location_checks_gps_accuracy_and_district_distance(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $init = $this->initData(self::UID);

        // past aniqlik — rad
        $this->postJson('/tg/attendance-location', ['initData' => $init, 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 800])->assertStatus(422);
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);

        // biriktirilgan tumandagi obyektlardan ~50 km uzoqda — qabul qilinadi, lekin belgilanadi
        $this->postJson('/tg/attendance-location', ['initData' => $init, 'lat' => 41.75, 'lng' => 69.25, 'accuracy' => 10])->assertOk();
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        $this->assertSame(['far_from_district'], $row->flags);
    }

    public function test_attendance_location_near_site_is_not_flagged(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $this->bot()->hearCallbackQueryData('at:1')->reply();

        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.301, 'lng' => 69.251, 'accuracy' => 10])->assertOk();
        $this->assertNull(Attendance::where('staff_id', $staff->id)->first()->flags);
    }

    public function test_duplicate_attendance_video_is_rejected_without_leaving_files(): void
    {
        Storage::fake('local');
        $webm = "\x1A\x45\xDF\xA3".str_repeat('b', 30000);

        // boshqa xodim shu videoni avval yuborgan
        $other = Staff::create(['telegram_id' => 777, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'full_name' => 'Boshqa Xodim Testov']);
        Attendance::create(['staff_id' => $other->id, 'date' => today(), 'status' => Attendance::PRESENT, 'video_unique_id' => sha1($webm)]);

        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $init = $this->initData(self::UID);
        $this->postJson('/tg/attendance-location', ['initData' => $init, 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 10])->assertOk();

        $this->post('/tg/attendance-video', ['initData' => $init, 'duration' => 8, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        $this->assertNull($row->video_path);
        $this->assertSame([], Storage::disk('local')->allFiles('attendance'));
    }

    public function test_reports_go_to_admins_and_staff_marked_in_panel(): void
    {
        $staff = $this->registerEmployee();
        Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::PRESENT, 'responded_at' => now()]);
        Staff::create(['telegram_id' => 801, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'full_name' => 'Global Admin', 'phone' => '900000101']);
        $marked = Staff::create(['telegram_id' => 802, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'full_name' => 'Nazoratchi Bir', 'phone' => '900000102', 'attendance_required' => true]);
        Staff::create(['telegram_id' => 803, 'status' => Staff::BLOCKED, 'role' => Staff::ROLE_EMPLOYEE, 'full_name' => 'Bloklangan', 'phone' => '900000103', 'receives_reports' => true]);

        $this->assertSame([801], \App\Telegram\Support\AttendanceReport::recipients()->pluck('telegram_id')->all());

        $marked->update(['receives_reports' => true]); // admin panel: «Hisobot oladi»
        $this->assertEqualsCanonicalizing([801, 802], \App\Telegram\Support\AttendanceReport::recipients()->pluck('telegram_id')->all());
        $this->artisan('attendance:report')->expectsOutputToContain('Yuborilgan xabarlar: 2')->assertSuccessful();

        // bosh sahifadagi tugmalar: istalgan payt qo'lda yuborish
        $admin = (new \App\Models\User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Pages\Dashboard::class)
            ->assertActionVisible('sendInspectionReport')
            ->callAction('sendAttendanceReport')->assertNotified('Yuborildi: 2 ta xabar')
            ->callAction('sendInspectionReport')->assertNotified('Yuborildi: 2 ta xabar');

        $viewer = (new \App\Models\User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer);
        \Livewire\Livewire::test(\App\Filament\Pages\Dashboard::class)->assertActionHidden('sendInspectionReport');
    }

    public function test_daily_demolition_report(): void
    {
        $staff = $this->registerEmployee();
        Staff::create(['telegram_id' => 801, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'full_name' => 'Global Admin', 'phone' => '900000101']);
        $other = Site::create(['district_id' => $this->district->id, 'source_no' => 3, 'address' => 'Uchinchi kocha 3']);
        $make = fn (Site $s, array $a) => Inspection::create($a + ['site_id' => $s->id, 'staff_id' => $staff->id, 'status' => Inspection::SUBMITTED, 'step' => 'done', 'submitted_at' => now()]);

        $make($this->site, ['is_demolished' => true, 'review_status' => Inspection::REVIEW_PENDING, 'suspicious' => true]);
        $make($other, ['is_demolished' => false, 'reason' => 'Egasi hujjat olib kelishini aytdi']);
        $make($other, ['is_demolished' => true, 'review_status' => Inspection::REVIEW_REJECTED, 'archived_at' => now()]); // rad etilgan — hisoblanmaydi
        $make($this->site, ['is_demolished' => true, 'review_status' => Inspection::REVIEW_APPROVED, 'submitted_at' => now()->subDay()]); // kecha

        $report = \App\Telegram\Support\InspectionReport::caption(\App\Support\ReportPeriod::current()->day);
        $this->assertStringContainsString('Tekshiruvlar: <b>2</b>', $report);
        $this->assertStringContainsString("Buzilgan: <b>1</b>\n", $report);
        $this->assertStringNotContainsString('Buzilmagan', $report); // qoldiq pastda ko'rsatiladi
        // qisqa: xodim ismlari va manzillarsiz
        $this->assertStringNotContainsString('Aliyev', $report);
        $this->assertStringNotContainsString('Birinchi kocha', $report);
        $this->assertStringContainsString('Umumiy bajarilish: <b>1</b> / 3', $report); // $this->demolished

        $this->artisan('inspections:report')->expectsOutputToContain('Yuborilgan xabarlar: 1')->assertSuccessful();

        // dam olish kuni natija yo'q — yuborilmaydi; ish kunida natija bo'lmasa ham "kiritilmagan" deb yuboriladi
        $this->travelTo(now()->next(\Carbon\Carbon::SUNDAY)->setTime(18, 0));
        $this->artisan('inspections:report')->expectsOutputToContain('yuborilmadi')->assertSuccessful();
        $this->assertStringContainsString('tekshiruv natijasi kiritilmagan', \App\Telegram\Support\InspectionReport::caption(\App\Support\ReportPeriod::current()->day));
    }

    public function test_attendance_report_marks_suspicious_locations(): void
    {
        $staff = $this->registerEmployee();
        Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::PRESENT, 'lat' => 41.75, 'lng' => 69.25,
            'responded_at' => now(), 'flags' => ['far_from_district', 'gps_mock_suspected']]);

        $report = implode("\n", \App\Telegram\Support\AttendanceReport::chunks(today()));
        $this->assertStringContainsString('🚩 shubhali: <b>1</b>', $report);
        $this->assertStringContainsString('Biriktirilgan hududdan uzoqda, Soxta GPS gumoni', $report);
    }

    public function test_attendance_request_is_retried_after_transient_error(): void
    {
        $staff = $this->registerEmployee();

        // Telegram vaqtincha xato qaytardi — so'rov "yuborilmagan" bo'lib qoladi
        $this->bot()->willReceive([], false);
        $this->artisan('attendance:send')->expectsOutputToContain('qayta uriniladi: 1')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->assertNull($row->sent_at);
        $this->assertNull($row->flags);

        // keyingi daqiqada (scheduler) qayta yuboriladi
        $this->artisan('attendance:send')->expectsOutputToContain('Yuborildi: 1')->assertSuccessful();
        $this->assertNotNull($row->refresh()->sent_at);

        // doimiy xatolar (bot bloklangan, chat yo'q) qayta urinilmaydi
        $this->assertTrue(\App\Console\Commands\SendAttendance::isPermanent(new \SergiX44\Nutgram\Telegram\Exceptions\TelegramException('Forbidden: bot was blocked by the user', 403)));
        $this->assertFalse(\App\Console\Commands\SendAttendance::isPermanent(new \SergiX44\Nutgram\Telegram\Exceptions\TelegramException('Too Many Requests', 429)));
        $this->assertFalse(\App\Console\Commands\SendAttendance::isPermanent(new \RuntimeException('cURL error 28')));
    }

    public function test_never_delivered_request_is_closed_as_undeliverable_and_not_sent_after_deadline(): void
    {
        $staff = $this->registerEmployee();
        $row = Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::SENT]);

        $this->travelTo(Attendance::deadline()->addMinute());
        $this->artisan('attendance:send')->expectsOutputToContain('muddati tugagan')->assertSuccessful();
        $this->assertNull($row->refresh()->sent_at);

        $this->artisan('attendance:close')->assertSuccessful();
        $this->assertSame(Attendance::MISSED, $row->refresh()->status);
        $this->assertSame(['undeliverable'], $row->flags);
    }

    public function test_upload_validation_errors_are_in_uzbek(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $init = $this->initData(self::UID);
        $this->postJson('/tg/attendance-location', ['initData' => $init, 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 10])->assertOk();

        $big = UploadedFile::fake()->create('v.webm', 2500, 'video/webm');
        $this->post('/tg/attendance-video', ['initData' => $init, 'duration' => 8, 'video' => $big], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Video hajmi juda katta (1900 KB dan oshmasligi kerak). Videoni qisqaroq yozib, qayta yuboring.');

        $this->postJson('/tg/attendance-location', ['initData' => $init, 'lat' => 'x', 'lng' => 'y'])
            ->assertStatus(422)->assertJsonPath('message', 'Kenglik son bo‘lishi kerak. (yana 1 ta xato)');
    }

    public function test_mini_app_rate_limit_is_per_telegram_user_not_shared_ip(): void
    {
        // bitta IP (mobil operator CGNAT) ortidagi xodim limitni tugatsa ham, boshqa xodim ishlay oladi
        $a = $this->initData(self::UID);
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/tg/attendance-location', ['initData' => $a, 'lat' => 41.3, 'lng' => 69.25])->assertStatus(409);
        }
        $this->postJson('/tg/attendance-location', ['initData' => $a, 'lat' => 41.3, 'lng' => 69.25])->assertStatus(429);

        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID + 1), 'lat' => 41.3, 'lng' => 69.25])->assertStatus(409);
    }

    public function test_convenience_commands(): void
    {
        $staff = $this->registerEmployee();

        $this->bot()->hearText('/yordam')->reply();
        $this->assertStringContainsString('/davomat', $this->sent());

        // so'rov yuborilmagan bo'lsa — tushuntirish; yuborilgan va javobsiz bo'lsa — tugmalar qayta chiqadi
        $this->bot()->hearText('/davomat')->reply();
        $this->assertStringContainsString('yuborilmagan', $this->sent());
        Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::LOCATING, 'sent_at' => now()]);
        $this->bot()->hearText('/davomat')->reply();
        $this->assertStringContainsString('at:1', $this->sent());
        $this->assertSame(Attendance::SENT, Attendance::first()->status);

        // /bekor tugallanmagan tekshiruvni o'chiradi
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->assertSame(1, Inspection::count());
        $this->bot()->hearText('/bekor')->reply();
        $this->assertSame(0, Inspection::count());

        $this->bot()->hearText('/menu')->reply();
        $this->assertStringContainsString('Yunusobod', $this->sent());
    }

    public function test_attendance_absent_requires_reason(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();

        $this->bot()->hearCallbackQueryData('at:0')->reply();
        $this->assertSame(Attendance::REASONING, $row->refresh()->status);

        $this->bot()->hearText('qisqa')->reply();
        $this->assertSame(Attendance::REASONING, $row->refresh()->status);

        $this->bot()->hearText('Kasal bolib qoldim, shifokorga bordim')->reply();
        $this->assertSame(Attendance::ABSENT, $row->refresh()->status);
        $this->assertStringContainsString('shifokorga', $row->reason);
    }

    public function test_attendance_closes_after_deadline(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();

        $this->travelTo(now()->setTime(9, 31));
        $this->bot()->hearCallbackQueryData('at:1')->reply(); // kech qoldi
        $this->assertSame(Attendance::MISSED, $row->refresh()->status);

        // yopish buyrug'i javob bermaganlarni belgilaydi
        $other = Staff::create(['telegram_id' => 777, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'full_name' => 'Boshqa Xodim Test', 'phone' => '900000000']);
        $pending = Attendance::create(['staff_id' => $other->id, 'date' => today(), 'status' => Attendance::SENT, 'sent_at' => now()]);
        $this->artisan('attendance:close')->assertSuccessful();
        $this->assertSame(Attendance::MISSED, $pending->refresh()->status);
    }

    public function test_global_admin_phones_are_not_tracked(): void
    {
        config(['tutash.all_districts_phones' => '+998901756545']);
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();
        $this->bot()->hearText('Admin Adminov Adminovich')->reply();
        $this->bot()->hearMessage(['contact' => ['phone_number' => '+998 90 175 65 45', 'first_name' => 'T', 'user_id' => self::UID]])->reply();

        $this->assertFalse(Staff::where('telegram_id', self::UID)->first()->attendance_required);
        $this->artisan('attendance:send')->assertSuccessful();
        $this->assertSame(0, Attendance::count());
    }

    public function test_script_filter_classic_and_new_alphabet(): void
    {
        $this->assertSame('Mas’ul xodim o‘qiydi, tog‘ ma’lumot', Script::apply("Mas'ul xodim o'qiydi, tog' ma'lumot"));
        $this->assertSame('Ulug‘bek', Script::apply('Ulug&#039;bek'));
        $this->assertSame('<a href="https://x.com/search?q=sh">link</a>', Script::apply('<a href="https://x.com/search?q=sh">link</a>'));

        config(['tutash.alphabet' => 'new']);
        $this->assertSame('Şayxontohur, çilonzor, öqituvçi, Uluğbek', Script::apply("Shayxontohur, chilonzor, o'qituvchi, Ulug'bek"));
        $this->assertSame('<a href="https://x.com/search">Şahar</a>', Script::apply('<a href="https://x.com/search">Shahar</a>'));
    }
}
