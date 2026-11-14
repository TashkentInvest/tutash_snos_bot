<?php

namespace Tests\Feature;

use App\Filament\Pages\LeaseSvodPage;
use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\LeaseContract;
use App\Models\Staff;
use App\Models\User;
use App\Support\LeaseSvod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Ijara shartnomalari svodi: tumanlar / ko'chalar / xodimlar kesimida, takrorlar va uzoqdan tuzilganlar, Excel. */
class LeaseSvodTest extends TestCase
{
    use RefreshDatabase;

    private function contract(array $over): LeaseContract
    {
        return LeaseContract::create(array_merge([
            'party' => LeaseContract::LEGAL, 'tenant_name' => 'A MChJ', 'mfy' => 'Oybek', 'cadastre' => '10:01', 'activity' => 'Savdo',
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'lat' => 41.3, 'lng' => 69.27, 'street_distance_m' => 10,
            'docx_path' => 'contracts/x.docx',
        ], $over)); // $over ustun (masalan boshqa tuman tarifi)
    }

    public function test_summary_by_district_with_duplicates_remote_and_excel(): void
    {
        $mir = District::create(['name' => 'Mirobod tumani', 'phone' => '901000001']);
        $chil = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000002']);
        District::create(['name' => 'Bektemir tumani', 'phone' => '901000003']); // shartnomasiz — 0 bilan chiqadi
        $st = GastroStreet::create(['district_id' => $mir->id, 'name' => 'Taras Shevchenko', 'line' => [[41.29, 69.27], [41.30, 69.27]]]);
        $st2 = GastroStreet::create(['district_id' => $chil->id, 'name' => 'Qatortol', 'line' => [[41.28, 69.20], [41.29, 69.21]]]);
        $s = Staff::create(['telegram_id' => 1, 'full_name' => 'Sagdullaev', 'phone' => '993313441', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        GastroResponsible::create(['district_id' => $mir->id, 'tariff' => 62073.19])->staff()->attach($s);
        $base = ['staff_id' => $s->id, 'tariff' => 62073.19];

        // Mirobod: 2 ta (biri qayta tuzilgan — takror), 1 jismoniy; Chilonzor: 1 ta, uzoqdan tuzilgan
        $this->contract([...$base, 'district_id' => $mir->id, 'gastro_street_id' => $st->id, 'area_m2' => 50, 'annual_amount' => 100]);
        $this->contract([...$base, 'district_id' => $mir->id, 'gastro_street_id' => $st->id, 'area_m2' => 58.5, 'annual_amount' => 21787689.72]);
        $this->contract([...$base, 'district_id' => $mir->id, 'gastro_street_id' => $st->id, 'area_m2' => 20, 'annual_amount' => 6207319,
            'party' => LeaseContract::INDIVIDUAL, 'tenant_name' => 'Kadirov', 'cadastre' => '10:02', 'start_date' => '2026-08-01']);
        $this->contract([...$base, 'district_id' => $chil->id, 'gastro_street_id' => $st2->id, 'area_m2' => 10, 'annual_amount' => 1000,
            'tenant_name' => 'B', 'cadastre' => '10:03', 'tariff' => 37101.44, 'street_distance_m' => 7500]);
        $this->contract([...$base, 'district_id' => $chil->id, 'gastro_street_id' => $st2->id, 'area_m2' => 99, 'annual_amount' => 9, 'docx_path' => null]); // yarim yozuv — hisoblanmaydi

        $d = LeaseSvod::data();
        $this->assertSame(1, $d['duplicates']);
        $this->assertSame(1, $d['remote']);
        $this->assertSame(3, $d['total']['count']);
        $this->assertSame(88.5, $d['total']['area']);
        $mirRow = collect($d['rows'])->firstWhere('name', 'Mirobod tumani');
        $this->assertSame([2, 1, 1], [$mirRow['count'], $mirRow['legal'], $mirRow['individual']]);
        $this->assertEqualsWithDelta(3631281.62 + 1241463.80, $mirRow['monthly'], 0.01); // oxirgisi (58,5 m²) + jismoniy (20 m²)
        $this->assertEqualsWithDelta($mirRow['monthly'] * 12, $mirRow['yearly'], 0.01);
        $this->assertSame('Sagdullaev', $mirRow['responsibles']);
        $this->assertSame(0, collect($d['rows'])->firstWhere('name', 'Bektemir tumani')['count']);

        $this->assertSame(4, LeaseSvod::data(unique: false)['total']['count']);   // takrorlar bilan
        $this->assertSame(2, LeaseSvod::data(onsite: true)['total']['count']);    // uzoqdan tuzilgan chiqarildi
        $this->assertSame('Taras Shevchenko', LeaseSvod::data()['streets'][0]['name']); // oylik bo'yicha eng katta
        $this->assertEqualsWithDelta(4872745.42 + 371014.40, $d['total']['monthly'], 0.01); // Chilonzor o'z tarifi bilan

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/lease-svod')->assertOk()->assertSee('Tumanlar kesimida')->assertSee('Mirobod tumani')->assertSee('4 872 745'); // Mirobod oylik (3 631 281,62 + 1 241 463,80)
        Livewire::test(LeaseSvodPage::class)->call('preset', 'today')->assertSet('from', now()->toDateString())
            ->set('onsite', true)->assertSee('Chilonzor tumani');

        $res = $this->get(route('admin.lease-svod', ['unique' => 1]))->assertOk();
        $this->assertStringStartsWith('PK', $res->getContent()); // xlsx (zip)
        $zip = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($zip, $res->getContent());
        $z = new \ZipArchive;
        $z->open($zip);
        $this->assertStringContainsString('Mirobod tumani', $z->getFromName('xl/sharedStrings.xml') ?: $z->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertSame(3, substr_count($z->getFromName('xl/workbook.xml'), '<sheet '));
        $z->close();
        @unlink($zip);
    }
}
