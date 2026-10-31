<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Site;
use App\Models\Staff;
use App\Support\Svod;
use App\Telegram\Support\InspectionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

class ReportSvodTest extends TestCase
{
    use RefreshDatabase;

    private District $bektemir;

    private District $chilonzor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nutgram.token' => '123:TEST']);
        $this->travelTo(today()->setTime(12, 0)); // hisobot davri ichida (18:00 gacha)
        $this->bektemir = District::create(['name' => 'Bektemir tumani', 'phone' => '942778855']);
        $this->chilonzor = District::create(['name' => 'Chilonzor tumani', 'phone' => '950507174']);
    }

    private function site(District $d, string $status, float $area): Site
    {
        static $no = 0;

        return Site::create(['district_id' => $d->id, 'source_no' => ++$no, 'address' => "Manzil {$no}", 'status' => $status, 'area_m2' => $area]);
    }

    private function seedSites(): Staff
    {
        $this->site($this->bektemir, Site::NOT_DEMOLISHED, 4000);
        $this->site($this->bektemir, Site::IN_PROGRESS, 2700);
        $done = $this->site($this->bektemir, Site::DEMOLISHED, 9999);
        $this->site($this->bektemir, Site::EXEMPT, 5000); // sud qarori bekor — hisobdan tashqari
        $pending = $this->site($this->chilonzor, Site::NOT_DEMOLISHED, 15800);

        $staff = Staff::create(['telegram_id' => 555, 'full_name' => 'Aliyev Vali', 'phone' => '901112233', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        foreach ([[$done, Inspection::REVIEW_APPROVED], [$pending, Inspection::REVIEW_PENDING]] as [$site, $review]) {
            Inspection::create(['site_id' => $site->id, 'staff_id' => $staff->id, 'status' => Inspection::SUBMITTED, 'step' => 'done',
                'is_demolished' => true, 'submitted_at' => now(), 'review_status' => $review]);
        }

        return $staff;
    }

    public function test_report_day_runs_from_18_to_18_and_past_days_keep_their_numbers(): void
    {
        $staff = Staff::create(['telegram_id' => 556, 'full_name' => 'X', 'phone' => '901112234', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $a = $this->site($this->bektemir, Site::NOT_DEMOLISHED, 1000);
        $b = $this->site($this->bektemir, Site::NOT_DEMOLISHED, 1000);
        $this->site($this->bektemir, Site::NOT_DEMOLISHED, 1000);
        $submit = fn (Site $s, string $at, array $attrs) => Inspection::create($attrs + ['site_id' => $s->id, 'staff_id' => $staff->id,
            'status' => Inspection::SUBMITTED, 'step' => 'done', 'submitted_at' => $at]);

        // kecha 17:00 — kechagi davr; kecha 19:00 — bugungi davr (18:00 dan keyin yangi kun)
        $yesterday = today()->subDay();
        $submit($a, $yesterday->copy()->setTime(17, 0), ['is_demolished' => true, 'review_status' => Inspection::REVIEW_APPROVED]);
        $a->update(['status' => Site::DEMOLISHED, 'demolished_at' => $yesterday]);
        $submit($b, $yesterday->copy()->setTime(19, 0), ['is_demolished' => false, 'in_progress' => true]);
        $b->update(['status' => Site::IN_PROGRESS]);

        $y = \App\Support\ReportPeriod::for($yesterday)->summary();
        $t = \App\Support\ReportPeriod::for(today())->summary();
        $this->assertSame([1, 0], [$y['demolished'], $y['progress']]);
        $this->assertSame([0, 1], [$t['demolished'], $t['progress']]);

        // kechagi svod: kecha 18:00 dagi holat — A buzilgan, B hali jarayonda emas
        $this->assertSame(['left' => 2, 'today' => 1, 'progress' => 0], array_intersect_key(Svod::data($yesterday)['total'], ['left' => 0, 'today' => 0, 'progress' => 0]));
        $this->assertSame(['left' => 2, 'today' => 0, 'progress' => 1], array_intersect_key(Svod::data(today())['total'], ['left' => 0, 'today' => 0, 'progress' => 0]));

        // 18:00 dan keyin joriy davr — ertangi kun, hisoblagichlar 0
        $this->travelTo(today()->setTime(18, 0, 1));
        $this->assertTrue(\App\Support\ReportPeriod::current()->day->isTomorrow());
        $this->assertSame(0, \App\Support\ReportPeriod::current()->summary()['inspections']);

        // dashboard: kun tanlanadi — vidjet o'sha kun sonlarini ko'rsatadi
        $admin = (new \App\Models\User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Widgets\PeriodStats::class, ['filters' => ['day' => $yesterday->toDateString()]])
            ->assertSee('Kunlik natija — '.$yesterday->format('d.m.Y'));
        $dash = \Livewire\Livewire::test(\App\Filament\Pages\Dashboard::class)->assertOk();
        $this->assertTrue(\App\Support\ReportPeriod::fromFilter($dash->get('filters.day'))->day->isTomorrow()); // 18:00 dan keyin — ertangi davr
        $this->get('/admin-svod.xlsx?day='.$yesterday->toDateString())->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="svod-'.$yesterday->format('Y-m-d').'.xlsx"');
    }

    public function test_svod_counts_left_sites_area_today_and_in_progress_per_district(): void
    {
        $this->seedSites();
        $data = Svod::data(today());

        $this->assertSame(['Bektemir tumani', 'Chilonzor tumani'], array_column($data['rows'], 'name'));
        $this->assertSame(['name' => 'Bektemir tumani', 'all' => 3, 'left' => 2, 'area' => 0.67, 'today' => 1, 'progress' => 1, 'court' => 1], $data['rows'][0]);
        $this->assertSame(['name' => 'Chilonzor tumani', 'all' => 1, 'left' => 1, 'area' => 1.58, 'today' => 1, 'progress' => 0, 'court' => 0], $data['rows'][1]);
        $this->assertSame(3, $data['total']['left']);
        $this->assertSame(2.25, $data['total']['area']);
        $this->assertSame('2,25', Svod::area($data['total']['area']));

        $png = Svod::png(today());
        $this->assertStringStartsWith("\x89PNG", $png);
        $getenv = getenv('SVOD_PNG_OUT');
        $getenv && file_put_contents($getenv, $png);
        // ikkinchi rasm: tumanlar kesimida bajarilish (jami 4 = 3 + 1, buzilgan 1, qolgan 3)
        $progress = Svod::progressPng(today());
        $this->assertStringStartsWith("\x89PNG", $progress);
        $getenv && file_put_contents(str_replace('.png', '-progress.png', $getenv), $progress);
        $this->assertSame(4, $data['total']['all']);
        // sud qarori bekor qilingan ham bajarilgan: jami 5 (4 + 1), bajarildi 2 (1 buzilgan + 1 sud qarori), 40%
        $this->assertSame(['all' => 5, 'demolished' => 1, 'court' => 1, 'left' => 3, 'done' => 2, 'pct' => 40.0], Svod::progress($data['total']));

        $xlsx = Svod::xlsx(today());
        $this->assertStringStartsWith('PK', $xlsx);
        $zip = new \ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $xlsx);
        $zip->open($tmp);
        $this->assertStringContainsString('Toshkent shahar jami', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_daily_report_is_short_photo_without_staff_names(): void
    {
        $this->seedSites();
        $admin = Staff::create(['telegram_id' => 777, 'full_name' => 'Admin', 'phone' => '901756545', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'receives_reports' => true, 'attendance_required' => false]);

        $caption = InspectionReport::caption(\App\Support\ReportPeriod::current()->day);
        $this->assertStringContainsString("Buzilgan: <b>2</b>\n", $caption);
        $this->assertStringNotContainsString('tasdiq kutilmoqda', $caption);
        $this->assertStringContainsString('Qoldiq: <b>3</b> ta · 2,25 ga', $caption);
        $this->assertStringNotContainsString('Aliyev', $caption);
        $this->assertStringNotContainsString('Manzil', $caption);

        $bot = Nutgram::fake();
        $this->app->instance(Nutgram::class, $bot);
        $this->assertSame(1, InspectionReport::send($bot, today()));
        // bitta albom: ikki rasm (svod + bajarilish), izoh birinchisida
        $req = collect($bot->getRequestHistory())->first()['request'];
        $this->assertStringContainsString('sendMediaGroup', (string) $req->getUri());
        $body = (string) $req->getBody();
        $this->assertStringContainsString((string) $admin->telegram_id, $body);
        $this->assertSame(2, substr_count($body, 'attach:')); // JSON ichida 'attach://…'
        $this->assertSame(2, substr_count($body, "\x89PNG"));
        $this->assertStringContainsString('Kunlik hisobot', $body); // izoh albomning birinchi rasmida
    }

    private function user(bool $admin): \App\Models\User
    {
        $u = (new \App\Models\User)->forceFill(['name' => 'U', 'email' => ($admin ? 'a' : 'v').'@t.test', 'password' => 'secret-pass-1', 'is_admin' => $admin, 'is_viewer' => ! $admin]);
        $u->save();

        return $u;
    }

    public function test_svod_widget_and_excel_are_admin_only(): void
    {
        $this->seedSites();

        $this->actingAs($this->user(true))->get('/admin')->assertOk()->assertSee('svod-table');
        \Livewire\Livewire::test(\App\Filament\Widgets\SvodTable::class)
            ->assertSee('Toshkent shahar jami')->assertSee('Excel yuklab olish')->assertSee('2,25');
        $this->get('/admin-svod.xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->flushSession(); // boshqa foydalanuvchi (AuthenticateSession avvalgi sessiyani rad etadi)
        $this->actingAs($this->user(false))->get('/admin')->assertOk()->assertDontSee('svod-table');
        $this->get('/admin-svod.xlsx')->assertForbidden();
    }

    public function test_broadcast_reaches_selected_district_staff(): void
    {
        $a = Staff::create(['telegram_id' => 1001, 'full_name' => 'A', 'phone' => '901000001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $b = Staff::create(['telegram_id' => 1002, 'full_name' => 'B', 'phone' => '901000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        Staff::create(['telegram_id' => 1003, 'full_name' => 'C', 'phone' => '901000003', 'status' => Staff::BLOCKED, 'role' => Staff::ROLE_EMPLOYEE]);
        $a->districts()->sync([$this->bektemir->id]);
        $b->districts()->sync([$this->chilonzor->id]);

        $bot = Nutgram::fake();
        $this->app->instance(Nutgram::class, $bot);

        $this->assertSame(['sent' => 2, 'failed' => 0], \App\Support\Broadcast::send('Ertaga 08:00 da yig‘ilish', [], null));
        $this->assertSame(['sent' => 1, 'failed' => 0], \App\Support\Broadcast::send('<b>Bektemir</b> uchun', [$this->bektemir->id], null));
        $last = urldecode((string) collect($bot->getRequestHistory())->last()['request']->getBody());
        $this->assertStringContainsString('1001', $last);
        $this->assertStringContainsString('&lt;b&gt;Bektemir', $last); // matn HTML sifatida emas, oddiy matn sifatida
    }
}
