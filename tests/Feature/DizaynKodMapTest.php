<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\User;
use App\Support\DizaynKodStreets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** «Umumiy xarita» → «Dizayn-kod ko‘chalari»: boshqa tizim API'sidan (token serverda), tuman bo'yicha filtr, xato bo'lsa yiqilmaydi. */
class DizaynKodMapTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://dk.test/api/v1/streets';

    /** SNOS'dagi 12 tuman (apostrofi oddiy) */
    private const DISTRICTS = ['Bektemir tumani', 'Chilonzor tumani', "Mirobod tumani", "Mirzo Ulug'bek tumani", 'Olmazor tumani', 'Sergeli tumani',
        'Shayxontohur tumani', 'Uchtepa tumani', 'Yakkasaroy tumani', 'Yangihayot tumani', 'Yashnobod tumani', 'Yunusobod tumani'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dizayn_kod.url' => self::URL, 'services.dizayn_kod.token' => 'test-token-123']);
        Cache::flush();
    }

    private function feature(int $id, string $district, array $coords, array $props = []): array
    {
        return ['type' => 'Feature', 'id' => $id, 'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
            'properties' => $props + ['id' => $id, 'name' => 'Кўча '.$id, 'name_latin' => 'Ko‘cha '.$id, 'district_id' => 99, 'district' => 'Тумани',
                'district_latin' => $district, 'length_km' => 10.3, 'drawn_km' => 9.03, 'has_shape' => true,
                'objects_total' => 147, 'objects_installed' => 0, 'objects_removed' => 0, 'pct' => 0]];
    }

    private function admin(): User
    {
        $u = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $u->save();

        return $u;
    }

    public function test_layer_reads_api_with_token_converts_coordinates_and_filters_by_district(): void
    {
        $mu = District::create(['name' => "Mirzo Ulug'bek tumani", 'phone' => '901000001']);
        $ch = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000002']);
        Http::fake([self::URL => Http::response(['type' => 'FeatureCollection', 'features' => [
            $this->feature(34, 'Mirzo Ulug‘bek tumani', [[69.1654474, 41.2362454], [69.1702, 41.2401]]),
            $this->feature(35, 'Chilonzor tumani', [[69.2, 41.28], [69.21, 41.29]], ['has_shape' => false, 'length_km' => null, 'objects_installed' => 3, 'pct' => 40]),
            $this->feature(36, 'Chilonzor tumani', [[69.22, 41.28], [69.23, 41.29]], ['objects_installed' => 10, 'pct' => 100]),
        ]])]);
        $this->actingAs($this->admin());

        $layer = fn (array $q = []) => $this->getJson(route('admin.map-layer', ['layer' => 'designcode'] + $q))->assertOk()->json();
        $all = $layer();
        $this->assertSame(3, $all['count']);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->hasHeader('Authorization', 'Bearer test-token-123'));

        $s = $all['lines'][0];
        $this->assertSame([[41.236245, 69.165447], [41.2401, 69.1702]], $s['line']); // [lon, lat] → [lat, lon]
        $this->assertSame('new', $s['c']);
        $this->assertTrue($s['shape']);
        $this->assertSame('Ko‘cha 34 · 10,3 km · 0 ta noqonuniy obyekt', $s['tip']);
        $this->assertContains('Uzunligi: 10,3 km', $s['rows']);
        $this->assertContains('Obyektlar: 147 · dizayn-kodga keltirildi: 0 · jarayonda: 0', $s['rows']);
        $this->assertContains('Bajarilish: 0%', $s['rows']);
        $this->assertSame('xaritada chizilgani: 9,03 km', $s['small']);
        $this->assertSame(['progress', false, 'Uzunligi: —'], [$all['lines'][1]['c'], $all['lines'][1]['shape'], $all['lines'][1]['rows'][1]]);
        $this->assertSame('done', $all['lines'][2]['c']);

        // tuman filtri: API'dagi «Ulug‘bek» (U+2018) SNOS'dagi «Ulug'bek» ga mos
        $this->assertSame([34], array_column($layer(['district' => $mu->id])['lines'], 'id'));
        $this->assertSame([35, 36], array_column($layer(['district' => $ch->id])['lines'], 'id'));
        Http::assertSentCount(1); // 10 daqiqa keshlanadi

        $this->get('/admin/map')->assertOk()->assertSee('Dizayn-kod ko‘chalari')->assertSee('to‘liq bajarilgan');
    }

    /** Ko'cha chizig'idan 50 m ichidagi SNOS obyektlari: xarita popupida va svodda (tuman → ko'chalar); chorrahadagi obyekt jamida bir marta. */
    public function test_snos_objects_along_streets_in_popup_and_svod(): void
    {
        $ch = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000002']);
        // ikki ko'cha: sharqqa (41.28 kenglikda) va shimolga (69.20 uzunlikda), 41.28/69.20 da kesishadi
        Http::fake([self::URL => Http::response(['features' => [
            $this->feature(1, 'Chilonzor tumani', [[69.20, 41.28], [69.22, 41.28]], ['objects_total' => 10, 'objects_installed' => 4]),
            $this->feature(2, 'Chilonzor tumani', [[69.20, 41.28], [69.20, 41.30]], ['objects_total' => 6, 'objects_installed' => 0]),
        ]])]);
        $site = fn (float $lat, float $lng, string $status = \App\Models\Site::NOT_DEMOLISHED) => \App\Models\Site::create(['district_id' => $ch->id,
            'source_no' => random_int(1, 99999), 'address' => 'x', 'lat' => $lat, 'lng' => $lng, 'status' => $status]);
        $site(41.2803, 69.21);                                       // 1-ko'chadan ~33 m
        $site(41.2802, 69.215, \App\Models\Site::DEMOLISHED);         // 1-ko'chadan ~22 m, buzilgan
        $site(41.2801, 69.2001);                                     // chorraha — ikkala ko'chaga ham
        $site(41.29, 69.2004);                                       // 2-ko'chadan ~34 m
        $site(41.2815, 69.21);                                       // ~166 m — 50 m da hech biriga, 200 m da 1-ko'chaga
        $site(41.2801, 69.205, \App\Models\Site::EXEMPT);             // sud — jamiga kirmaydi
        $this->actingAs($this->admin());

        $lines = collect($this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertOk()->json('lines'))->keyBy('id');
        $this->assertContains('Noqonuniy obyektlar (50 m ichida): 3 · buzilgan: 1 · qolgan: 2', $lines[1]['rows']);
        $this->assertStringEndsWith('· 3 ta noqonuniy obyekt', $lines[1]['tip']);
        $this->assertContains('Noqonuniy obyektlar (50 m ichida): 2 · buzilgan: 0 · qolgan: 2', $lines[2]['rows']);
        $this->assertStringContainsString('designcode-svod?district='.$ch->id, $lines[1]['u']);

        $d = \App\Support\DesignCodeSvod::build(50, $ch->id);
        $this->assertEquals(['total' => 4, 'demolished' => 1, 'left' => 3, 'pct' => 25.0], array_intersect_key($d['total']['snos'], array_flip(['total', 'demolished', 'left', 'pct'])));
        $this->assertSame(1, $d['total']['snos']['exempt']);
        $this->assertSame([16, 4, 25.0], [$d['total']['dk_total'], $d['total']['dk_installed'], $d['total']['dk_pct']]);
        $this->assertSame(4, $d['districts'][0]['snos']['total']);
        $this->assertSame([1, 2], array_column($d['streets'], 'id')); // ko'p obyektlisi tepada
        $this->assertSame(5, \App\Support\DesignCodeSvod::build(200)['total']['snos']['total']); // 200 m — uzoqdagisi ham

        $this->get('/admin/designcode-svod')->assertOk()->assertSee('Toshkent shahar jami')->assertSee('Chilonzor tumani')->assertSee('25%');
        \Livewire\Livewire::test(\App\Filament\Pages\DesignCodeSvodPage::class)->call('pick', $ch->id)
            ->assertSee('Chilonzor tumani — ko‘chalar kesimida')->assertSee('Ko‘cha 1')->assertSee('Ko‘cha 2')->assertSee('40%');
    }

    public function test_all_twelve_districts_match_api_names(): void
    {
        foreach (self::DISTRICTS as $name) {
            $api = str_replace("'", '‘', $name); // API: lotin, U+2018
            $this->assertSame(DizaynKodStreets::districtKey($name), DizaynKodStreets::districtKey($api), $name);
            $this->assertNotSame('', DizaynKodStreets::districtKey($name));
        }
        $keys = array_map([DizaynKodStreets::class, 'districtKey'], self::DISTRICTS);
        $this->assertCount(12, array_unique($keys));
        $this->assertSame('mirzo ulugbek', DizaynKodStreets::districtKey('Mirzo  Ulugʻbek Tumani'));
    }

    public function test_api_errors_do_not_break_the_map(): void
    {
        District::create(['name' => 'Chilonzor tumani', 'phone' => '901000002']);
        $this->actingAs($this->admin());

        $mode = '500';
        Http::fake(function () use (&$mode) {
            return match ($mode) {
                '500' => Http::response(['message' => 'Server Error'], 500),
                '401' => Http::response(['message' => 'Unauthenticated.'], 401),
                'ok' => Http::response(['features' => [$this->feature(1, 'Chilonzor tumani', [[69.2, 41.28], [69.21, 41.29]])]]),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
            };
        });
        $this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertOk()->assertJson(['lines' => [], 'count' => 0]);
        $this->get('/admin/map')->assertOk();

        // timeout — oxirgi muvaffaqiyatli nusxa qaytadi
        Cache::flush();
        $mode = 'ok';
        $this->assertCount(1, DizaynKodStreets::all());
        Cache::forget(DizaynKodStreets::CACHE);
        $mode = 'timeout';
        $this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertOk()->assertJson(['count' => 1]);

        // 401 (token noto'g'ri) va nusxa yo'q — bo'sh
        Cache::flush();
        $mode = '401';
        $this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertOk()->assertJson(['count' => 0]);
    }

    public function test_no_request_without_token(): void
    {
        config(['services.dizayn_kod.token' => '']);
        Http::fake();
        $this->actingAs($this->admin());

        $this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertOk()
            ->assertJson(['lines' => [], 'count' => 0])->assertJsonPath('note', 'Dizayn-kod tizimi bilan ulanish hozircha sozlanmagan.');
        $this->assertSame([], DizaynKodStreets::all());
        Http::assertNothingSent();
    }

    public function test_layer_is_admin_only(): void
    {
        Http::fake();
        $this->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertUnauthorized();

        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer)->getJson(route('admin.map-layer', ['layer' => 'designcode']))->assertForbidden();
        Http::assertNothingSent();
    }
}
