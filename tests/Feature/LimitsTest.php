<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportSettings;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Staff;
use App\Models\User;
use App\Support\Gps;
use App\Support\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Hisobotlar sahifasidagi «Joylashuv cheklovlari»: davomat, obyekt tekshiruvi, gastronomik ko'cha. */
class LimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_come_from_config_and_admin_page_saves_overrides(): void
    {
        config(['tutash.max_distance_m' => 300, 'tutash.strict_distance' => true]);
        $this->assertSame(300, Limits::inspectionRadius());
        $this->assertTrue(Limits::attendanceCheck());

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/report-settings')->assertOk()->assertSee('Joylashuv cheklovlari')->assertSee('Faqat o‘z tumani hududidan');

        Livewire::test(ReportSettings::class)
            ->assertSet('data.limits.inspection_radius', 300)
            ->set('data.limits.attendance_check', false)
            ->set('data.limits.lease_radius', 800)
            ->set('data.limits.gps_accuracy', 400)
            ->call('save')->assertHasNoFormErrors();

        $this->assertFalse(Limits::attendanceCheck());
        $this->assertSame(800, Limits::leaseRadius());
        $this->assertSame(400, Limits::gpsAccuracy());
        $this->assertSame(300, Limits::inspectionRadius()); // tegilmagani — o'zgarmadi
        $this->assertNull(Gps::accuracyError(390.0));
        $this->assertTrue(AuditLog::where('event', 'limits.saved')->exists());

        // o'chirilgan cheklov maydonlari yashirin — saqlanganda eski qiymat qoladi
        Livewire::test(ReportSettings::class)->assertSet('data.limits.attendance_check', false)->call('save')->assertHasNoFormErrors();
        $this->assertSame(300, Limits::boundaryBuffer(true));
    }

    public function test_attendance_district_limit_can_be_turned_off_for_remote_work(): void
    {
        $d = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001', 'boundary' => [[[41.27, 69.18], [41.30, 69.18], [41.30, 69.23], [41.27, 69.23]]]]);
        $s = Staff::create(['telegram_id' => 601, 'full_name' => 'Xodim', 'phone' => '900000601', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $s->districts()->attach($d);

        $this->assertNotNull(Gps::outsideDistricts($s, 41.40, 69.40, 10)); // tumandan ~20 km — rad
        Limits::save(['attendance_boundary_buffer' => 30000]);
        $this->assertNull(Gps::outsideDistricts($s, 41.40, 69.40, 10)); // zaxira kattalashtirildi
        Limits::save(['attendance_boundary_buffer' => 300, 'attendance_check' => false]);
        $this->assertNull(Gps::outsideDistricts($s, 41.40, 69.40, 10)); // cheklov o'chirildi — onlayn
    }
}
