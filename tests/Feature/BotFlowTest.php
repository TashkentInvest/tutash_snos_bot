<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Support\MediaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SergiX44\Hydrator\Hydrator;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 111222;

    private District $yunus;
    private District $olmazor;
    private Site $site;
    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['nutgram.token' => '123:TEST']);

        // ffmpeg o'rniga: har chaqiruvda yangi fayl (yoki $fixed bo'lsa — bir xil fayl) yozadi
        $this->app->bind(MediaTool::class, fn () => new class extends MediaTool {
            public static ?string $fixed = null;

            public static bool $black = false;

            public function isBlack(string $file): bool
            {
                return self::$black;
            }

            public function frame(string $video, string $outJpg): bool
            {
                return file_put_contents($outJpg, self::$fixed ?? random_bytes(6000)) !== false;
            }

            public function merge(string $face, string $main, string $out): bool
            {
                return file_put_contents($out, random_bytes(40000)) !== false;
            }
        });

        $this->yunus = District::create(['name' => 'Yunusobod tumani', 'phone' => '999075300']);
        $this->olmazor = District::create(['name' => 'Olmazor tumani', 'phone' => '998783522']);
        $this->site = $this->makeSite($this->yunus, 1);
        $this->foreignSite = $this->makeSite($this->olmazor, 2);
    }

    private function makeSite(District $d, int $no): Site
    {
        $m = Mahalla::create(['district_id' => $d->id, 'name' => "MFY {$no}"]);

        return Site::create([
            'district_id' => $d->id, 'mahalla_id' => $m->id, 'source_no' => $no, 'address' => "Test ko'chasi {$no}",
            'lat' => 41.3, 'lng' => 69.25, 'violator_name' => 'TEST MCHJ', 'violator_passport' => 'AD1234567', 'area_m2' => 100,
        ]);
    }

    private function bot(): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(id: self::UID, is_bot: false, first_name: 'Test'));
        $bot->setCommonChat(Chat::make(id: self::UID, type: 'private'));

        return $bot;
    }

    /** FakeNutgram ichki obyektlar (PhotoSize...) qiymatlarini o'zgartirib yuboradi — shuning uchun to'liq update yig'amiz. */
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

    private function register(string $phone = '+998 99 907 53 00', ?int $contactUserId = self::UID): void
    {
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();
        $this->bot()->hearText('Aliyev Ali Aliyevich')->reply();
        $this->bot()->hearMessage(['contact' => ['phone_number' => $phone, 'first_name' => 'T', 'user_id' => $contactUserId]])->reply();
    }

    public function test_registration_by_known_phone_links_districts(): void
    {
        $this->register();

        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(Staff::ACTIVE, $staff->status);
        $this->assertSame('Aliyev Ali Aliyevich', $staff->full_name);
        $this->assertEquals([$this->yunus->id], $staff->districts->pluck('id')->all());
    }

    public function test_start_offers_permissions_check_to_staff(): void
    {
        $this->register();
        $bot = $this->bot();
        $bot->hearText('/start')->reply();
        $bodies = collect($bot->getRequestHistory())->map(fn ($r) => urldecode((string) $r['request']->getBody()))->implode("\n");
        $this->assertStringContainsString('tg\/permissions', $bodies);

        $this->get('/tg/permissions')->assertOk()->assertSee('Ruxsatlarni tekshirish')->assertSee('/js/tg-location.js', false);
    }

    public function test_one_phone_responsible_for_two_districts(): void
    {
        // bitta raqam ikki tumanga mas'ul bo'lgan holat
        $this->olmazor->update(['phone' => '999075300']);
        $third = District::create(['name' => 'Chilonzor tumani', 'phone' => '901234567']);
        $thirdSite = $this->makeSite($third, 3);

        $this->register();
        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(Staff::ACTIVE, $staff->status);
        $this->assertEqualsCanonicalizing(['Yunusobod tumani', 'Olmazor tumani'], $staff->districts()->pluck('name')->all());
        $this->assertTrue($staff->attendance_required);

        // bosh sahifada ikkala tuman tugmasi chiqadi, uchinchisi yo'q
        $this->bot()->hearText('/menu')->reply();
        $sent = '';
        foreach ($this->bot()->getRequestHistory() as $pair) {
            $sent .= json_encode(json_decode((string) array_values($pair)[0]->getBody(), true), JSON_UNESCAPED_UNICODE);
        }
        $this->assertStringContainsString("d:{$this->yunus->id}", $sent);
        $this->assertStringContainsString("d:{$this->olmazor->id}", $sent);
        $this->assertStringNotContainsString("d:{$third->id}", $sent);

        // ikkala tumandagi obyekt bo'yicha tekshiruv boshlanadi, begona tumanda — yo'q
        $this->bot()->hearCallbackQueryData("go:{$this->foreignSite->id}")->reply(); // Olmazor
        $this->assertSame($this->foreignSite->id, Inspection::where('status', Inspection::DRAFT)->value('site_id'));
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply(); // Yunusobod (eski qoralama almashadi)
        $this->assertSame($this->site->id, Inspection::where('status', Inspection::DRAFT)->value('site_id'));
        $this->bot()->hearCallbackQueryData("go:{$thirdSite->id}")->reply(); // Chilonzor — ruxsat yo'q
        $this->assertSame($this->site->id, Inspection::where('status', Inspection::DRAFT)->value('site_id'));

        // davomatdagi "hududdan uzoq" tekshiruvi ikkala tuman obyektlarini hisobga oladi
        $this->assertSame(0, \App\Support\Gps::nearestSiteDistance($staff, 41.3, 69.25));
    }

    public function test_changing_district_phone_relinks_registered_staff(): void
    {
        $this->register(); // 999075300 — Yunusobod
        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(['Yunusobod tumani'], $staff->districts()->pluck('name')->all());

        // ikkinchi tuman ham shu xodimga berildi
        $this->olmazor->update(['phone' => '999075300']);
        $this->assertEqualsCanonicalizing(['Yunusobod tumani', 'Olmazor tumani'], $staff->districts()->pluck('name')->all());

        // birinchi tumanning mas'uli almashdi — eski xodimdan olib tashlanadi
        $this->yunus->update(['phone' => '901112233']);
        $this->assertSame(['Olmazor tumani'], $staff->districts()->pluck('name')->all());
    }

    public function test_global_phone_sees_all_districts(): void
    {
        config(['tutash.all_districts_phones' => '+998901756545, +998997906699']);
        $this->register('+998 90 175 65 45');

        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(Staff::ACTIVE, $staff->status);
        $this->assertTrue($staff->is_global);
        $this->assertTrue($staff->canAccessDistrict($this->yunus->id));
        $this->assertTrue($staff->canAccessDistrict($this->olmazor->id));
        $this->assertSame(2, $staff->accessibleDistricts()->count());
    }

    public function test_unknown_phone_is_rejected(): void
    {
        $this->register('+998 90 000 00 00');

        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertNotSame(Staff::ACTIVE, $staff->status);
        $this->assertCount(0, $staff->districts);
    }

    public function test_foreign_contact_is_rejected(): void
    {
        $this->register('+998 99 907 53 00', 999);

        $this->assertNotSame(Staff::ACTIVE, Staff::where('telegram_id', self::UID)->first()->status);
    }

    public function test_cannot_open_site_of_foreign_district(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("s:{$this->foreignSite->id}")->reply();
        $this->bot()->hearCallbackQueryData("go:{$this->foreignSite->id}")->reply();

        $this->assertSame(0, Inspection::count());
    }

    private function initData(): string
    {
        $data = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => self::UID])];
        ksort($data);
        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode(chr(10));
        $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', '123:TEST', 'WebAppData', true));

        return http_build_query($data);
    }

    private function app(string $path, array $data = [])
    {
        return $this->post("/tg/inspection/{$path}", ['initData' => $this->initData()] + $data, ['Accept' => 'application/json']);
    }

    private function webm(int $size = 30000): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('v.webm', "\x1A\x45\xDF\xA3".random_bytes($size));
    }

    public function test_full_demolition_flow_and_idempotent_submit(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $this->assertSame('location', $in->step);

        // chatga yuborilgan joylashuv, rasm va video qabul qilinmaydi
        $this->bot()->hearMessage(['location' => ['latitude' => 41.3001, 'longitude' => 69.2501, 'horizontal_accuracy' => 5]])->reply();
        $this->hear(['photo' => [['file_id' => 'f1', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90]]]);
        $this->hear(['video_note' => ['file_id' => 'v1', 'file_unique_id' => 'uv1', 'length' => 240, 'duration' => 8]]);
        $this->assertSame('location', $in->refresh()->step);
        $this->assertSame(0, $in->media()->count());

        // imzosiz so'rov rad etiladi
        $this->postJson('/tg/inspection/state', ['initData' => 'user=%7B%22id%22%3A1%7D&auth_date='.time().'&hash=bad'])->assertStatus(403);

        $this->app('state')->assertOk()->assertJsonPath('step', 'location');
        $this->app('photo', ['clip' => $this->webm()])->assertStatus(409); // bosqich emas

        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 6])->assertOk()->assertJsonPath('step', 'before_photo');
        $this->assertFalse($in->refresh()->suspicious);

        $this->app('photo', ['clip' => $this->webm()])->assertOk()->assertJsonPath('step', 'before_video');
        $this->app('skip')->assertOk()->assertJsonPath('step', 'demolished');
        $this->app('demolished', ['value' => '1'])->assertOk()->assertJsonPath('step', 'after_photo');

        // avvalgi rasmning o'zi qayta yuborilsa — rad (bir xil fayl)
        $first = $in->mediaOf(\App\Models\InspectionMedia::BEFORE_PHOTO) ?? $in->refresh()->load('media')->mediaOf(\App\Models\InspectionMedia::BEFORE_PHOTO);
        $class = get_class(app(MediaTool::class));
        $class::$fixed = file_get_contents(Storage::disk('local')->path($first->path));
        $this->app('photo', ['clip' => $this->webm()])->assertStatus(422);
        $this->assertSame('after_photo', $in->refresh()->step);
        $this->assertTrue($in->suspicious);
        $class::$fixed = null;

        $this->app('photo', ['clip' => $this->webm()])->assertOk()->assertJsonPath('step', 'after_video');

        // qisqa video va noto'g'ri format rad etiladi
        $this->app('video', ['video' => $this->webm(), 'duration' => 1.2])->assertStatus(422);
        $this->app('video', ['video' => UploadedFile::fake()->createWithContent('v.webm', 'not a video'), 'duration' => 8])->assertStatus(422);
        $this->assertSame('after_video', $in->refresh()->step);

        $this->app('video', ['video' => $this->webm(), 'face' => $this->webm(8000), 'duration' => 8])->assertOk()->assertJsonPath('step', 'confirm');

        $this->app('submit')->assertOk();
        $this->app('submit')->assertStatus(409); // qo'sh bosish

        $this->assertSame(Inspection::SUBMITTED, $in->refresh()->status);
        $this->assertSame(Inspection::REVIEW_PENDING, $in->review_status);
        $this->assertSame(Site::NOT_DEMOLISHED, $this->site->refresh()->status); // moderator tasdiqlamaguncha hisoblanmaydi
        $card = \App\Telegram\Support\Ui::siteCard(Site::with(['mahalla', 'pendingReview'])->find($this->site->id));
        $this->assertStringContainsString('Ko‘rib chiqilmoqda', $card);
        $this->assertStringNotContainsString('Buzilmagan', $card);
        $this->assertSame(3, $in->media()->count());

        // tasdiq kutilayotgan obyekt bo'yicha yangi tekshiruv boshlab bo'lmaydi
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->assertSame(0, Inspection::where('status', Inspection::DRAFT)->count());

        // moderator tasdiqlaydi -> obyekt buzilgan, qayta tasdiqlab bo'lmaydi
        $this->assertTrue(\App\Support\InspectionReview::approve($in, null));
        $this->assertFalse(\App\Support\InspectionReview::approve($in, null));
        $this->assertSame(Site::DEMOLISHED, $this->site->refresh()->status);
        $this->assertNotNull($this->site->demolished_at);
        $this->assertSame(Inspection::REVIEW_APPROVED, $in->refresh()->review_status);
        $in->media->each(fn ($m) => Storage::disk('local')->assertExists($m->path));
    }

    public function test_not_demolished_requires_reason(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();

        // obyekt hududidan uzoqda — rad etiladi (radius ichida bo'lish shart)
        config(['tutash.strict_distance' => true]);
        $this->app('location', ['lat' => 41.5, 'lng' => 69.9])->assertStatus(422)
            ->assertJsonPath('radius', 300)->assertJson(fn ($j) => $j->has('distance')->where('ok', false)->etc());
        $this->assertSame('location', $in->refresh()->step);
        $this->assertNull($in->staff_lat);

        // qat'iy rejim o'chirilsa — ruxsat, lekin tekshiruv "shubhali" deb belgilanadi
        config(['tutash.strict_distance' => false]);
        $this->app('location', ['lat' => 41.5, 'lng' => 69.9])->assertOk();
        $this->assertTrue($in->refresh()->suspicious);

        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '0'])->assertOk()->assertJsonPath('step', 'reason');

        $this->app('reason', ['reason' => 'qisqa'])->assertStatus(422);
        $this->assertSame('reason', $in->refresh()->step);

        $this->app('reason', ['reason' => 'Obyekt hali buzilmagan, qurilish davom etmoqda'])->assertOk()->assertJsonPath('step', 'confirm');
        $this->app('submit')->assertOk();

        $this->assertSame(Site::NOT_DEMOLISHED, $this->site->refresh()->status);
        $this->assertNotNull($this->site->last_inspected_at);
        $this->assertSame(Inspection::SUBMITTED, $in->refresh()->status);
    }

    public function test_court_cancelled_needs_documents_and_approval_removes_site_from_total(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $totalBefore = Site::countable()->count();

        $this->app('location', ['lat' => 41.3, 'lng' => 69.25])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '3'])->assertOk()->assertJsonPath('step', 'issue');
        $this->app('issue', ['type' => 'court_cancelled', 'reason' => 'Mirzo Ulug‘bek tumanlararo sudi, 08.01.2025'])
            ->assertOk()->assertJsonPath('step', 'documents');

        // hujjatsiz davom etib bo'lmaydi; faqat rasm/PDF qabul qilinadi
        $this->app('documents/done')->assertStatus(422);
        $this->app('document', ['file' => UploadedFile::fake()->createWithContent('x.txt', str_repeat('a', 5000))])->assertStatus(422);
        $pdf = UploadedFile::fake()->createWithContent('q.pdf', "%PDF-1.4\n".random_bytes(6000));
        $this->app('document', ['file' => $pdf])->assertOk()->assertJsonCount(1, 'documents');
        $jpg = UploadedFile::fake()->image('p.jpg', 800, 1100);
        $this->app('document', ['file' => $jpg])->assertOk()->assertJsonCount(2, 'documents');
        $this->app('document/remove', ['kind' => 'document_2'])->assertOk()->assertJsonCount(1, 'documents');
        $this->app('documents/done')->assertOk()->assertJsonPath('step', 'confirm');
        $this->app('submit')->assertOk();

        $in->refresh();
        $this->assertSame(Inspection::REVIEW_PENDING, $in->review_status);
        $this->assertSame(Site::NOT_DEMOLISHED, $this->site->refresh()->status); // tasdiqqacha o'zgarmaydi
        $doc = $in->documents()->first();
        Storage::disk(config('tutash.media_disk'))->assertExists($doc->path);
        $admin = (new \App\Models\User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get("/admin/inspections/{$in->id}")->assertOk()
            ->assertSee('Sud qarori bekor')->assertSee('PDF faylni ochish')->assertSee('Tasdiqlash');

        $this->assertTrue(\App\Support\InspectionReview::approve($in, null));
        $this->assertSame(Site::EXEMPT, $this->site->refresh()->status);
        $this->assertSame($totalBefore - 1, Site::countable()->count());
        $this->assertFalse(Site::pending()->whereKey($this->site->id)->exists());
        $this->assertStringContainsString('sud qarori bekor', implode("\n", \App\Telegram\Support\InspectionReport::chunks(today())));
    }

    public function test_other_issue_is_text_only_and_keeps_site_in_work_list(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();

        $this->app('location', ['lat' => 41.3, 'lng' => 69.25])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '3'])->assertOk();
        $this->app('issue', ['type' => 'other', 'reason' => 'Obyekt egasi chet elda, eshik yopiq'])->assertOk()->assertJsonPath('step', 'confirm');
        $this->app('submit')->assertOk();

        $in->refresh();
        $this->assertNull($in->review_status);
        $this->assertSame(Inspection::EXEMPT_OTHER, $in->exempt_type);
        $this->assertTrue(Site::pending()->whereKey($this->site->id)->exists());
    }

    public function test_in_progress_result_marks_site_and_keeps_it_in_work_list(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();

        $this->app('location', ['lat' => 41.3, 'lng' => 69.2])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '2'])->assertOk()->assertJsonPath('step', 'reason')->assertJsonPath('in_progress', true);
        $this->app('reason', ['reason' => '70% buzilgan, tadbirkor o‘zi buzmoqda'])->assertOk()->assertJsonPath('step', 'confirm');
        $this->app('submit')->assertOk();

        $in->refresh();
        $this->assertFalse($in->is_demolished);
        $this->assertTrue($in->in_progress);
        $this->assertNull($in->review_status);
        $this->assertSame(Site::IN_PROGRESS, $this->site->refresh()->status);
        $this->assertTrue(Site::pending()->whereKey($this->site->id)->exists());
        $this->assertStringContainsString('Buzish jarayonida', implode("\n", \App\Telegram\Support\InspectionReport::chunks(today())));
    }

    public function test_black_media_is_rejected_and_device_is_recorded(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $device = json_encode(['ua' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A) AppleWebKit', 'tg' => ['p' => 'android', 'v' => '11.4'], 'cam' => ['w' => 480, 'h' => 480, 'f' => 'user']]);

        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'device' => $device])->assertOk();
        $this->assertSame($device, $in->refresh()->device);

        $class = get_class(app(MediaTool::class));
        $class::$black = true;
        $this->app('photo', ['clip' => $this->webm(), 'device' => $device])->assertStatus(422);
        $this->assertSame('before_photo', $in->refresh()->step);
        $this->assertSame(0, $in->media()->count());
        $this->assertSame('Android · SM-S918B · Telegram android 11.4 · kamera 480x480 (user)', \App\Support\Device::label($in->device));

        $class::$black = false;
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->assertSame(1, $in->media()->count());
    }

    public function test_rejected_demolition_keeps_site_and_allows_retry(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '1'])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('video', ['video' => $this->webm(), 'duration' => 8])->assertOk();
        $this->app('submit')->assertOk();
        $this->assertSame(Inspection::REVIEW_PENDING, $in->refresh()->review_status);

        $this->assertTrue(\App\Support\InspectionReview::reject($in, null, 'Rasmda obyekt aniq ko‘rinmayapti'));
        $this->assertSame(Inspection::REVIEW_REJECTED, $in->refresh()->review_status);
        $this->assertSame(Site::NOT_DEMOLISHED, $this->site->refresh()->status);
        $this->assertNotNull($in->refresh()->archived_at); // arxivlandi
        $this->assertNull($this->site->last_inspected_at); // obyekt sahifasi bo'shadi

        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->assertSame(1, Inspection::where('status', Inspection::DRAFT)->count());
    }

    public function test_inspection_can_be_cancelled_from_app(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $this->app('cancel')->assertOk();
        $this->assertSame(0, Inspection::count());
        $this->app('state')->assertStatus(409);
    }

    public function test_low_gps_accuracy_is_rejected_and_mock_gps_is_flagged(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();

        // aniqlik chegaradan yomon — rad, bosqich o'zgarmaydi
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 900])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertSame('location', $in->refresh()->step);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inspection.low_gps_accuracy']);

        // aynan 0 m — soxta GPS gumoni: qabul qilinadi, lekin shubhali deb belgilanadi
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 0])->assertOk()->assertJsonPath('step', 'before_photo');
        $this->assertTrue($in->refresh()->suspicious);
        $this->assertSame(['gps_mock_suspected'], $in->flags);
    }

    public function test_reused_file_is_rejected_without_leaving_files_on_disk(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 6])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('skip')->assertOk();
        $this->app('demolished', ['value' => '1'])->assertOk();

        $first = $in->refresh()->load('media')->mediaOf(\App\Models\InspectionMedia::BEFORE_PHOTO);
        $class = get_class(app(MediaTool::class));
        $class::$fixed = Storage::disk('local')->get($first->path);
        $this->app('photo', ['clip' => $this->webm()])->assertStatus(422);
        $class::$fixed = null;

        // diskda faqat bazadagi yozuvga mos fayl qoladi
        $this->assertSame([$first->path], Storage::disk('local')->files("inspections/{$in->id}"));
        $this->assertSame(1, $in->media()->count());
    }

    public function test_inspection_video_is_merged_in_queue_and_reuse_is_detected_on_raw_clip(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 6])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();

        $raw = "\x1A\x45\xDF\xA3".random_bytes(30000);
        $clip = fn () => UploadedFile::fake()->createWithContent('v.webm', $raw);
        $this->app('video', ['video' => $clip(), 'face' => $this->webm(8000), 'duration' => 8])->assertOk()->assertJsonPath('step', 'demolished');

        // birlashtirish so'rov ichida emas, navbatda
        $media = $in->refresh()->load('media')->mediaOf(\App\Models\InspectionMedia::BEFORE_VIDEO);
        Storage::disk('local')->assertExists($media->path);
        $facePath = null;
        Queue::assertPushed(\App\Jobs\MergeInspectionVideo::class, function ($job) use ($media, &$facePath) {
            $facePath = $job->facePath;

            return $job->mediaId === $media->id;
        });
        Storage::disk('local')->assertExists($facePath);

        // xuddi shu xom lavha "keyingi holat" videosi sifatida yuborilsa — rad (hash qayta kodlanmagan fayldan)
        $this->app('demolished', ['value' => '1'])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->app('video', ['video' => $clip(), 'duration' => 8])->assertStatus(422);
        $this->assertSame('after_video', $in->refresh()->step);

        // job: birlashtirilgan fayl asosiy lavha va yuz lavhasi o'rnini egallaydi
        $original = $media->path;
        (new \App\Jobs\MergeInspectionVideo($media->id, $facePath))->handle(app(MediaTool::class));
        $media->refresh();
        $this->assertStringEndsWith('_merged.mp4', $media->path);
        $this->assertSame('video/mp4', $media->mime);
        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertMissing([$original, $facePath]);
    }

    public function test_merge_job_cleans_face_clip_when_inspection_is_gone(): void
    {
        Storage::disk('local')->put('inspections/5/before_video_x_face.webm', 'face');
        (new \App\Jobs\MergeInspectionVideo(123456, 'inspections/5/before_video_x_face.webm'))->handle(app(MediaTool::class));
        Storage::disk('local')->assertMissing('inspections/5/before_video_x_face.webm');
    }

    public function test_deleted_drafts_remove_their_files_and_prune_cleans_orphans(): void
    {
        $this->register();
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $in = Inspection::first();
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 6])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        $this->assertNotEmpty(Storage::disk('local')->files("inspections/{$in->id}"));

        // bot orqali bekor qilish ham fayllarni o'chiradi
        $this->bot()->hearCallbackQueryData('cancel')->reply();
        $this->assertSame(0, Inspection::count());
        $this->assertFalse(Storage::disk('local')->directoryExists("inspections/{$in->id}"));

        // eskirgan qoralama + avvaldan qolgan yetim papka
        $this->bot()->hearCallbackQueryData("go:{$this->site->id}")->reply();
        $old = Inspection::first();
        $this->app('location', ['lat' => 41.3001, 'lng' => 69.2501, 'accuracy' => 6])->assertOk();
        $this->app('photo', ['clip' => $this->webm()])->assertOk();
        Inspection::whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);
        Storage::disk('local')->put('inspections/999999/before_photo.jpg', 'x');

        $this->artisan('inspections:prune-drafts')->expectsOutputToContain('Yetim papkalar: 1')->assertSuccessful();
        $this->assertSame(0, Inspection::count());
        $this->assertSame([], Storage::disk('local')->directories('inspections'));
    }

    public function test_csv_import_is_idempotent(): void
    {
        if (! is_file(base_path('dataset.csv'))) {
            $this->markTestSkipped('dataset.csv repoda yo‘q (shaxsiy ma’lumotlar) — test faqat fayl mavjud muhitda ishlaydi.');
        }
        $this->seed(\Database\Seeders\DistrictSeeder::class);
        $this->artisan('sites:import', ['file' => 'dataset.csv'])->assertSuccessful();
        $count = Site::count();
        $this->assertGreaterThan(800, $count);
        $this->artisan('sites:import', ['file' => 'dataset.csv'])->assertSuccessful();
        $this->assertSame($count, Site::count());
    }

    public function test_fuzzy_search_tolerates_apostrophes_cyrillic_and_typos(): void
    {
        $this->site->update(['address' => "Sh.Rustaveli ko'chasi 1-tor"]);
        $hay = \App\Telegram\Support\Fuzzy::normalize($this->site->address.' '.$this->site->violator_name);

        foreach (['rustaveli', 'rustavelli', 'Руставели', "ko`chasi", 'kochasi', 'rustvli'] as $q) {
            $this->assertGreaterThan(0, \App\Telegram\Support\Fuzzy::score(\App\Telegram\Support\Fuzzy::normalize($q), $hay), $q);
        }
        $this->assertSame(0.0, \App\Telegram\Support\Fuzzy::score(\App\Telegram\Support\Fuzzy::normalize('olmazor'), $hay));

        $this->register();
        $this->bot()->hearCallbackQueryData("q:{$this->yunus->id}")->reply();
        $this->bot()->hearText('rustavelli')->reply()->assertReplyText("Topildi (1): <b>Sh.Rustaveli ko‘chasi 1-tor</b>", 0);
    }
}
