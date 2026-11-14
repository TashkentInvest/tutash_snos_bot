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
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($c->docx_path));
        $media = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($n) => str_starts_with($n, 'word/media/lease'));
        $this->assertCount(5, $media); // sxema + 4 rasm
        $this->assertStringContainsString('rIdLease', $zip->getFromName('word/_rels/document.xml.rels'));
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
