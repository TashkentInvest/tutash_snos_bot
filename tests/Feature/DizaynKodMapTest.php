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
        $this->assertSame('Ko‘cha 34 · 10,3 km', $s['tip']);
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
            ->assertJson(['lines' => [], 'count' => 0])->assertJsonPath('note', 'Dizayn-kod API sozlanmagan (DIZAYN_KOD_TOKEN bo‘sh).');
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
