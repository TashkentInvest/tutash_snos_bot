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

    /** Ikkinchi vazifa: /start da soatlik qayd holati va javob kutilayotgan bo'lsa — Mini App tugmasi. */
    public function test_start_shows_hourly_checkin_for_employee_with_second_duty(): void
    {
        $staff = $this->registerEmployee();
        $this->assertSame(Staff::ACTIVE, $staff->status);

        // belgisiz: bitta tuman — to'g'ridan-to'g'ri MFY ro'yxati, soatlik qayd yo'q
        $this->bot()->hearText('/start')->reply();
        $this->assertStringNotContainsString('Soatlik qayd', $this->sent());

        $staff->update(['hourly_checkin' => true]);
        $this->bot()->hearText('/start')->reply();
        $out = $this->sent();
        $this->assertStringContainsString('Soatlik qayd', $out);
        $this->assertStringContainsString('Yunusobod tumani', $out); // tuman tugmalari saqlanadi
        $this->assertStringNotContainsString('/tg/checkin', $out); // javob kutilayotgan so'rov yo'q

        \App\Models\Checkin::create(['staff_id' => $staff->id, 'date' => today()->toDateString(), 'slot' => '10:00', 'status' => \App\Models\Checkin::SENT,
            'sent_at' => now(), 'deadline_at' => now()->addMinutes(30)]);
        $this->bot()->hearText('/start')->reply();
        $out = $this->sent();
        $this->assertStringContainsString('Soatlik qayd: joylashuv va video', $out);
        $this->assertStringContainsString('/tg/checkin', $out);
        $this->assertStringContainsString('10:00', $out);
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

        // aholi qayta /start bosganda ro'yxatdan o'tish qayta so'ralmaydi — to'g'ridan-to'g'ri tumanlar
        $this->bot()->hearText('/start')->reply();
        $this->assertStringNotContainsString('kirish turini tanlang', $this->sent());
        $this->assertStringContainsString('Yunusobod tumani', $this->sent());
        $this->assertStringContainsString('Men xodimman', $this->sent()); // buyruqsiz xodim sifatida kirish yo'li
        $this->assertSame(Staff::ROLE_CITIZEN, $staff->refresh()->role);

        // oddiy matn ham "/start yuboring" emas, bosh sahifani ko'rsatadi
        $this->bot()->hearText('salom')->reply();
        $this->assertStringNotContainsString('/start', $this->sent());

        // kirish turini o'zgartirish — faqat /rol bilan
        $this->bot()->hearText('/rol')->reply();
        $this->assertStringContainsString('role:employee', $this->sent());
    }

    /** Shartnoma xodimi davomat tugmasini bosganda va sabab yozganda "/start" so'ralmasligi kerak. */
    public function test_contract_staff_can_answer_attendance_without_restart(): void
    {
        $staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Shartnoma Xodim', 'phone' => '950507174', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_CONTRACT, 'attendance_required' => true]);
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->assertNotNull($row);

        $this->bot()->hearCallbackQueryData('at:0')->reply();
        $this->assertStringNotContainsString('/start', $this->sent());
        $this->assertSame(Attendance::REASONING, $row->refresh()->status);

        $this->bot()->hearText('Kasal bolib qoldim, shifokorga bordim')->reply();
        $this->assertStringNotContainsString('/start', $this->sent());
        $this->assertSame(Attendance::ABSENT, $row->refresh()->status);

        // ertasiga "Ha" — joylashuv tugmasi chiqadi
        $this->travelTo(now()->addDay()->setTime(8, 30));
        $this->artisan('attendance:send')->assertSuccessful();
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $this->assertSame(Attendance::LOCATING, Attendance::where('staff_id', $staff->id)->whereDate('date', today())->value('status'));
        $this->assertStringContainsString('attendance-location', $this->sent());

        // davomatdan tashqari matn — bosh sahifa, "/start yuboring" emas; /start ham ruxsat tugmasini faqat birinchi marta yuboradi
        $this->bot()->hearText('/start')->reply();
        $this->assertStringContainsString('Ruxsatlarni tekshirish', $this->sent());
        $this->bot()->hearText('/start')->reply();
        $this->assertStringNotContainsString('Ruxsatlarni tekshirish', $this->sent());
        $this->assertStringContainsString('shartnoma asosida', $this->sent());
    }

    /** Bot xatosi tufayli "javob bermadi" bo'lganlarga davomat qayta ochiladi: o'z muddati bilan, uzr matni bilan. */
    public function test_reopened_attendance_accepts_answers_until_its_own_deadline(): void
    {
        $staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Xayit Test', 'phone' => '950507174', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_CONTRACT, 'attendance_required' => true]);
        $other = Staff::create(['telegram_id' => 777100, 'full_name' => 'Javob bergan', 'phone' => '900000100', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $this->artisan('attendance:send')->assertSuccessful();
        Attendance::where('staff_id', $other->id)->update(['status' => Attendance::PRESENT]);
        $this->travelTo(today()->setTime(9, 3));
        $this->artisan('attendance:close')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->assertSame(Attendance::MISSED, $row->status);

        // 09:40 — qayta ochiladi (faqat javob bermagan shartnoma xodimi), 60 daqiqa
        $this->travelTo(today()->setTime(9, 40));
        $this->artisan('attendance:reopen --missed-contract --minutes=60')->expectsOutputToContain('yuborildi: 1')->assertSuccessful();
        $this->assertStringContainsString('texnik kamchilik', $this->sent());
        $this->assertSame(Attendance::SENT, $row->refresh()->status);
        $this->assertSame('10:40', $row->deadline_at->format('H:i'));
        $this->assertSame(Attendance::PRESENT, Attendance::where('staff_id', $other->id)->value('status'));

        // 09:45 — "Ha" qabul qilinadi (umumiy 09:00 muddati o'tgan bo'lsa ham), joylashuv ham
        $this->travelTo(today()->setTime(9, 45));
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 10])->assertOk();
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        $this->artisan('attendance:close --reopened')->assertSuccessful();
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status); // hali muddati bor

        // 10:44 — o'z muddati (+3 daqiqa) o'tdi: yopiladi
        $this->travelTo(today()->setTime(10, 44));
        $this->artisan('attendance:close --reopened')->assertSuccessful();
        $this->assertSame(Attendance::MISSED, $row->refresh()->status);
    }

    /** Mini App yig'ib qo'yilib, keyin qaytilganda (initData 1 soat oldin imzolangan) — qayta "tugmani bosing" demasligi kerak. */
    public function test_mini_app_accepts_initdata_signed_hours_ago_but_not_days(): void
    {
        $sign = function (int $authDate) {
            $data = ['auth_date' => (string) $authDate, 'query_id' => 'AAA', 'user' => json_encode(['id' => self::UID])];
            ksort($data);
            $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
            $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', (string) config('nutgram.token'), 'WebAppData', true));

            return http_build_query($data);
        };
        $this->assertSame(['id' => self::UID], \App\Telegram\Support\WebAppAuth::user($sign(time() - 3600)));
        $this->assertNull(\App\Telegram\Support\WebAppAuth::user($sign(time() - 2 * 86400)));
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

    /** Audit: muddat chegarasida video, telefon bosqichi, shartnoma xodimining eski tugmalari, qoralama + qidiruv. */
    public function test_video_after_deadline_is_accepted_if_location_was_sent_in_time(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $row = Attendance::where('staff_id', $staff->id)->first();
        $this->bot()->hearCallbackQueryData('at:1')->reply();

        $this->travelTo(today()->setTime(8, 59, 30));
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 10])->assertOk();

        // 09:01 — muddat o'tdi, lekin video yozilayotgan edi: qabul qilinadi; boshqa xabar ham uni yopib qo'ymaydi
        $this->travelTo(today()->setTime(9, 1));
        $this->bot()->hearText('salom')->reply();
        $this->assertSame(Attendance::RECORDING, $row->refresh()->status);
        Storage::fake('local');
        $webm = "\x1A\x45\xDF\xA3".str_repeat('g', 30000);
        $this->post('/tg/attendance-video', ['initData' => $this->initData(self::UID), 'duration' => 6, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm)],
            ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(Attendance::PRESENT, $row->refresh()->status);
        // qayta yuborish (javob yo'qolgan) — xato emas
        $this->post('/tg/attendance-video', ['initData' => $this->initData(self::UID), 'duration' => 6, 'video' => UploadedFile::fake()->createWithContent('v.webm', $webm.'x')],
            ['Accept' => 'application/json'])->assertOk();
    }

    public function test_video_is_refused_after_grace_minutes(): void
    {
        $staff = $this->registerEmployee();
        $this->artisan('attendance:send')->assertSuccessful();
        $this->bot()->hearCallbackQueryData('at:1')->reply();
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.3, 'lng' => 69.25, 'accuracy' => 10])->assertOk();

        $this->travelTo(today()->setTime(9, 4));
        Storage::fake('local');
        $this->post('/tg/attendance-video', ['initData' => $this->initData(self::UID), 'duration' => 6,
            'video' => UploadedFile::fake()->createWithContent('v.webm', "\x1A\x45\xDF\xA3".str_repeat('h', 30000))], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame(Attendance::MISSED, Attendance::where('staff_id', $staff->id)->value('status'));
    }

    public function test_typed_phone_number_gets_contact_button_not_restart(): void
    {
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();
        $this->bot()->hearText('Aliyev Ali Aliyevich')->reply();
        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(Staff::AWAITING_PHONE, $staff->status);

        $this->bot()->hearText('+998 99 907 53 00')->reply();
        $this->assertStringContainsString('request_contact', $this->sent());
        $this->assertStringNotContainsString('/start', $this->sent());
        $this->assertSame(Staff::AWAITING_PHONE, $staff->refresh()->status);
        $this->assertSame('Aliyev Ali Aliyevich', $staff->full_name);
    }

    public function test_contract_staff_old_site_button_shows_own_home(): void
    {
        Staff::create(['telegram_id' => self::UID, 'full_name' => 'Sobiq xodim', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $this->bot()->hearCallbackQueryData("d:{$this->district->id}")->reply();
        $this->assertStringNotContainsString('/start', $this->sent());
        $this->assertStringContainsString('shartnoma asosida', $this->sent());
    }

    public function test_search_works_with_an_abandoned_inspection_draft(): void
    {
        $this->registerEmployee();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->bot()->hearCallbackQueryData("gk:{$this->site->id}:check")->reply();
        $this->assertSame(1, Inspection::where('status', Inspection::DRAFT)->count());

        $this->bot()->hearCallbackQueryData("q:{$this->district->id}")->reply();
        $this->bot()->hearText('Birinchi')->reply();
        $this->assertStringContainsString('Birinchi kocha', $this->sent());
    }

    public function test_employee_without_districts_gets_explanation(): void
    {
        $staff = $this->registerEmployee();
        $this->district->update(['phone' => '900000099']); // tuman telefoni o'zgardi — xodim tumansiz qoldi
        $this->assertSame(0, $staff->refresh()->districts()->count());

        $this->bot()->hearText('/start')->reply();
        $this->assertStringContainsString('hudud biriktirilmagan', $this->sent());
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
        // qayta yuborish (javob tarmoqda yo'qolgan): xato emas, video bosqichiga o'tadi, birinchi joylashuv o'zgarmaydi
        $this->postJson('/tg/attendance-location', ['initData' => $this->initData(self::UID), 'lat' => 41.4, 'lng' => 69.3])->assertOk()->assertJsonPath('step', 'video');
        $this->assertEqualsWithDelta(41.3, $row->refresh()->lat, 0.0001);

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
        Staff::create(['telegram_id' => 888, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'receives_reports' => true, 'full_name' => 'Admin Test Adminov', 'phone' => '901756545']);
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

    public function test_reports_go_only_to_staff_marked_in_panel(): void
    {
        $staff = $this->registerEmployee();
        Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::PRESENT, 'responded_at' => now()]);
        Staff::create(['telegram_id' => 801, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'receives_reports' => true, 'full_name' => 'Global Admin', 'phone' => '900000101']);
        // admin, lekin «Hisobot oladi» belgisi yo'q — hisobot bormaydi
        Staff::create(['telegram_id' => 804, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'full_name' => 'Kuzatuvchi Admin', 'phone' => '949220555']);
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
        Staff::create(['telegram_id' => 801, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'receives_reports' => true, 'full_name' => 'Global Admin', 'phone' => '900000101']);
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

    public function test_attendance_request_warns_and_unanswered_get_reminder_before_deadline(): void
    {
        $staff = $this->registerEmployee();
        $other = Staff::create(['telegram_id' => 902, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'full_name' => 'Javob berdi', 'phone' => '900000902']);
        $this->travelTo(today()->setTime(8, 0));
        $this->artisan('attendance:send')->assertSuccessful();
        $this->assertStringContainsString('javob bermasangiz', $this->sent()); // so'rovning o'zida ogohlantirish
        Attendance::where('staff_id', $other->id)->update(['status' => Attendance::PRESENT, 'responded_at' => now()]);

        // 08:45 — faqat javob bermaganga, tugmalar bilan
        $this->travelTo(today()->setTime(8, 45));
        $bot = $this->bot();
        $before = count($bot->getRequestHistory());
        $this->artisan('attendance:remind')->expectsOutputToContain('Eslatma yuborildi: 1')->assertSuccessful();
        $req = collect($bot->getRequestHistory())->slice($before)->map(fn ($r) => urldecode((string) array_values($r)[0]->getBody()))->implode("\n");
        $this->assertStringContainsString('15 daqiqa qoldi', $req);
        $this->assertStringContainsString('at:1', $req);
        $this->assertStringContainsString((string) $staff->telegram_id, $req);
        $this->assertStringNotContainsString('"chat_id":902', $req);

        // muddatdan keyin — eslatma yuborilmaydi
        $this->travelTo(Attendance::deadline()->addMinute());
        $this->artisan('attendance:remind')->expectsOutputToContain('muddati tugagan')->assertSuccessful();
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

        // so'rov yuborilmagan bo'lsa — tushuntirish; boshlangan jarayon — shu joyidan davom etadi (boshidan emas)
        $this->bot()->hearText('/davomat')->reply();
        $this->assertStringContainsString('yuborilmagan', $this->sent());
        $row = Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => Attendance::LOCATING, 'sent_at' => now()]);
        $this->bot()->hearText('/davomat')->reply();
        $this->assertStringContainsString('attendance-location', $this->sent());
        $this->assertSame(Attendance::LOCATING, $row->refresh()->status);
        // javob berilmagan — tugmalar qayta chiqadi
        $row->update(['status' => Attendance::SENT]);
        $this->bot()->hearText('/davomat')->reply();
        $this->assertStringContainsString('at:1', $this->sent());

        // /bekor tugallanmagan tekshiruvni o'chiradi
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->bot()->hearCallbackQueryData("gk:{$this->site->id}:check")->reply();
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
