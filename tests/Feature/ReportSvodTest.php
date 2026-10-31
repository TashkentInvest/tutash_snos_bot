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

    public function test_svod_counts_left_sites_area_today_and_in_progress_per_district(): void
    {
        $this->seedSites();
        $data = Svod::data(today());

        $this->assertSame(['Bektemir tumani', 'Chilonzor tumani'], array_column($data['rows'], 'name'));
        $this->assertSame(['name' => 'Bektemir tumani', 'left' => 2, 'area' => 0.67, 'today' => 1, 'progress' => 1], $data['rows'][0]);
        $this->assertSame(['name' => 'Chilonzor tumani', 'left' => 1, 'area' => 1.58, 'today' => 1, 'progress' => 0], $data['rows'][1]);
        $this->assertSame(3, $data['total']['left']);
        $this->assertSame(2.25, $data['total']['area']);
        $this->assertSame('2,25', Svod::area($data['total']['area']));

        $png = Svod::png(today());
        $this->assertStringStartsWith("\x89PNG", $png);
        $getenv = getenv('SVOD_PNG_OUT');
        $getenv && file_put_contents($getenv, $png);

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
            'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true, 'attendance_required' => false]);

        $caption = InspectionReport::caption(today());
        $this->assertStringContainsString("Buzilgan: <b>2</b>\n", $caption);
        $this->assertStringNotContainsString('tasdiq kutilmoqda', $caption);
        $this->assertStringContainsString('Qoldiq: <b>3</b> ta · 2,25 ga', $caption);
        $this->assertStringNotContainsString('Aliyev', $caption);
        $this->assertStringNotContainsString('Manzil', $caption);

        $bot = Nutgram::fake();
        $this->app->instance(Nutgram::class, $bot);
        $this->assertSame(1, InspectionReport::send($bot, today()));
        $req = collect($bot->getRequestHistory())->first()['request'];
        $this->assertStringContainsString('sendPhoto', (string) $req->getUri());
        $body = (string) $req->getBody();
        $this->assertStringContainsString((string) $admin->telegram_id, $body);
        $this->assertStringContainsString('image/png', $body);
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
