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
        $rChil = GastroResponsible::create(['district_id' => $chil->id, 'phone' => '950507174']);
        $rChil->staff()->attach($sanjar);
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

        // bir tumanga bir nechta mas'ul — faqat shu yerda; xodimlarning «Tumanlar»i o'zgarmaydi
        $page = fn () => \Livewire\Livewire::test(\App\Filament\Resources\GastroResponsibleResource\Pages\ManageGastroResponsibles::class);
        $page()->callTableAction('edit', $rChil, ['staff' => [$sanjar->id, $other->id]])->assertHasNoTableActionErrors();
        $this->assertEqualsCanonicalizing([$sanjar->id, $other->id], $rChil->staff()->pluck('staff.id')->all());
        $this->assertSame([$chil->id], GastroResponsible::districtIdsFor($other));
        $this->assertSame([$chil->id], GastroResponsible::districtIdsFor($sanjar));
        $this->assertSame([], $other->districts()->pluck('districts.id')->all());
        $this->get('/admin/gastro-map')->assertOk()->assertSee('Aslanov Sanjar, Boshqa Xodim');
        // bir xodim bir nechta tumanga ham mas'ul bo'la oladi
        $rUch = GastroResponsible::where('district_id', $uch->id)->sole();
        $page()->callTableAction('edit', $rUch, ['staff' => [$other->id]])->assertHasNoTableActionErrors();
        $this->assertSame([$chil->id, $uch->id], GastroResponsible::districtIdsFor($other));
        $this->assertSame([], GastroResponsible::districtIdsFor($late)); // xodim tanlangach jadvaldagi raqam endi ishlamaydi
        // 1 m² tarifi jadvalning o'zida tahrirlanadi
        $page()->call('updateTableColumnState', 'tariff', (string) $rChil->id, '37101.44');
        $this->assertSame(37101.44, $rChil->refresh()->tariff);
        // olib tashlandi
        $page()->callTableAction('edit', $rChil, ['staff' => [$other->id]]);
        $this->assertSame([], GastroResponsible::districtIdsFor($sanjar));

        // faqat admin
        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer);
        $this->assertFalse(\App\Filament\Pages\GastroMap::canAccess());
        $this->assertFalse(\App\Filament\Resources\GastroResponsibleResource::canViewAny());
    }

    /** Obyekt xaritalaridagi «Gastronomik ko'chalar» qatlami: keshlangan, ko'cha o'zgarsa yangilanadi. */
    public function test_map_layer_lines_are_cached_and_refreshed(): void
    {
        $d = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001']);
        $s = GastroStreet::create(['district_id' => $d->id, 'name' => 'Qatortol', 'line' => [[41.28, 69.20], [41.29, 69.21]]]);
        $this->assertSame([['n' => 'Qatortol', 'l' => [[41.28, 69.20], [41.29, 69.21]]]], GastroStreet::mapLines());
        $s->update(['name' => 'Qatortol ko‘chasi']);
        $this->assertSame('Qatortol ko‘chasi', GastroStreet::mapLines()[0]['n']);
        $s->delete();
        $this->assertSame([], GastroStreet::mapLines());
    }

    public function test_migration_data_files_are_consistent(): void
    {
        $streets = json_decode(file_get_contents(database_path('data/gastro-streets.json')), true);
        $bounds = json_decode(file_get_contents(database_path('data/district-boundaries.json')), true);
        $this->assertCount(12, $streets);
        $this->assertSame(42, collect($streets)->flatten(1)->count());
        $this->assertEmpty(array_diff(array_keys($streets), array_keys($bounds))); // tuman nomlari bir xil
        // 1 m² tariflari: 12 tumanning har biri uchun, nomlari bir xil
        $tariffs = (new \ReflectionClassConstant(require database_path('migrations/2026_11_15_000002_add_lease_tariff.php'), 'TARIFFS'))->getValue();
        $this->assertEqualsCanonicalizing(array_keys($streets), array_keys($tariffs));
    }

    /** Migratsiya: KMZ ko'chalari va «Shartnoma tushum» jadvali tumanlar nomi/telefon bo'yicha yoziladi. */
    public function test_migration_seeds_streets_and_responsibles_by_name_and_phone(): void
    {
        foreach (['Chilonzor tumani', 'Uchtepa tumani', 'Olmazor tumani', 'Mirobod tumani'] as $i => $n) {
            District::create(['name' => $n, 'phone' => '90100000'.$i]);
        }
        $sanjar = Staff::create(['telegram_id' => 501, 'full_name' => 'Aslanov Sanjar', 'phone' => '950507174', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        \Illuminate\Support\Facades\Schema::dropIfExists('gastro_responsible_staff');
        \Illuminate\Support\Facades\Schema::dropIfExists('gastro_responsibles');
        \Illuminate\Support\Facades\Schema::dropIfExists('gastro_streets');
        (require database_path('migrations/2026_11_14_000001_create_gastro_tables.php'))->up();
        (require database_path('migrations/2026_11_15_000001_create_gastro_responsible_staff_table.php'))->up(); // yagona mas'ul → ro'yxatga

        $this->assertSame(6, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Chilonzor tumani'))->count());
        $this->assertSame(12, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Olmazor tumani'))->count());
        $this->assertSame(1, GastroStreet::whereHas('district', fn ($q) => $q->where('name', 'Mirobod tumani'))->count());
        $this->assertSame(4, GastroResponsible::count());
        $this->assertEqualsCanonicalizing(District::whereIn('name', ['Chilonzor tumani', 'Uchtepa tumani'])->pluck('id')->all(), GastroResponsible::districtIdsFor($sanjar));
        $this->assertSame('978981899', GastroResponsible::whereHas('district', fn ($q) => $q->where('name', 'Olmazor tumani'))->value('phone'));
    }
}
