<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Faqat davomat» foydalanuvchisi: ertalabki davomatni ko'radi (videolar bilan), boshqa bo'limlar yopiq. */
class LimitedRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_only_user_sees_only_attendance(): void
    {
        $s = Staff::create(['telegram_id' => 7, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        Attendance::create(['staff_id' => $s->id, 'date' => today(), 'status' => 'present', 'lat' => 41.3, 'lng' => 69.2, 'responded_at' => now()]);
        $u = (new User)->forceFill(['name' => 'Kadir', 'email' => 'k@t.test', 'password' => 'secret-pass-1', 'attendance_only' => true]);
        $u->save();
        $this->actingAs($u);

        $this->get('/admin')->assertRedirect(AttendanceResource::getUrl('index'));
        $this->get('/admin/attendances')->assertOk()->assertSee('Aslanov Sanjar');
        foreach (['/admin/sites', '/admin/inspections', '/admin/checkins', '/admin/staff', '/admin/lease-contracts', '/admin/report-settings', '/admin/map', '/admin/gastro-map'] as $p) {
            $this->get($p)->assertForbidden();
        }
        $this->assertTrue($u->canAccessPanel(filament()->getPanel('admin')));

        // «Ro'yxatdan o'tmagan» va boshqa admin amallari unga ko'rinmaydi
        $this->assertFalse(User::currentIsAdmin());
        $this->assertTrue(User::currentIsLimited());
    }
}
