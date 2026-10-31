<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Brauzer kabi: /admin sahifasidagi Livewire komponentlariga haqiqiy /livewire/update so'rovlari (production 419 xatolarini ushlash uchun). */
class DashboardHttpTest extends TestCase
{
    use RefreshDatabase;

    private function snapshots(string $html): array
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);

        return array_map(fn ($s) => html_entity_decode($s, ENT_QUOTES), $m[1]);
    }

    private function update(string $snapshot, array $calls = [], array $updates = [])
    {
        return $this->withHeaders(['X-Livewire' => '1', 'Accept' => 'application/json'])
            ->postJson('/livewire/update', ['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => (object) $updates, 'calls' => $calls]]]);
    }

    public function test_dashboard_livewire_requests_work_like_in_browser(): void
    {
        config(['app.debug' => true]);
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);

        \App\Models\Staff::create(['telegram_id' => 801, 'status' => \App\Models\Staff::ACTIVE, 'role' => \App\Models\Staff::ROLE_EMPLOYEE, 'is_global' => true, 'receives_reports' => true, 'full_name' => 'Global Admin', 'phone' => '900000101']);
        $this->get('/admin-svod.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        $html = $this->get('/admin')->assertOk()->getContent();
        $snaps = $this->snapshots($html);
        $this->assertNotEmpty($snaps);

        $this->app->instance(\SergiX44\Nutgram\Nutgram::class, \SergiX44\Nutgram\Nutgram::fake());
        $page = collect($snaps)->first(fn ($s) => str_contains($s, 'dashboard'));
        foreach (['sendInspectionReport', 'sendAttendanceReport'] as $action) {
            $r = $this->update($page, [['path' => '', 'method' => 'mountAction', 'params' => [$action]]]);
            $this->assertSame(200, $r->status(), $action.' mount: '.$r->json('message').' @ '.$r->json('file').':'.$r->json('line'));
            // yuborishdan oldin namuna: kimlarga boradi va matn (buzilish hisobotida — jadval rasmi)
            $html = $r->json('components.0.effects.html');
            $this->assertStringContainsString('Kimlarga boradi', $html);
            $this->assertStringContainsString('Global Admin', $html);
            $action === 'sendInspectionReport' && $this->assertStringContainsString('admin-svod.png', $html);
            // bugun davomat bo'lmasa — bo'sh namuna o'rniga tushuntirish
            $action === 'sendAttendanceReport' && $this->assertStringContainsString('davomat so‘rovi yuborilmagan', $html);
            $snap = $r->json('components.0.snapshot');
            $r = $this->update($snap, [['path' => '', 'method' => 'callMountedAction', 'params' => []]]);
            $this->assertSame(200, $r->status(), $action.' call: '.$r->json('message').' @ '.$r->json('file').':'.$r->json('line'));
        }

        // har bir komponent qayta chiziladi (lazy joy egallovchilar bundan mustasno)
        foreach ($snaps as $s) {
            $name = json_decode($s, true)['memo']['name'];
            if (! empty(json_decode($s, true)['memo']['lazyLoaded']) || str_contains($s, '"lazyLoaded":false')) {
                continue;
            }
            $r = $this->update($s); // polling: chaqiruvsiz qayta chizish
            $this->assertSame(200, $r->status(), $name.': '.$r->json('message').' @ '.$r->json('file').':'.$r->json('line'));
        }
    }
}
