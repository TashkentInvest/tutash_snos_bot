<?php

namespace Tests\Feature;

use App\Filament\Resources\Dizayn\FeedbackResource\Pages\ListFeedback;
use App\Models\Dizayn\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesDizaynDb;
use Tests\TestCase;

/** «Dizayn-kod boti» bo'limi: ikkinchi botning bazasi shu paneldan — umumiy ko'rinish, foydalanuvchilar, yuklab olishlar, murojaatlar. */
class DizaynPanelTest extends TestCase
{
    use RefreshDatabase, UsesDizaynDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDizaynDb();
    }

    private function admin(): User
    {
        $u = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $u->save();

        return $u;
    }

    public function test_overview_users_downloads_and_feedback_answer(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $this->dz('users', ['user_id' => 501, 'full_name' => 'Karimov Alisher', 'phone' => '+998901112233', 'lang' => 'uz', 'registered_at' => $now, 'first_seen' => $now, 'last_seen' => $now]);
        $this->dz('users', ['user_id' => 502, 'first_name' => 'Ivan', 'lang' => 'ru', 'first_seen' => $now, 'last_seen' => $now, 'blocked_at' => $now]);
        $this->dz('downloads', ['user_id' => 501, 'district' => 'Мирзо Улуғбек тумани', 'street' => 'Ахмад Дониш кўчаси', 'file_name' => 'a.pdf', 'sent_at' => $now]);
        $this->dz('downloads', ['user_id' => 501, 'district' => 'Мирзо Улуғбек тумани', 'street' => 'Ахмад Дониш кўчаси', 'file_name' => 'a.pdf', 'sent_at' => $now]);
        $fb = $this->dz('feedback', ['user_id' => 501, 'level' => 'high', 'text' => 'Do‘konim oldida peshtaxta qanday bo‘lishi kerak?', 'created_at' => $now]);

        $this->actingAs($this->admin());
        $this->get('/admin/dizayn')->assertOk()->assertSee('Bot foydalanuvchilari')->assertSee('Mirzo Ulug‘bek')
            ->assertSee('Axmad Donish ko‘chasi')->assertSee('javobsiz');
        $this->get('/admin/dizayn/foydalanuvchilar')->assertOk()->assertSee('Karimov Alisher')->assertSee('+998 90 111 22 33');
        $this->get('/admin/dizayn/foydalanuvchilar/501')->assertOk()->assertSee('Yuklab olgan konsepsiyalar')->assertSee('Axmad Donish ko‘chasi');
        $this->get('/admin/dizayn/yuklab-olishlar')->assertOk()->assertSee('Mirzo Ulug‘bek tumani');
        $this->get('/admin/dizayn/murojaatlar')->assertOk()->assertSee('peshtaxta')->assertSee('Yangi');

        // javob: bot kutadigan ustunlarga yoziladi (answer, answered_at, answered_by); ikkinchi marta yozilmaydi
        Livewire::test(ListFeedback::class)->callTableAction('answer', Feedback::find($fb), ['answer' => 'Assalomu alaykum, konsepsiyadagi namunaga mos bo‘lishi kerak.'])
            ->assertHasNoTableActionErrors();
        $f = Feedback::find($fb);
        $this->assertSame(['a@t.test', 'answered'], [$f->answered_by, $f->status()]);
        $this->assertNotNull($f->answered_at);
        Livewire::test(ListFeedback::class)->assertTableActionHidden('answer', $f);
    }

    public function test_section_hidden_when_disabled_or_for_non_admins(): void
    {
        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer)->get('/admin/dizayn')->assertForbidden();

        config(['services.dizayn_bot.enabled' => false]);
        $this->flushSession(); // boshqa foydalanuvchi (AuthenticateSession avvalgi sessiyani rad etadi)
        $this->actingAs($this->admin());
        $this->assertFalse(\App\Filament\Resources\Dizayn\FeedbackResource::canViewAny());
        $this->get('/admin')->assertOk()->assertDontSee('Dizayn-kod boti');
    }
}
