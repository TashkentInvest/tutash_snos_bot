<?php

namespace Tests\Feature;

use App\Filament\Pages\LeaseSvodPage;
use App\Filament\Pages\ReportSettings;
use App\Models\District;
use App\Models\GastroStreet;
use App\Models\LeaseContract;
use App\Models\ReportSetting;
use App\Models\Staff;
use App\Models\User;
use App\Telegram\Support\LeaseReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/** Gastronomik svod Telegram'ga: admin panelda yoqiladi, oluvchilar, vaqt va kunlar belgilanadi. */
class LeaseReportTest extends TestCase
{
    use RefreshDatabase;

    private Staff $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $mir = District::create(['name' => 'Mirobod tumani', 'phone' => '901000001']);
        $st = GastroStreet::create(['district_id' => $mir->id, 'name' => 'Taras Shevchenko', 'line' => [[41.29, 69.27], [41.30, 69.27]]]);
        $this->boss = Staff::create(['telegram_id' => 9001, 'full_name' => 'Rahbar', 'phone' => '900009001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true]);
        $base = ['district_id' => $mir->id, 'gastro_street_id' => $st->id, 'staff_id' => $this->boss->id, 'party' => LeaseContract::LEGAL,
            'mfy' => 'Oybek', 'activity' => 'Savdo', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'lat' => 41.3, 'lng' => 69.27,
            'docx_path' => 'contracts/x.docx', 'tariff' => 62073.19];
        LeaseContract::create($base + ['tenant_name' => 'A', 'cadastre' => '1', 'area_m2' => 58.5, 'annual_amount' => 21787689.72, 'street_distance_m' => 10]);
        LeaseContract::create($base + ['tenant_name' => 'B', 'cadastre' => '2', 'area_m2' => 10, 'annual_amount' => 3724391.4, 'street_distance_m' => 7000]); // uzoqdan (sinov)
    }

    private function sent(): string
    {
        return collect(app(Nutgram::class)->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getUri().' '.(string) array_values($r)[0]->getBody())->implode("\n");
    }

    public function test_sent_only_at_configured_time_and_days_once_a_day(): void
    {
        ReportSetting::for(ReportSetting::GASTRO)->update(['enabled' => true, 'recipients' => [$this->boss->id],
            'options' => ['at' => '18:30', 'days' => [1, 2, 3, 4, 5], 'onsite' => true]]);

        Carbon::setTestNow('2026-11-16 18:29:00'); // dushanba, vaqti emas
        $this->artisan('leases:report')->assertSuccessful();
        $this->assertStringNotContainsString('sendPhoto', $this->sent());

        Carbon::setTestNow('2026-11-16 18:30:00');
        $this->artisan('leases:report')->assertSuccessful();
        $this->artisan('leases:report')->assertSuccessful(); // shu daqiqada qayta — yuborilmaydi
        $this->assertSame(1, substr_count($this->sent(), 'sendPhoto'));
        $this->assertStringContainsString('Ijara shartnomalari svodi', $this->sent());
        $this->assertStringContainsString('3 631 282', $this->sent()); // faqat joyida — uzoqdan tuzilgan (B) kirmaydi

        Carbon::setTestNow('2026-11-21 18:30:00'); // shanba — kunlar ro'yxatida yo'q
        $this->artisan('leases:report')->assertSuccessful();
        $this->assertSame(1, substr_count($this->sent(), 'sendPhoto'));

        ReportSetting::for(ReportSetting::GASTRO)->update(['enabled' => false]); // o'chirilgan
        Carbon::setTestNow('2026-11-17 18:30:00');
        $this->artisan('leases:report')->assertSuccessful();
        $this->assertSame(1, substr_count($this->sent(), 'sendPhoto'));

        $this->artisan('leases:report --force')->assertSuccessful(); // qo'lda — jadvalsiz
        $this->assertSame(2, substr_count($this->sent(), 'sendPhoto'));
        Carbon::setTestNow();
    }

    public function test_image_and_caption_follow_onsite_option(): void
    {
        $png = LeaseReport::png(today(), false);
        $this->assertSame("\x89PNG", substr($png, 0, 4));
        $this->assertStringContainsString('Jami: <b>2</b>', LeaseReport::caption(today(), false));
        $this->assertStringContainsString('Jami: <b>1</b>', LeaseReport::caption(today(), true));
        $this->assertStringContainsString('Bugun tuzilgan: <b>2</b>', LeaseReport::caption(today(), false));
    }

    public function test_admin_manages_schedule_and_sends_manually(): void
    {
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);

        $this->get('/admin/report-settings')->assertOk()->assertSee('Gastronomik ko‘chalar — ijara shartnomalari svodi')->assertSee('Yuborish vaqti');
        Livewire::test(ReportSettings::class)
            ->assertSet('data.'.ReportSetting::GASTRO.'.options.at', '18:00')
            ->set('data.'.ReportSetting::GASTRO.'.options.at', '09:15')
            ->set('data.'.ReportSetting::GASTRO.'.options.days', ['1', '3'])
            ->set('data.'.ReportSetting::GASTRO.'.options.onsite', false)
            ->set('data.'.ReportSetting::GASTRO.'.recipients', [(string) $this->boss->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['at' => '09:15', 'days' => [1, 3], 'onsite' => false], ReportSetting::gastroOptions());
        $this->assertSame([$this->boss->id], ReportSetting::for(ReportSetting::GASTRO)->recipients);

        $this->get(route('admin.lease-svod.png'))->assertOk()->assertHeader('Content-Type', 'image/png');

        Livewire::test(LeaseSvodPage::class)
            ->callAction('sendTelegram', ['onsite' => false, 'recipients' => [(string) $this->boss->id]])->assertHasNoActionErrors();
        $this->assertSame(1, substr_count($this->sent(), 'sendPhoto'));
    }
}
