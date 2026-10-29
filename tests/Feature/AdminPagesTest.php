<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_only_confirmed_demolitions_and_skips_rejected(): void
    {
        $d = District::create(['name' => 'Test tumani', 'phone' => '991112233']);
        $site = Site::create(['district_id' => $d->id, 'source_no' => 1, 'address' => 'Ko\'cha 1']);
        $staff = Staff::create(['telegram_id' => 5, 'full_name' => 'Test Xodim', 'status' => 'active']);
        $make = fn (array $a) => Inspection::create($a + ['site_id' => $site->id, 'staff_id' => $staff->id, 'status' => 'submitted', 'step' => 'done', 'submitted_at' => now()]);

        $make(['is_demolished' => true, 'review_status' => Inspection::REVIEW_APPROVED]);
        $make(['is_demolished' => true, 'review_status' => Inspection::REVIEW_PENDING]);
        $make(['is_demolished' => true, 'review_status' => Inspection::REVIEW_REJECTED, 'archived_at' => now(), 'suspicious' => true]);
        $make(['is_demolished' => false, 'reason' => 'sabab sabab sabab']);

        $s = \App\Filament\Widgets\InspectionsChart::series();
        $this->assertSame([1, 1, 1], [end($s['approved']), end($s['pending']), end($s['not_demolished'])]);

        $this->assertSame(3, Inspection::counted()->whereDate('submitted_at', today())->count()); // rad etilgani hisoblanmaydi
        $this->assertSame(0, Inspection::counted()->where('suspicious', true)->count());
    }

    public function test_guest_opening_media_link_is_redirected_to_admin_login(): void
    {
        $this->get('/admin-media/1')->assertRedirect('/admin/login');
        $this->get('/admin-attendance/1')->assertRedirect('/admin/login');
    }

    public function test_site_form_has_map_picker_and_saves_coordinates(): void
    {
        $d = District::create(['name' => 'Test tumani', 'phone' => '991112233']);
        $site = Site::create(['district_id' => $d->id, 'source_no' => 48, 'address' => 'Guliston ko‘chasi 7-uy']);
        $user = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $user->save();

        foreach (['/admin/sites/create', "/admin/sites/{$site->id}/edit"] as $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertSee('Xaritada obyekt joyini bosing', false)->assertSee('leaflet@1.9.4', false);
        }

        // xaritadan tanlangan nuqta (lat/lng maydonlariga yoziladi) saqlanadi; xarita maydonining o'zi bazaga yozilmaydi
        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Resources\SiteResource\Pages\EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['lat' => 41.3264512, 'lng' => 69.3304187])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertEqualsWithDelta(41.3264512, $site->refresh()->lat, 0.0000001);
        $this->assertEqualsWithDelta(69.3304187, $site->lng, 0.0000001);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Resources\SiteResource\Pages\CreateSite::class)
            ->fillForm(['district_id' => $d->id, 'address' => 'Yangi ko‘cha', 'status' => Site::NOT_DEMOLISHED, 'lat' => 41.31, 'lng' => 69.27])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('sites', ['address' => 'Yangi ko‘cha', 'lat' => 41.31, 'lng' => 69.27]);
    }

    public function test_admin_pages_render(): void
    {
        $d = District::create(['name' => 'Test tumani', 'phone' => '991112233']);
        $m = Mahalla::create(['district_id' => $d->id, 'name' => 'Test MFY']);
        $site = Site::create(['district_id' => $d->id, 'mahalla_id' => $m->id, 'source_no' => 1, 'address' => 'Ko\'cha 1', 'lat' => 41.3, 'lng' => 69.2, 'area_m2' => 120]);
        $staff = Staff::create(['telegram_id' => 5, 'full_name' => 'Test Xodim', 'phone' => '991112233', 'status' => 'active']);
        $in = Inspection::create(['site_id' => $site->id, 'staff_id' => $staff->id, 'status' => 'submitted', 'step' => 'done', 'is_demolished' => false, 'reason' => 'sabab sabab sabab', 'suspicious' => true, 'flags' => ['far_from_site'], 'submitted_at' => now()]);
        $user = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $user->save();

        foreach (['/admin', '/admin/sites', "/admin/sites/{$site->id}", "/admin/sites/{$site->id}/edit", '/admin/inspections', "/admin/inspections/{$in->id}", '/admin/staff', '/admin/districts', '/admin/attendances'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $lw = fn ($c, $p = []) => \Livewire\Livewire::actingAs($user)->test($c, $p)->assertSuccessful();
        $lw(\App\Filament\Resources\SiteResource\RelationManagers\InspectionsRelationManager::class, ['ownerRecord' => $site, 'pageClass' => \App\Filament\Resources\SiteResource\Pages\ViewSite::class]);
        $lw(\App\Filament\Widgets\DistrictProgress::class);
        $lw(\App\Filament\Widgets\StatsOverview::class);
        $lw(\App\Filament\Widgets\InspectionsChart::class);
        $lw(\App\Filament\Widgets\AttendanceToday::class);
        \App\Models\Attendance::create(['staff_id' => $staff->id, 'date' => today(), 'status' => 'present', 'lat' => 41.3, 'lng' => 69.2, 'video_file_id' => 'x', 'video_path' => 'attendance/x.mp4', 'responded_at' => now()]);
        $lw(\App\Filament\Resources\AttendanceResource\Pages\ListAttendances::class);
        $lw(\App\Filament\Widgets\AttendanceList::class);
        $lw(\App\Filament\Resources\SiteResource\Pages\ListSites::class);
        $lw(\App\Filament\Resources\InspectionResource\Pages\ListInspections::class);

        // Tasdiq kutayotgan "buzilgan" natija: menyu belgisi, qo'ng'iroq, tab va tasdiqlash amali
        $pending = Inspection::create(['site_id' => $site->id, 'staff_id' => $staff->id, 'status' => 'submitted', 'step' => 'done', 'is_demolished' => true, 'review_status' => 'pending', 'submitted_at' => now()]);
        $this->assertSame('1', \App\Filament\Resources\InspectionResource::getNavigationBadge());
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('pending-inspections-bell', false)->assertSee('Tasdiqlanmagan');
        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\PendingInspectionsBell::class)->assertSee('snos-bell-on', false)->assertSee('>1<', false);
        $lw(\App\Filament\Resources\InspectionResource\Pages\ListInspections::class);
        \Livewire\Livewire::actingAs($user)->test(\App\Filament\Resources\InspectionResource\Pages\ListInspections::class)
            ->set('activeTab', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$in]);
        $this->actingAs($user)->get("/admin/inspections/{$pending->id}")->assertOk()->assertSee('Tasdiqlash');
        \Livewire\Livewire::actingAs($user)->test(\App\Filament\Resources\InspectionResource\Pages\ListInspections::class)
            ->callTableAction('approve', $pending);
        $this->assertSame(Site::DEMOLISHED, $site->refresh()->status);
        $this->assertSame('approved', $pending->refresh()->review_status);
        $this->assertNull(\App\Filament\Resources\InspectionResource::getNavigationBadge());
        $site->update(['status' => Site::NOT_DEMOLISHED]);

        // Dashboard kartalari va tuman qatorlari filtrlangan ro'yxatni ochadi
        Site::create(['district_id' => $d->id, 'mahalla_id' => $m->id, 'source_no' => 2, 'address' => 'Buzilgan manzil 77', 'status' => Site::DEMOLISHED]);
        $url = \App\Filament\Resources\SiteResource::getUrl('index', ['tableFilters' => ['status' => ['value' => Site::DEMOLISHED]]]);
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Buzilgan manzil 77')->assertDontSee('Kocha 1');

        $plain = (new User)->forceFill(['name' => 'B', 'email' => 'b@t.test', 'password' => 'secret-pass-2']);
        $plain->save();
        $this->actingAs($plain)->get('/admin')->assertForbidden();
    }
}
