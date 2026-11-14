<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gastronomik ko'chalar: mas'ullar (xodim tumanlaridan alohida) va ko'chalar xaritasi. */
class GastroTest extends TestCase
{
    use RefreshDatabase;

    public function test_responsibles_are_separate_from_staff_districts_and_streets_show_on_map(): void
    {
        $chil = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001', 'boundary' => [[[41.27, 69.18], [41.30, 69.18], [41.30, 69.23], [41.27, 69.18]]]]);
        $uch = District::create(['name' => 'Uchtepa tumani', 'phone' => '901000002']);
        GastroStreet::create(['district_id' => $chil->id, 'name' => 'Qatortol', 'line' => [[41.28, 69.20], [41.29, 69.21]]]);
        $sanjar = Staff::create(['telegram_id' => 501, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $other = Staff::create(['telegram_id' => 502, 'full_name' => 'Boshqa Xodim', 'phone' => '900000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $rChil = GastroResponsible::create(['district_id' => $chil->id, 'staff_id' => $sanjar->id, 'phone' => '950507174']);
        GastroResponsible::create(['district_id' => $uch->id, 'phone' => '977777777']); // botga hali kirmagan

        $this->assertSame([$chil->id], GastroResponsible::districtIdsFor($sanjar));
        $this->assertSame([], $sanjar->districts()->pluck('districts.id')->all()); // davomat/obyekt tumanlari tegilmadi

        // botga keyin kirgan raqam egasi — jadvaldagi raqami bo'yicha
        $late = Staff::create(['telegram_id' => 503, 'full_name' => 'Kech Kelgan', 'phone' => '977777777', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $this->assertSame([$uch->id], GastroResponsible::districtIdsFor($late));

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/gastro-responsibles')->assertOk()->assertSee('Chilonzor tumani')->assertSee('botga hali kirmagan');
        $this->get('/admin/gastro-map')->assertOk()->assertSee('Qatortol')->assertSee('Aslanov Sanjar')->assertSee('977777777 (botga kirmagan)');

        // xodim almashdi — faqat shu yerda; boshqa xodimning tumanlari o'zgarmaydi
        \Livewire\Livewire::test(\App\Filament\Resources\GastroResponsibleResource\Pages\ManageGastroResponsibles::class)
            ->call('updateTableColumnState', 'staff_id', (string) $rChil->id, (string) $other->id);
        $this->assertSame($other->id, $rChil->refresh()->staff_id);
        $this->assertSame([$chil->id], GastroResponsible::districtIdsFor($other));
        $this->assertSame([], GastroResponsible::districtIdsFor($sanjar));
        $this->assertSame([], $other->districts()->pluck('districts.id')->all());

        // faqat admin
        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer);
        $this->assertFalse(\App\Filament\Pages\GastroMap::canAccess());
        $this->assertFalse(\App\Filament\Resources\GastroResponsibleResource::canViewAny());
    }

    public function test_migration_data_files_are_consistent(): void
    {
        $streets = json_decode(file_get_contents(database_path('data/gastro-streets.json')), true);
        $bounds = json_decode(file_get_contents(database_path('data/district-boundaries.json')), true);
        $this->assertCount(12, $streets);
        $this->assertSame(42, collect($streets)->flatten(1)->count());
        $this->assertEmpty(array_diff(array_keys($streets), array_keys($bounds))); // tuman nomlari bir xil
    }

    /** Migratsiya: KMZ ko'chalari va «Shartnoma tushum» jadvali tumanlar nomi/telefon bo'yicha yoziladi. */
    public function test_migration_seeds_streets_and_responsibles_by_name_and_phone(): void
    {
        foreach (['Chilonzor tumani', 'Uchtepa tumani', 'Olmazor tumani', 'Mirobod tumani'] as $i => $n) {
            District::create(['name' => $n, 'phone' => '90100000'.$i]);
        }
        $sanjar = Staff::create(['telegram_id' => 501, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        \Illuminate\Support\Facades\Schema::dropIfExists('gastro_responsibles');
        \Illuminate\Support\Facades\Schema::dropIfExists('gastro_streets');
        (require database_path('migrations/2026_11_14_000001_create_gastro_tables.php'))->up();

        $this->assertSame(6, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Chilonzor tumani'))->count());
        $this->assertSame(12, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Olmazor tumani'))->count());
        $this->assertSame(1, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Mirobod tumani'))->count());
        $this->assertSame(4, GastroResponsible::count());
        $this->assertEqualsCanonicalizing(District::whereIn('name', ['Chilonzor tumani', 'Uchtepa tumani'])->pluck('id')->all(), GastroResponsible::districtIdsFor($sanjar));
        $this->assertSame('978981899', GastroResponsible::whereHas('district', fn ($q) => $q->where('name', 'Olmazor tumani'))->value('phone'));
    }
}
