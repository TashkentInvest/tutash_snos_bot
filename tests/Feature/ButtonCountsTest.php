<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use Tests\TestCase;

/**
 * Xodim tugmasidagi «A/B»: A — davr boshidagi qoldiq, B — bugun (18:00 dan 18:00 gacha) buzilgani tasdiqlanganlar.
 * 18:00 da B qoldiqdan ayiriladi va 0 dan boshlanadi. Aholida — avvalgidek jami/buzilgan.
 */
class ButtonCountsTest extends TestCase
{
    use RefreshDatabase;

    private function home(int $uid): string
    {
        /** @var \SergiX44\Nutgram\Testing\FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TgUser::make(id: $uid, is_bot: false, first_name: 'X'));
        $bot->setCommonChat(Chat::make(id: $uid, type: 'private'));
        $bot->hearText('/start')->reply();

        $body = collect($bot->getRequestHistory())->map(fn ($r) => (string) array_values($r)[0]->getBody())->last() ?? '';

        return json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function test_staff_buttons_roll_over_at_18_and_citizens_unchanged(): void
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        $d = District::create(['name' => 'Bektemir tumani', 'phone' => '901000001']);
        District::create(['name' => 'Chilonzor tumani', 'phone' => '901000002']);
        $m = Mahalla::create(['district_id' => $d->id, 'name' => 'Test MFY']);
        $sites = collect(range(1, 4))->map(fn ($i) => Site::create(['district_id' => $d->id, 'mahalla_id' => $m->id, 'source_no' => $i, 'address' => "Ko'cha {$i}"]));
        $sites[0]->update(['status' => Site::DEMOLISHED, 'demolished_at' => '2026-11-10']); // oldingi kunlarda buzilgan
        $staff = Staff::create(['telegram_id' => 701, 'full_name' => 'Xodim', 'phone' => '900000701', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'is_global' => true]);

        // bugun 10:00 da buzildi va tasdiqlandi
        $sites[1]->update(['status' => Site::DEMOLISHED, 'demolished_at' => '2026-11-23']);
        Inspection::create(['site_id' => $sites[1]->id, 'staff_id' => $staff->id, 'status' => Inspection::SUBMITTED, 'is_demolished' => true,
            'review_status' => Inspection::REVIEW_APPROVED, 'submitted_at' => now(), 'reviewed_at' => now()]);

        // 17:00 — qoldiq 3 (4 tadan oldin buzilgan 1 ayirilgan), bugun 1
        Carbon::setTestNow('2026-11-23 17:00:00');
        $this->assertStringContainsString('Bektemir tumani · 3/1', $this->home(701));
        $this->assertStringContainsString('qolgan obyektlar / bugun buzilganlari', $this->home(701));

        // 18:00 dan keyin — bugungi 1 ta qoldiqdan ayirildi, B 0 dan
        Carbon::setTestNow('2026-11-23 18:01:00');
        $this->assertStringContainsString('Bektemir tumani · 2/0', $this->home(701));

        // aholi — avvalgidek jami / buzilgan
        Staff::create(['telegram_id' => 702, 'full_name' => 'Aholi', 'phone' => '900000702', 'status' => Staff::CITIZEN, 'role' => Staff::ROLE_CITIZEN]);
        $this->assertStringContainsString('Bektemir tumani · 4/2', $this->home(702));
        Carbon::setTestNow();
    }
}
