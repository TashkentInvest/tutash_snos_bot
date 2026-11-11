<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/** Kadastr xodimi: o'z tumanini ko'radi, botdan (Mini App) obyekt qo'shadi; admin tasdiqlaguncha obyekt hech qayerda ko'rinmaydi. */
class CadastreTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 999001;

    private District $district;
    private District $other;
    private Mahalla $mahalla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfWeek()->setTime(11, 0));
        $this->district = District::create(['name' => 'Chilonzor tumani', 'phone' => '974100313']);
        $this->other = District::create(['name' => 'Sergeli tumani', 'phone' => '992340101']);
        $this->mahalla = Mahalla::create(['district_id' => $this->district->id, 'name' => 'Bo‘rijar MFY']);
        Site::create(['district_id' => $this->district->id, 'mahalla_id' => $this->mahalla->id, 'source_no' => 1, 'address' => 'Eski obyekt 1', 'area_m2' => 100]);
    }

    private function bot(): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TgUser::make(id: self::UID, is_bot: false, first_name: 'K'));
        $bot->setCommonChat(Chat::make(id: self::UID, type: 'private'));

        return $bot;
    }

    private function sent(): string
    {
        return collect($this->bot()->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getBody())->implode("\n");
    }

    private function initData(): string
    {
        $data = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => self::UID])];
        ksort($data);
        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', (string) config('nutgram.token'), 'WebAppData', true));

        return http_build_query($data);
    }

    private function form(string $path, array $data = [])
    {
        return $this->postJson('/tg/site-form/'.$path, ['initData' => $this->initData()] + $data);
    }

    private function admin(): User
    {
        $u = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $u->save();

        return $u;
    }

    private function newSite(array $over = []): array
    {
        return $over + [
            'district_id' => $this->district->id, 'mahalla_id' => $this->mahalla->id, 'address' => 'Yangi ko‘cha 5-uy oldi',
            'lat' => 41.30, 'lng' => 69.25, 'area_m2' => 75, 'is_tutash' => true, 'violator_name' => 'Kadastr Test MCHJ',
            'violator_passport' => 'AB1234567', 'org_inn' => '301234567', 'court_number' => '2-1001/1', 'court_date' => '2025-10-01',
        ];
    }

    public function test_cadastre_staff_adds_site_from_bot_and_admin_approves(): void
    {
        // admin kadastr xodimini oldindan qo'shadi (tuman bilan) — botga kirganda tasdiqlanadi
        \App\Support\StaffInvites::add('901112233', 'Kadastr Xodim', Staff::ROLE_CADASTRE, [$this->district->id], null);
        $this->bot()->hearText('/start')->reply();
        $this->bot()->hearCallbackQueryData('role:employee')->reply();
        $this->bot()->hearText('Kadastrov Kadastr Kadastrovich')->reply();
        $this->bot()->hearMessage(['contact' => ['phone_number' => '+998 90 111 22 33', 'first_name' => 'K', 'user_id' => self::UID]])->reply();
        $staff = Staff::where('telegram_id', self::UID)->first();
        $this->assertSame(Staff::ROLE_CADASTRE, $staff->role);
        $this->assertTrue($staff->isActive());
        $this->assertFalse((bool) $staff->attendance_required); // kadastrga davomat yuritilmaydi
        $this->assertSame([$this->district->id], $staff->districts()->pluck('districts.id')->all());

        // /start — qo'shish va "men qo'shganlar" tugmalari; tekshiruv tugmasi yo'q
        $this->bot()->hearText('/start')->reply();
        $this->assertStringContainsString('tg\/site-form', $this->sent());
        $this->assertStringContainsString('cad:mine', $this->sent());
        $site1 = Site::first();
        $this->bot()->hearCallbackQueryData("s:{$site1->id}")->reply();
        $this->assertStringNotContainsString('go:', $this->sent());
        $this->bot()->hearCallbackQueryData("go:{$site1->id}")->reply();
        $this->assertSame(0, Inspection::count());

        // Mini App: tumanlari va MFY lari
        $this->get('/tg/site-form')->assertOk()->assertSee('Yangi obyekt');
        $this->form('state')->assertOk()->assertJsonPath('districts.0.name', 'Chilonzor tumani')->assertJsonPath('districts.0.mahallas.0.name', 'Bo‘rijar MFY');
        $this->form('save', $this->newSite(['district_id' => $this->other->id]))->assertStatus(403); // o'z tumani emas
        $this->form('save', $this->newSite(['mahalla_id' => null]))->assertStatus(422);              // MFY yo'q
        $this->form('save', $this->newSite(['area_m2' => null]))->assertStatus(422)->assertJsonValidationErrors('area_m2');

        // yangi MFY nomi bilan qo'shish
        $id = $this->form('save', $this->newSite(['mahalla_id' => null, 'mahalla_name' => 'Yangi MFY']))->assertOk()->json('id');
        $site = Site::withoutGlobalScope('approved')->find($id);
        $this->assertSame(Site::APPROVAL_PENDING, $site->approval);
        $this->assertSame($staff->id, $site->added_by_staff_id);
        $this->assertSame('AB1234567', $site->violator_passport);
        $this->assertSame('Yangi MFY', $site->mahalla->name);
        $this->assertStringContainsString('Obyekt yuborildi', $this->sent());

        // tasdiqlanguncha: ro'yxatda, svodda, tuman sonida — yo'q
        $this->assertSame(1, Site::count());
        $this->assertSame(1, \App\Support\Svod::data()['total']['all']);
        $this->assertSame(1, $this->district->sites()->count());

        // admin: rad etadi (sabab) — xodimga tahrirlash tugmasi bilan boradi
        $this->actingAs($this->admin());
        $this->get('/admin/sites')->assertOk()->assertSee('Tasdiq kutilmoqda')->assertSee('Kadastr: Kadastr Xodim');
        \Livewire\Livewire::test(\App\Filament\Resources\SiteResource\Pages\ListSites::class)
            ->callTableAction('rejectSite', $site, ['note' => 'Maydoni noto‘g‘ri, qayta o‘lchang'])->assertHasNoTableActionErrors();
        $this->assertSame(Site::APPROVAL_REJECTED, $site->refresh()->approval);
        $this->assertStringContainsString('rad etildi', $this->sent());
        $this->assertStringContainsString('site-form?site='.$site->id, str_replace('\\/', '/', $this->sent()));

        // xodim: "men qo'shganlar" va tahrirlab qayta yuborish — yana tasdiqqa
        $this->bot()->hearCallbackQueryData('cad:mine')->reply();
        $this->assertStringContainsString('rad etildi', $this->sent());
        $this->assertStringContainsString('Maydoni noto', $this->sent());
        $this->form('state', ['site' => $site->id])->assertOk()->assertJsonPath('site.approval', 'rejected');
        $this->form('save', $this->newSite(['site' => $site->id, 'area_m2' => 82]))->assertOk();
        $this->assertSame(Site::APPROVAL_PENDING, $site->refresh()->approval);
        $this->assertSame(82.0, $site->area_m2);

        // admin tasdiqlaydi — endi hamma joyda
        \Livewire\Livewire::test(\App\Filament\Resources\SiteResource\Pages\ListSites::class)
            ->callTableAction('approveSite', $site)->assertHasNoTableActionErrors();
        $this->assertNull($site->refresh()->approval);
        $this->assertSame(2, Site::count());
        $this->assertSame(2, \App\Support\Svod::data()['total']['all']);
        $this->assertStringContainsString('tasdiqlandi', $this->sent());

        // tasdiqlangan — tahrirlab bo'lmaydi
        $this->form('state', ['site' => $site->id])->assertStatus(404);
        $this->get("/admin/sites/{$site->id}")->assertOk()->assertSee('Kadastr qo‘shgan');
    }

    public function test_cadastre_form_is_only_for_cadastre_staff(): void
    {
        Staff::create(['telegram_id' => self::UID, 'full_name' => 'Oddiy Xodim', 'phone' => '901112233', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $this->form('state')->assertStatus(403);
        $this->form('save', $this->newSite())->assertStatus(403);
        $this->assertSame(0, Site::withoutGlobalScope('approved')->where('approval', Site::APPROVAL_PENDING)->count());
    }

    public function test_admin_sets_cadastre_role_and_districts_from_staff_form(): void
    {
        $staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Xodim', 'phone' => '901112233', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $this->actingAs($this->admin());
        \Livewire\Livewire::test(\App\Filament\Resources\StaffResource\Pages\ManageStaff::class)
            ->mountTableAction('edit', $staff)
            ->setTableActionData(['role' => Staff::ROLE_CADASTRE, 'district_ids' => [(string) $this->other->id]])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $staff->refresh();
        $this->assertTrue($staff->isCadastre());
        $this->assertSame([$this->other->id], $staff->districts()->pluck('districts.id')->all());
    }

    /** Tumanni olib tashlab saqlash: raqami tuman mas'uli, «barcha tumanlar» ro'yxatida yoki eski is_global bo'lsa ham saqlanadi. */
    public function test_removing_districts_from_staff_form_is_saved(): void
    {
        config(['tutash.all_districts_employee_phones' => '901112233']);
        $this->district->update(['phone' => '901112233']); // shu xodim Chilonzor mas'uli
        $staff = Staff::create(['telegram_id' => self::UID, 'full_name' => 'Xodim', 'phone' => '901112233', 'status' => Staff::ACTIVE,
            'role' => Staff::ROLE_CADASTRE, 'is_global' => true]);
        $staff->districts()->sync([$this->district->id, $this->other->id]);
        $this->actingAs($this->admin());

        \Livewire\Livewire::test(\App\Filament\Resources\StaffResource\Pages\ManageStaff::class)
            ->mountTableAction('edit', $staff)
            ->setTableActionData(['district_ids' => [(string) $this->other->id]])
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $staff->refresh();
        $this->assertFalse($staff->is_global);
        $this->assertSame([$this->other->id], $staff->districts()->pluck('districts.id')->all());
    }
}
