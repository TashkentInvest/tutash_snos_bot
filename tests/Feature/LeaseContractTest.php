<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\LeaseContract;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/** Ijara shartnomasi: mas'ul botda to'ldiradi → namunadan Word yasaladi va botga yuboriladi; faqat gastronomik ko'chada. */
class LeaseContractTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 777501;

    private District $district;
    private GastroStreet $street;
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['tutash.soffice_bin' => null]); // testda PDF yasalmaydi
        // sxema fonidagi xarita plitkalari — tarmoqqa chiqilmaydi
        $tile = imagecreatetruecolor(256, 256);
        imagefill($tile, 0, 0, imagecolorallocate($tile, 60, 120, 60));
        ob_start();
        imagepng($tile);
        \Illuminate\Support\Facades\Http::fake(['server.arcgisonline.com/*' => \Illuminate\Support\Facades\Http::response(ob_get_clean())]);
        $this->district = District::create(['name' => 'Mirobod tumani', 'phone' => '901000001']);
        // Taras Shevchenko ko'chasi bo'lagi (~41.300, 69.270 atrofida)
        $this->street = GastroStreet::create(['district_id' => $this->district->id, 'name' => 'Taras Shevchenko', 'line' => [[41.2990, 69.2700], [41.3010, 69.2700]]]);
        $this->staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Sagdullaev Rahmatjon', 'phone' => '993313441', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        GastroResponsible::create(['district_id' => $this->district->id, 'phone' => '993313441', 'tariff' => 62073.19])->staff()->attach($this->staff);
    }

    private function initData(int $uid = self::UID): string
    {
        $data = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => $uid])];
        ksort($data);
        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', (string) config('nutgram.token'), 'WebAppData', true));

        return http_build_query($data);
    }

    private function legal(array $over = []): array
    {
        return $over + [
            'initData' => $this->initData(), 'party' => 'legal', 'district_id' => $this->district->id, 'gastro_street_id' => $this->street->id,
            'lat' => 41.3000, 'lng' => 69.2702, 'accuracy' => 12, // ko'chadan ~17 m, telefon GPS'i
            'tenant_name' => '“GLOBAL PROPERTY DEVELOPMENT” MChJ', 'tenant_address' => 'Toshkent shahri, Mirobod tumani, Taras Shevchenko ko‘chasi, 21A-uy',
            'director' => 'Yermatov Vali Rustamovich', 'account' => '20208000005495269001', 'inn' => '309303138', 'mfo' => '00445', 'phone' => '+998997607007',
            'mfy' => 'Oybek', 'house' => '21A', 'cadastre' => '10:11:40:02:01:0612', 'area_m2' => 58.5, 'activity' => 'Umumiy ovqatlanish',
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'annual_amount' => 1, // e'tiborga olinmaydi — serverda maydon × tarif
            // ko'cha yonidagi ~22 × 12,5 m to'rtburchak
            'polygon' => json_encode([[41.2999, 69.27005], [41.3001, 69.27005], [41.3001, 69.2702], [41.2999, 69.2702]]),
            'photos' => $this->photos(),
        ];
    }

    /** @return UploadedFile[] joyida olingan rasmlar */
    private function photos(int $n = LeaseContract::PHOTOS): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("rasm-{$i}.jpg", 800, 600), range(1, $n));
    }

    /** Mini App multipart (rasmlar bilan) yuboradi. */
    private function save(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post('/tg/lease-form/save', $data, ['Accept' => 'application/json']);
    }

    private function docText(LeaseContract $c): string
    {
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($c->docx_path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse(simplexml_load_string($xml), 'document.xml to‘g‘ri XML bo‘lishi kerak');

        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function test_responsible_makes_legal_contract_and_gets_word_file(): void
    {
        $this->get('/tg/lease-form')->assertOk()->assertSee('Ijara shartnomasi');
        $this->postJson('/tg/lease-form/state', ['initData' => $this->initData()])->assertOk()
            ->assertJsonPath('districts.0.name', 'Mirobod tumani')->assertJsonPath('districts.0.streets.0.name', 'Taras Shevchenko')->assertJsonPath('radius', 300)
            ->assertJsonPath('districts.0.tariff', 62073.19);

        // ko'chadan uzoq — rad (faqat gastronomik ko'chadagi obyektga)
        $this->save($this->legal(['lat' => 41.3000, 'lng' => 69.2800]))->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'uzoqdasiz') && str_contains($m, '300 m'));
        // faqat GPS: aniqligi yo'q yoki past — rad
        $this->save($this->legal(['accuracy' => null]))->assertStatus(422)->assertJsonValidationErrors('accuracy');
        $this->save($this->legal(['accuracy' => 400]))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'GPS aniqligi past'));
        $this->save($this->legal(['director' => null]))->assertStatus(422)->assertJsonValidationErrors('director');
        // hudud chizilmagan / burchagi ko'chadan 300 m dan uzoq / rasm 4 tadan kam — rad
        $this->save($this->legal(['polygon' => null]))->assertStatus(422)->assertJsonValidationErrors('polygon');
        $this->save($this->legal(['polygon' => json_encode([[41.2999, 69.27005], [41.3001, 69.27005], [41.3001, 69.2760]])]))->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '300 m'));
        $this->save($this->legal(['photos' => $this->photos(3)]))->assertStatus(422)->assertJsonValidationErrors('photos');
        GastroResponsible::query()->update(['tariff' => null]);
        $this->save($this->legal())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tarifi kiritilmagan'));
        GastroResponsible::query()->update(['tariff' => 62073.19]);
        $this->assertSame(0, LeaseContract::count());

        $this->save($this->legal())->assertOk()->assertJsonPath('sent', true);
        $c = LeaseContract::sole();
        $this->assertSame('309303138', $c->inn);
        $this->assertTrue(Storage::disk('local')->exists($c->docx_path));
        $this->assertNull($c->pdf_path);
        $this->assertCount(4, $c->photos);
        $this->assertEqualsWithDelta(278, $c->polygon_area_m2, 15);
        foreach ($c->photos as $ph) {
            $this->assertTrue(Storage::disk('local')->exists($ph));
        }

        $t = $this->docText($c);
        $this->assertStringNotContainsString('{{', $t);
        foreach (['“GLOBAL PROPERTY DEVELOPMENT” MÇJ', 'Toşkent şahri, Mirobod tumani, Oybek MFY,', 'Taras Şevçenko ko‘çasi 21A-uy', '10:11:40:02:01:0612',
            '58,50', 'Umumiy ovqatlaniş', '2026 yil “01” iyul dan 2027 yil “30” iyun', '21 787 689,72', '20208000005495269001', '309303138', 'V.R.Yermatov'] as $s) {
            $this->assertStringContainsString($s, $t, $s);
        }
        // to'lov jadvali: iyul–dekabr, 6 oy; jami summa saqlanadi (oxirgi oy farqi bilan)
        foreach (['Iyul', 'Avgust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'] as $m) {
            $this->assertStringContainsString($m, $t);
        }
        // 58,5 m² × 62 073,19 (Mirobod tarifi) = 3 631 281,62 so'm/oy × 6 oy
        $this->assertSame(6, substr_count($t, '3 631 281,62'));
        $this->assertSame(21787689.72, $c->annual_amount);
        $this->assertSame(62073.19, $c->tariff);
        $this->assertStringNotContainsString('Yanvar', $t);
        // ilovalar: sxema (1-ilova) va joyida olingan rasmlar (2-ilova)
        $this->assertStringContainsString('1-ilova', $t);
        $this->assertStringContainsString('2-ilova', $t);
        // maydon — xodim qo'lda kiritgani (chizmadan hisoblangani hujjatda yo'q)
        $this->assertStringContainsString('hudud maydoni: 58,50 kv.m', $t);
        $this->assertStringNotContainsString('sxema bo', $t);
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($c->docx_path));
        $media = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($n) => str_starts_with($n, 'word/media/lease'));
        $this->assertCount(5, $media); // sxema + 4 rasm
        $this->assertStringContainsString('rIdLease', $zip->getFromName('word/_rels/document.xml.rels'));
        // sxema — xarita fonida (sun'iy yo'ldosh plitkalari olindi va keshlandi), JPEG
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'World_Imagery/MapServer/tile/'));
        $this->assertNotEmpty(Storage::disk('local')->allFiles('tiles/World_Imagery'));
        $this->assertNotEmpty($media->filter(fn ($n) => str_ends_with($n, '.jpg'))->all());
        $zip->close();

        // botga Word fayl yuborildi
        $sent = collect(app(Nutgram::class)->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getUri())->implode(' ');
        $this->assertStringContainsString('sendDocument', $sent);
    }

    public function test_individual_contract_uses_individual_template(): void
    {
        $this->save($this->legal([
            'party' => 'individual', 'tenant_name' => 'Kadirov Abduraxmon Abdugafurovich', 'tenant_address' => null, 'director' => null,
            'passport' => 'AD 7739566', 'jshshir' => '31912820020026', 'phone' => '998042075', 'area_m2' => 20, 'activity' => 'Savdo',
            'start_date' => '2026-08-01', 'end_date' => '2027-07-31', 'annual_amount' => 31657316.7, // e'tiborga olinmaydi
        ]))->assertOk();
        $c = LeaseContract::sole();
        $this->assertNull($c->inn);
        $t = $this->docText($c);
        foreach (['KADIROV ABDURAXMON ABDUGAFUROVIÇ', 'yaşovçi fuqaro', 'AD 7739566', '31912820020026', 'A.A.Kadirov', 'hududdagi 20 kv.m', '“Savdo”', '6 207 319,00'] as $s) {
            $this->assertStringContainsString($s, $t, $s);
        }
        $this->assertStringContainsString('___________________________________da yaşovçi', $t); // manzil bo'sh — chiziq qoladi
        $this->assertStringNotContainsString('Iyul', $t); // avgust–dekabr (5 oy)
        $this->assertSame(5, substr_count($t, '1 241 463,80')); // 20 m² × 62 073,19, avgust–dekabr
    }

    public function test_only_responsibles_and_own_districts(): void
    {
        $other = District::create(['name' => 'Yakkasaroy tumani', 'phone' => '901000002']);
        $s2 = GastroStreet::create(['district_id' => $other->id, 'name' => 'Shota Rustaveli', 'line' => [[41.29, 69.26], [41.291, 69.26]]]);
        $this->save($this->legal(['district_id' => $other->id, 'gastro_street_id' => $s2->id, 'lat' => 41.2905, 'lng' => 69.2601]))->assertStatus(403);

        Staff::create(['telegram_id' => 777502, 'full_name' => 'Begona', 'phone' => '900000502', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CONTRACT]);
        $this->postJson('/tg/lease-form/state', ['initData' => $this->initData(777502)])->assertStatus(403);
        $this->assertSame(0, LeaseContract::count());
    }

    /** Admin keraksiz shartnomani o'chiradi: svoddan chiqadi, har doim tiklanadi (butunlay o'chirish yo'q). */
    public function test_admin_deletes_and_restores_contracts(): void
    {
        $this->save($this->legal())->assertOk();
        $this->save($this->legal(['tenant_name' => 'Ikkinchi MChJ', 'cadastre' => '10:11:40:02:01:0999']))->assertOk();
        [$a, $b] = LeaseContract::orderBy('id')->get()->all();
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $list = fn () => \Livewire\Livewire::test(\App\Filament\Resources\LeaseContractResource\Pages\ListLeaseContracts::class);

        $list()->callTableAction('delete', $a);
        $this->assertSoftDeleted($a);
        $this->assertSame(1, \App\Support\LeaseSvod::data()['total']['count']); // svodga kirmaydi
        $list()->assertCanNotSeeTableRecords([$a])->assertCanSeeTableRecords([$b])
            ->filterTable('trashed', false)->assertCanSeeTableRecords([$a]); // «Faqat o'chirilganlar»
        $this->assertTrue(\App\Models\AuditLog::where('event', 'lease.deleted')->exists());

        $list()->filterTable('trashed', false)->callTableAction('restore', LeaseContract::withTrashed()->find($a->id));
        $this->assertNotSoftDeleted($a);

        $list()->callTableBulkAction('delete', [$a, $b]);
        $this->assertSame(0, LeaseContract::count());
        $this->assertTrue(Storage::disk('local')->exists($a->docx_path)); // tiklash mumkin — fayllar joyida

        // butunlay o'chirish yo'q — o'chirilgan har doim tiklanadi
        $list()->filterTable('trashed', false)->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete');
        $list()->filterTable('trashed', false)->callTableBulkAction('restore', LeaseContract::withTrashed()->whereKey([$a->id, $b->id])->get());
        $this->assertSame(2, LeaseContract::count());
        $this->assertNotEmpty(Storage::disk('local')->allFiles("contracts/{$a->id}"));
        $b->delete();
        $this->get('/admin/lease-contracts/'.$b->id)->assertOk(); // o'chirilganini ham ko'rish mumkin (tiklash tugmasi)
    }

    /** «Faqat shartnomalar» foydalanuvchisi: ko'radi, xatoni tuzatadi (Word yangilanadi), o'chira olmaydi; boshqa bo'limlar yopiq. */
    public function test_leases_only_user_views_and_fixes_but_cannot_delete(): void
    {
        $this->save($this->legal())->assertOk();
        $c = LeaseContract::sole();
        $u = (new User)->forceFill(['name' => 'Nizomiddin', 'email' => 'n@t.test', 'password' => 'secret-pass-1', 'leases_only' => true, 'sites_edit' => true]);
        $u->save();
        $this->actingAs($u);

        $this->get('/admin')->assertRedirect(\App\Filament\Resources\LeaseContractResource::getUrl('index'));
        $this->get('/admin/lease-contracts')->assertOk()->assertSee('GLOBAL PROPERTY DEVELOPMENT');
        $this->get('/admin/lease-contracts/'.$c->id)->assertOk();
        $this->get(route('admin.lease-file', ['contract' => $c, 'format' => 'docx']))->assertOk();
        $this->get(route('admin.lease-photo', ['contract' => $c, 'n' => 1]))->assertOk();
        foreach (['/admin/inspections', '/admin/attendances', '/admin/staff', '/admin/report-settings', '/admin/map', '/admin/lease-svod'] as $p) {
            $this->get($p)->assertForbidden();
        }

        $list = \Livewire\Livewire::test(\App\Filament\Resources\LeaseContractResource\Pages\ListLeaseContracts::class);
        $list->assertTableActionHidden('delete', $c)->assertTableActionVisible('edit', $c)->assertTableBulkActionHidden('delete');

        // xato tuzatiladi: ijarachi nomi va maydon — to'lov qayta hisoblanadi, Word yangilanadi
        \Livewire\Livewire::test(\App\Filament\Resources\LeaseContractResource\Pages\EditLeaseContract::class, ['record' => $c->id])
            ->fillForm(['tenant_name' => '“GLOBAL PROPERTY” MChJ', 'area_m2' => 60])->call('save')->assertHasNoFormErrors();
        $c->refresh();
        $this->assertSame(60.0, $c->area_m2);
        $this->assertSame(LeaseContract::amounts(60, 62073.19, $c->start_date)['annual'], $c->annual_amount);
        $this->assertStringContainsString('“GLOBAL PROPERTY” MÇJ', $this->docText($c));
        $this->assertTrue(\App\Models\AuditLog::where('event', 'lease.edited')->exists());
        $this->assertNull($c->deleted_at);

        // har bir tahrir bazada: kim, qachon, eski → yangi
        $log = \App\Models\AuditLog::where('event', 'panel.lease.updated')->where('context->id', $c->id)->oldest('id')->first();
        $this->assertSame('Nizomiddin', $log->context['user']);
        $this->assertSame(['“GLOBAL PROPERTY DEVELOPMENT” MChJ', '“GLOBAL PROPERTY” MChJ'], $log->context['changes']['tenant_name']);
        $this->get('/admin/lease-contracts/'.$c->id)->assertOk()->assertSee('O‘zgarishlar tarixi')->assertSee('tahrirladi');

        // obyektlar: ko'radi va tahrirlaydi; qo'sha / o'chira olmaydi; tahrir tarixga yoziladi
        $site = \App\Models\Site::create(['district_id' => $this->district->id, 'source_no' => 77, 'address' => 'Eski manzil 1', 'lat' => 41.3, 'lng' => 69.27]);
        $this->get('/admin/sites')->assertOk()->assertSee('Eski manzil 1');
        $this->get('/admin/sites/'.$site->id)->assertOk();
        $this->assertFalse(\App\Filament\Resources\SiteResource::canCreate());
        $this->assertFalse(\App\Filament\Resources\SiteResource::canDelete($site));
        $this->get('/admin/sites/create')->assertForbidden();
        \Livewire\Livewire::test(\App\Filament\Resources\SiteResource\Pages\EditSite::class, ['record' => $site->id])
            ->assertActionHidden('delete')
            ->fillForm(['address' => 'Yangi manzil 1'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Yangi manzil 1', $site->refresh()->address);
        $slog = \App\Models\AuditLog::where('event', 'panel.site.updated')->where('context->id', $site->id)->sole();
        $this->assertSame(['Eski manzil 1', 'Yangi manzil 1'], $slog->context['changes']['address']);
        $this->assertSame($u->id, $slog->context['user_id']);
        $this->get('/admin/sites/'.$site->id)->assertSee('O‘zgarishlar tarixi')->assertSee('Yangi manzil 1');
    }

    /** Oy o'rtasida boshlansa: birinchi oy kunlar bo'yicha (boshlanish kuni ham kiradi), keyingilari to'liq. */
    public function test_first_month_is_prorated_by_days(): void
    {
        $this->save($this->legal(['party' => 'individual', 'tenant_name' => 'Kadirov Abduraxmon', 'tenant_address' => null, 'director' => null,
            'passport' => 'AD 7739566', 'jshshir' => '31912820020026', 'area_m2' => 20, 'start_date' => '2026-11-10', 'end_date' => '2027-11-09']))->assertOk();
        $c = LeaseContract::sole();
        // 20 m² × 62 073,19 = 1 241 463,80/oy; noyabr: 10..30 = 21 kun → 21/30; dekabr — to'liq
        $this->assertSame(2110488.46, $c->annual_amount);
        $this->assertSame([[11, 869024.66, 21], [12, 1241463.8]], $c->schedule());
        $t = $this->docText($c);
        $this->assertStringContainsString('Noyabr (21 kun)', $t);
        $this->assertStringContainsString('869 024,66', $t);
        $this->assertStringContainsString('1 241 463,80', $t);
        $this->assertStringContainsString('2 110 488,46', $t);

        // 1-sanada boshlansa — to'liq oy
        $a = LeaseContract::amounts(20, 62073.19, \Carbon\Carbon::parse('2026-12-01'));
        $this->assertSame([1241463.8, 31, 1241463.8], [$a['first'], $a['first_days'], $a['annual']]);
        // oyning oxirgi kuni — 1 kun
        $this->assertSame(1, LeaseContract::amounts(20, 62073.19, \Carbon\Carbon::parse('2026-02-28'))['first_days']);
    }

    /** Xarita xizmati javob bermasa — sxema oq fonda chiziladi, shartnoma baribir tayyor. */
    public function test_scheme_falls_back_to_plain_background_when_map_service_fails(): void
    {
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory); // setUp dagi plitkalar o'rniga — xizmat ishlamaydi
        \Illuminate\Support\Facades\Http::fake(['server.arcgisonline.com/*' => \Illuminate\Support\Facades\Http::response('', 503)]);
        $this->save($this->legal())->assertOk()->assertJsonPath('sent', true);
        $this->assertSame([], Storage::disk('local')->allFiles('tiles'));
        $this->assertStringContainsString('1-ilova', $this->docText(LeaseContract::sole()));
    }

    /** Production xatosi: soffice'ga ruxsat yo'q (open_basedir) — PDF bo'lmaydi, Word baribir yuboriladi. */
    public function test_unavailable_pdf_converter_does_not_break_contract(): void
    {
        config(['tutash.soffice_bin' => '/nonexistent/soffice']);
        $this->save($this->legal())->assertOk()->assertJsonPath('sent', true);
        $c = LeaseContract::sole();
        $this->assertNotNull($c->docx_path);
        $this->assertNull($c->pdf_path);
    }

    /** Word yasalmasa — yarim saqlangan shartnoma va rasmlar qolmaydi (qayta urinishda dublikat ham bo'lmaydi). */
    public function test_failed_render_leaves_no_half_saved_contract(): void
    {
        $tpl = resource_path('contracts/lease-legal.docx');
        rename($tpl, $tpl.'.bak');
        try {
            $this->save($this->legal())->assertStatus(500)->assertJsonPath('message', fn ($m) => str_contains($m, 'tayyorlab bo‘lmadi'));
        } finally {
            rename($tpl.'.bak', $tpl);
        }
        $this->assertSame(0, LeaseContract::count());
        $this->assertSame([], Storage::disk('local')->allFiles('contracts'));

        $this->save($this->legal())->assertOk(); // namuna joyida — ishlaydi
        $this->assertSame(1, LeaseContract::count());
    }

    /** Admin «Faqat ko'cha yonida» cheklovini o'chirsa — shartnoma uzoqdan (onlayn) ham to'ldiriladi. */
    public function test_lease_location_limit_can_be_turned_off(): void
    {
        $far = ['lat' => 41.3500, 'lng' => 69.3500, 'accuracy' => 900,
            'polygon' => json_encode([[41.35, 69.35], [41.3502, 69.35], [41.3502, 69.3503]])];
        $this->save($this->legal($far))->assertStatus(422);

        \App\Support\Limits::save(['lease_check' => false]);
        $this->postJson('/tg/lease-form/state', ['initData' => $this->initData()])->assertJsonPath('check', false);
        $this->save($this->legal($far))->assertOk();
        $this->assertGreaterThan(5000, LeaseContract::sole()->street_distance_m); // qayerdan yuborilgani saqlanadi
    }

    /** Ko'chadan uzoq joylashuv «Xatolikka uchraganlar»ga yoziladi; tumanning boshqa gastro ko'chasi yonida bo'lsa — o'sha ko'chaga. */
    public function test_far_location_is_logged_and_nearby_other_street_is_used(): void
    {
        // ko'chadan ~420 m (ruxsat 300 m): oyna joylashuv olganda xabar beradi
        $far = ['initData' => $this->initData(), 'gastro_street_id' => $this->street->id, 'lat' => 41.3000, 'lng' => 69.2750, 'accuracy' => 10];
        $this->postJson('/tg/lease-form/reject', $far)->assertOk()->assertJsonPath('logged', true);
        $this->postJson('/tg/lease-form/reject', $far)->assertOk()->assertJsonPath('logged', false); // 2 daqiqada bir marta
        $r = \App\Models\LocationRejection::sole();
        $this->assertSame(['lease', \App\Models\LocationRejection::FAR_FROM_STREET, $this->staff->id], [$r->source, $r->reason, $r->staff_id]);
        $this->assertEqualsWithDelta(420, $r->distance_m, 10);
        $this->assertEqualsWithDelta(69.27, $r->site_lng, 0.0001); // ko'chaning eng yaqin nuqtasi
        // ko'cha yonida — yozilmaydi; boshqa tuman ko'chasi — e'tiborsiz
        $this->postJson('/tg/lease-form/reject', ['lat' => 41.3000, 'lng' => 69.2702] + $far)->assertJsonPath('logged', false);

        $admin = (new \App\Models\User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/location-rejections')->assertOk()->assertSee('Ijara shartnomasi')->assertSee('Gastro ko‘chadan uzoq')->assertSee('«Taras Shevchenko» ko‘chasi');
        $this->get('/admin/location-rejections/'.$r->id)->assertOk()->assertSee('Ko‘chagacha')->assertSee('Ko‘chaning eng yaqin nuqtasi');
        $this->get('/admin/location-limits')->assertOk()->assertSee('Faqat ko‘cha yonida');
        $this->get('/admin/report-settings')->assertOk()->assertDontSee('Faqat ko‘cha yonida');

        // botda Taras Shevchenko tanlangan, xodim esa Fidokor yonida — shartnoma Fidokorga yoziladi
        $fid = GastroStreet::create(['district_id' => $this->district->id, 'name' => 'Fidokor', 'line' => [[41.2990, 69.2745], [41.3010, 69.2745]]]);
        $this->save($this->legal(['lat' => 41.3000, 'lng' => 69.2746, 'polygon' => json_encode([[41.2999, 69.27455], [41.3001, 69.27455], [41.3001, 69.2747], [41.2999, 69.2747]])]))->assertOk();
        $this->assertSame($fid->id, LeaseContract::sole()->gastro_street_id);
        $this->assertLessThan(20, LeaseContract::sole()->street_distance_m);
    }

    /** Barcha tumanlarga biriktirilgan xodim ham mas'ul bo'lsa — tumanlar ro'yxati ustida shartnoma tugmasi. */
    public function test_global_staff_who_is_responsible_sees_lease_button(): void
    {
        config(['tutash.all_districts_employee_phones' => '901756545']);
        $xayit = Staff::create(['telegram_id' => 777503, 'full_name' => 'Xayit', 'phone' => '901756545', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true]);
        District::create(['name' => 'Bektemir tumani', 'phone' => '901000005']);
        GastroResponsible::where('district_id', $this->district->id)->sole()->staff()->attach($xayit);

        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TgUser::make(id: 777503, is_bot: false, first_name: 'X'));
        $bot->setCommonChat(Chat::make(id: 777503, type: 'private'));
        $bot->hearText('/start')->reply();
        $sent = collect($bot->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getBody())->implode("\n");
        $this->assertStringContainsString('"callback_data":"ls"', $sent);
        $this->assertStringContainsString('Ijara shartnomalari', $sent);
    }

    public function test_bot_home_shows_lease_button_and_admin_lists_downloads(): void
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TgUser::make(id: self::UID, is_bot: false, first_name: 'R'));
        $bot->setCommonChat(Chat::make(id: self::UID, type: 'private'));
        $sent = function () use ($bot) {
            return collect($bot->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getBody())->implode("\n");
        };
        // obyektlar kabi tugmalar: bosh sahifa → (bitta tuman — darrov) ko'chalar → obyekt oldida to'ldirish
        $bot->hearText('/start')->reply();
        $this->assertStringContainsString('"callback_data":"ls"', $sent());
        $this->assertStringContainsString('Mirobod tumani', $sent());
        $bot->hearCallbackQueryData('ls')->reply();
        $this->assertStringContainsString('"callback_data":"lst:'.$this->street->id.'"', $sent());
        $bot->hearCallbackQueryData('lst:'.$this->street->id)->reply();
        $this->assertStringContainsString('tg\/lease-form?street='.$this->street->id, $sent());
        $this->assertStringContainsString('300 m ichida', $sent());
        // cheklov o'chirilsa — botdagi yozuv ham shunga mos
        \App\Support\Limits::save(['lease_check' => false]);
        $bot->hearCallbackQueryData('lst:'.$this->street->id)->reply();
        $this->assertStringContainsString('uzoqdan ham', $sent());
        \App\Support\Limits::save(['lease_check' => true]);
        // begona ko'cha — javob yo'q
        $other = GastroStreet::create(['district_id' => District::create(['name' => 'Yakkasaroy tumani', 'phone' => '901000009'])->id, 'name' => 'Shota Rustaveli', 'line' => [[41.29, 69.26], [41.291, 69.26]]]);
        $bot->hearCallbackQueryData('lst:'.$other->id)->reply();
        $this->assertStringNotContainsString('street='.$other->id, $sent());

        $this->save($this->legal())->assertOk();
        $c = LeaseContract::sole();
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $this->get('/admin/lease-contracts')->assertOk()->assertSee('GLOBAL PROPERTY DEVELOPMENT')->assertSee('Taras Shevchenko');
        // ko'chalar xaritasi: shartnoma nuqtasi (yuridik) va ko'cha
        $this->get('/admin/gastro-map')->assertOk()->assertSee('GLOBAL PROPERTY DEVELOPMENT')->assertSee('Taras Shevchenko')->assertSee('Ko‘chalar rangi');
        // ismi bo'sh xodim — «Tayyorlagan» filtri 500 bermasin (production'dagi xato)
        $this->staff->forceFill(['full_name' => null])->save();
        $this->get('/admin/lease-contracts')->assertOk()->assertSee('993313441');
        $this->staff->forceFill(['full_name' => 'Sagdullaev Rahmatjon'])->save();
        $this->get(route('admin.lease-file', ['contract' => $c, 'format' => 'docx']))->assertOk()->assertDownload('ijara-shartnoma-'.$c->id.'.docx');
        $this->get(route('admin.lease-file', ['contract' => $c, 'format' => 'pdf']))->assertNotFound();
        $this->get('/admin/lease-contracts/'.$c->id)->assertOk()->assertSee('Tutash hudud')->assertSee(route('admin.lease-photo', ['contract' => $c, 'n' => 1]), false);
        $this->get(route('admin.lease-photo', ['contract' => $c, 'n' => 4]))->assertOk();
        $this->get(route('admin.lease-photo', ['contract' => $c, 'n' => 5]))->assertNotFound();
    }
}
