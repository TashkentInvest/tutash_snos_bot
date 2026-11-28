<?php

namespace Tests\Feature;

use App\Filament\Resources\Dizayn\InspectionResource\Pages\ListInspections;
use App\Models\Dizayn\DesignObject;
use App\Models\Dizayn\Inspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\UsesDizaynDb;
use Tests\TestCase;

/** Dizayn-kod: obyektlar, xarita va tekshiruvlar SNOS panelida — qaror Dizayn bazasiga yoziladi, xodimga bot (outbox) orqali boradi. */
class DizaynInspectionTest extends TestCase
{
    use RefreshDatabase, UsesDizaynDb;

    private int $object;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDizaynDb();
        $now = now()->utc()->format('Y-m-d H:i:s');
        $d = $this->dz('districts', ['name' => 'Учтепа тумани', 'sort_order' => 1]);
        $s = $this->dz('streets', ['district_id' => $d, 'name' => 'Лутфий кўчаси', 'planned_objects' => 4, 'start_lat' => 41.29, 'start_lon' => 69.18, 'end_lat' => 41.30, 'end_lon' => 69.19]);
        $this->dz('users', ['user_id' => 801, 'first_name' => 'X', 'lang' => 'uz', 'first_seen' => $now, 'last_seen' => $now]);
        $staff = $this->dz('staff', ['full_name' => 'Каримов Ботир', 'phone' => '+998901110000', 'district_id' => $d, 'user_id' => 801, 'is_active' => true]);
        $this->object = $this->dz('objects', ['street_id' => $s, 'shop_name' => 'Nur market', 'stir' => '123456789', 'status' => 'pending', 'created_by' => $staff,
            'found_lat' => 41.295, 'found_lon' => 69.185, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $this->dz('inspections', ['object_id' => $this->object, 'staff_id' => $staff, 'kind' => 'installed', 'step' => 'submitted', 'review' => 'pending',
            'latitude' => 41.2951, 'longitude' => 69.1851, 'obj_latitude' => 41.2952, 'obj_longitude' => 69.1852, 'distance_m' => 12,
            'photo' => 'x/p.jpg', 'video' => 'x/v.mp4', 'comment' => 'Yangi peshtaxta', 'created_at' => $now, 'submitted_at' => $now]);

        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
    }

    public function test_pages_render(): void
    {
        $this->get('/admin/dizayn/obyektlar')->assertOk()->assertSee('Nur market')->assertSee('Lutfiy ko‘chasi')->assertSee('dalil tekshiruvda');
        $this->get('/admin/dizayn/obyektlar/'.$this->object)->assertOk()->assertSee('Xodim dalillari')->assertSee('Yangi peshtaxta')->assertSee('Karimov Botir');
        $this->get('/admin/dizayn/tekshiruvlar')->assertOk()->assertSee('Nur market')->assertSee('Tekshiruv kutmoqda');
        $this->get('/admin/dizayn')->assertOk()->assertSee('Kiritilgan tadbirkorlar')->assertSee('Dalillar tasdiq kutmoqda')->assertSee('Tuman xodimlari');
        $this->get('/admin/dizayn/xarita')->assertOk()->assertSee('Dizayn-kod xaritasi')->assertSee('Lutfiy ko')->assertSee('Tasdiq kutmoqda');
    }

    public function test_main_dashboard_has_dizayn_block(): void
    {
        \Livewire\Livewire::test(\App\Filament\Widgets\Overview::class)->assertSee('Dizayn-kod boti')->assertSee('Kiritilgan tadbirkorlar')
            ->assertSee('Dalillar tasdiq kutmoqda')->assertSee('/admin/dizayn/obyektlar');
        config(['services.dizayn_bot.enabled' => false]);
        app()->forgetInstance('dizayn.available');
        \Livewire\Livewire::test(\App\Filament\Widgets\Overview::class)->assertDontSee('Kiritilgan tadbirkorlar');
    }

    public function test_process_then_approve_tells_staff_through_the_bot(): void
    {
        $i = Inspection::sole();
        // kamchilik belgilansa — jarayonli tasdiq: obyekt «Yechildi» (jarayonda), xodimga nima tuzatish kerakligi
        Livewire::test(ListInspections::class)->callTableAction('approve', $i, ['reasons' => ['letters'], 'note' => 'Tez'])->assertHasNoTableActionErrors();
        $this->assertSame(Inspection::CONFIRMING, $i->fresh()->review);
        $this->assertSame(DesignObject::REMOVED, DesignObject::find($this->object)->status);
        $msg = DB::connection('dizayn')->table('outbox')->sole();
        $this->assertSame(801, (int) $msg->user_id);
        $this->assertStringContainsString('Bukva qo‘yilmagan', $msg->text);

        // to'liq tasdiq: obyekt «Qo'yildi», joyi — xodim belgilagan nuqta
        Livewire::test(ListInspections::class)->set('activeTab', 'process')->callTableAction('approve', $i->fresh(), [])->assertHasNoTableActionErrors();
        $o = DesignObject::find($this->object);
        $this->assertSame([DesignObject::INSTALLED, 41.2952], [$o->status, $o->latitude]);
        $this->assertSame([Inspection::APPROVED, 'a@t.test'], [$i->fresh()->review, $i->fresh()->reviewed_by]);
        $this->assertSame(2, DB::connection('dizayn')->table('outbox')->count());

        // xato tasdiqni rad etish: holat qaytadi
        Livewire::test(ListInspections::class)->set('activeTab', 'approved')->callTableAction('reject', $i->fresh(), ['reason' => 'Boshqa do‘kon rasmi'])->assertHasNoTableActionErrors();
        $this->assertSame(DesignObject::PENDING, DesignObject::find($this->object)->status);
        $this->assertStringContainsString('Boshqa do‘kon rasmi', DB::connection('dizayn')->table('outbox')->orderByDesc('id')->value('text'));
    }

    public function test_hold_and_ask(): void
    {
        $i = Inspection::sole();
        Livewire::test(ListInspections::class)->callTableAction('hold', $i, ['note' => 'Joyni tekshiramiz'])->assertHasNoTableActionErrors();
        $this->assertSame(Inspection::MODERATION, $i->fresh()->review);
        Livewire::test(ListInspections::class)->callTableAction('ask', $i->fresh(), ['question' => 'Do‘kon ochiqmi?'])->assertHasNoTableActionErrors();
        $this->assertStringContainsString('maps.google.com', DB::connection('dizayn')->table('outbox')->orderByDesc('id')->value('text'));
        $this->assertSame(2, DB::connection('dizayn')->table('outbox')->count());
    }
}
