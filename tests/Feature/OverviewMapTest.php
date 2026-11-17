<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\District;
use App\Models\GastroStreet;
use App\Models\Inspection;
use App\Models\LocationRejection;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Umumiy xarita»: qatlamlar alohida yuklanadi, tuman / kun / xodim bo'yicha filtr, faqat admin. */
class OverviewMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_layers_with_filters(): void
    {
        $chil = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001', 'boundary' => [[[41.27, 69.18], [41.30, 69.18], [41.30, 69.23], [41.27, 69.23]]]]);
        $mir = District::create(['name' => 'Mirobod tumani', 'phone' => '901000002']);
        GastroStreet::create(['district_id' => $chil->id, 'name' => 'Qatortol', 'line' => [[41.28, 69.20], [41.29, 69.21]]]);
        $a = Site::create(['district_id' => $chil->id, 'source_no' => 1, 'address' => 'Qatortol 1', 'lat' => 41.285, 'lng' => 69.205, 'status' => Site::DEMOLISHED]);
        Site::create(['district_id' => $mir->id, 'source_no' => 2, 'address' => 'Mirobod 2', 'lat' => 41.29, 'lng' => 69.28]);
        $s = Staff::create(['telegram_id' => 1, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $s->districts()->attach($chil);
        Attendance::create(['staff_id' => $s->id, 'date' => today(), 'status' => 'present', 'lat' => 41.28, 'lng' => 69.20, 'responded_at' => now()]);
        Attendance::create(['staff_id' => $s->id, 'date' => today()->subDay(), 'status' => 'present', 'lat' => 41.5, 'lng' => 69.9]);
        Checkin::create(['staff_id' => $s->id, 'date' => today(), 'slot' => '10:00', 'status' => 'present', 'deadline_at' => now(), 'lat' => 41.28, 'lng' => 69.20]);
        Checkin::create(['staff_id' => $s->id, 'date' => today(), 'slot' => '11:00', 'status' => 'present', 'deadline_at' => now(), 'lat' => 41.60, 'lng' => 69.90]); // oxirgisi — tumandan tashqarida
        Inspection::create(['site_id' => $a->id, 'staff_id' => $s->id, 'status' => Inspection::SUBMITTED, 'is_demolished' => true, 'staff_lat' => 41.2851, 'staff_lng' => 69.2051, 'submitted_at' => now()]);
        LocationRejection::create(['staff_id' => $s->id, 'source' => 'checkin', 'reason' => 'out_of_district', 'lat' => 41.6, 'lng' => 69.9, 'accuracy' => 10]);

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/map')->assertOk()->assertSee('Umumiy xarita')->assertSee('Qatlamlar')->assertSee('Ertalabki davomat')->assertSee('Shaffoflik');

        $layer = fn (string $k, array $q = []) => $this->getJson(route('admin.map-layer', ['layer' => $k] + $q))->assertOk()->json();
        $this->assertCount(1, $layer('gastro')['lines']);
        $this->assertCount(2, $layer('sites')['points']);
        $this->assertSame(Site::DEMOLISHED, $layer('sites', ['district' => $chil->id])['points'][0]['c']);
        $this->assertCount(1, $layer('sites', ['district' => $mir->id])['points']);

        $att = $layer('attendance')['points']; // faqat bugungi
        $this->assertCount(1, $att);
        $this->assertSame('in', $att[0]['c']);
        $this->assertCount(1, $layer('attendance', ['day' => today()->subDay()->toDateString()])['points']);
        $this->assertCount(0, $layer('attendance', ['district' => $mir->id])['points']); // xodim Chilonzorniki

        $ch = $layer('checkins')['points']; // har xodimdan oxirgi qayd
        $this->assertCount(1, $ch);
        $this->assertSame('out', $ch[0]['c']);
        $this->assertStringContainsString('11:00', $ch[0]['t']);

        $this->assertSame('demolished', $layer('inspections')['points'][0]['c']);
        $this->assertCount(0, $layer('inspections', ['staff' => 999])['points']);
        $this->assertCount(1, $layer('rejections')['points']);
        $this->getJson(route('admin.map-layer', ['layer' => 'nope']))->assertNotFound();

        // faqat admin
        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer);
        $this->getJson(route('admin.map-layer', ['layer' => 'sites']))->assertForbidden();
        $this->assertFalse(\App\Filament\Pages\OverviewMap::canAccess());
    }
}
