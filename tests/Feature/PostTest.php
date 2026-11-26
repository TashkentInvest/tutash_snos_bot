<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Jobs\SendPost;
use App\Models\District;
use App\Models\Post;
use App\Models\PostDelivery;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use Tests\TestCase;

/** Bosh sahifa → «Post yuborish»: rasm/video + matn, bo'limlar (xohlansa aholi), «Tanishdim» va statistika. */
class PostTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $u->save();

        return $u;
    }

    public function test_post_with_photo_to_chosen_people_ack_and_stats(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['nutgram.token' => '123:TEST']);
        $d = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001']);
        $emp = Staff::create(['telegram_id' => 2001, 'full_name' => 'Xodim Bir', 'phone' => '902000001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $emp->districts()->attach($d);
        $emp2 = Staff::create(['telegram_id' => 2002, 'full_name' => 'Xodim Ikki', 'phone' => '902000002', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $cit = Staff::create(['telegram_id' => 2003, 'full_name' => 'Aholi Bir', 'phone' => '902000003', 'status' => Staff::CITIZEN, 'role' => Staff::ROLE_CITIZEN]);
        Staff::create(['telegram_id' => 2004, 'full_name' => 'Bloklangan', 'phone' => '902000004', 'status' => Staff::BLOCKED, 'role' => Staff::ROLE_EMPLOYEE]);

        // aholi — faqat belgilansa
        $this->assertSame([2001, 2002], \App\Support\Broadcast::recipients()->orderBy('telegram_id')->pluck('telegram_id')->all());
        $this->assertSame([2001, 2002, 2003], \App\Support\Broadcast::recipients([], ['employee', 'citizen'])->orderBy('telegram_id')->pluck('telegram_id')->all());
        $this->assertSame([2003], \App\Support\Broadcast::recipients([], ['citizen'])->pluck('telegram_id')->all());

        $this->actingAs($this->admin());
        $this->get('/admin')->assertOk(); // vidjetlar sahifadan keyin yuklanadi — alohida tekshiriladi
        Livewire::test(\App\Filament\Widgets\UsersStats::class)->assertSee('Bot foydalanuvchilari')->assertSee('Jami foydalanuvchilar')->assertSee('Aholi');
        Livewire::test(Dashboard::class)->mountAction('post')->assertSee('Aholi (botdagi barcha aholi)')
            ->set('mountedActionsData.0.groups', ['employee', 'citizen'])
            ->assertSet('mountedActionsData.0.people', [(string) $cit->id, (string) $emp->id, (string) $emp2->id])
            ->set('mountedActionsData.0.people', [(string) $emp->id, (string) $cit->id])
            ->set('mountedActionsData.0.media', [UploadedFile::fake()->image('e.jpg', 800, 600)])
            ->set('mountedActionsData.0.caption', 'Ertaga <b>shanbalik</b>')
            ->callMountedAction()->assertHasNoActionErrors();

        $post = Post::sole();
        $this->assertSame(['photo', 2, true], [$post->media_type, $post->total, $post->ack_button]);
        $this->assertTrue(Storage::disk('local')->exists($post->media_path));
        Queue::assertPushed(SendPost::class);

        // yuborish: birinchisi fayl bilan, keyingisi file_id bilan; matn HTML emas, oddiy matn
        $bot = Nutgram::fake();
        (new SendPost($post->id))->handle($bot);
        $post->refresh();
        $this->assertSame([Post::SENT, 2, 0], [$post->status, $post->delivered, $post->failed]);
        $body = urldecode((string) collect($bot->getRequestHistory())->first()['request']->getBody());
        $this->assertStringContainsString('&lt;b&gt;shanbalik', $body);
        $this->assertStringContainsString('pa:'.$post->id, $body);

        // «Tanishdim» — aholi bosdi; xodim keyin botdan foydalandi
        $this->travel(5)->minutes();
        $tg = app(Nutgram::class);
        $tg->setCommonUser(TgUser::make(id: 2003, is_bot: false, first_name: 'T'));
        $tg->setCommonChat(Chat::make(id: 2003, type: 'private'));
        $tg->hearCallbackQueryData('pa:'.$post->id)->reply();
        $this->assertNotNull(PostDelivery::where('staff_id', $cit->id)->value('ack_at'));
        $emp->forceFill(['last_seen_at' => now()->addMinute()])->save();

        $this->assertSame(['total' => 2, 'delivered' => 2, 'failed' => 0, 'ack' => 1, 'active' => 2, 'seen' => 2], $post->stats());
        Livewire::test(\App\Filament\Widgets\PostsStats::class)->assertSee('Botdagi postlar — statistika')->assertSee('2 (100%)');
        $this->get('/admin/posts')->assertOk()->assertSee('Ertaga <b>shanbalik</b>')->assertSee('1 (50%)');
        $this->get('/admin/posts/'.$post->id)->assertOk()->assertSee('Postdan keyin botni ochgan')->assertSee('1 (50%)');
        Livewire::test(\App\Filament\Resources\PostResource\RelationManagers\DeliveriesRelationManager::class,
            ['ownerRecord' => $post, 'pageClass' => \App\Filament\Resources\PostResource\Pages\ViewPost::class])
            ->assertSee('Xodim Bir')->assertSee('Aholi Bir')
            ->filterTable('view', 'ack')->assertSee('Aholi Bir')->assertDontSee('Xodim Bir');
        $this->get(route('admin.post-media', $post))->assertOk();
    }

    public function test_text_only_post_and_non_admin_cannot_see(): void
    {
        Queue::fake();
        Staff::create(['telegram_id' => 3001, 'full_name' => 'X', 'phone' => '903000001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_CADASTRE]);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->mountAction('post')
            ->set('mountedActionsData.0.caption', '')->callMountedAction()->assertHasActionErrors(['caption' => 'required']);
        Livewire::test(Dashboard::class)->mountAction('post')
            ->set('mountedActionsData.0.caption', 'Faqat matn')->set('mountedActionsData.0.ack_button', false)
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame([null, 1, false], [Post::sole()->media_type, Post::sole()->total, Post::sole()->ack_button]);

        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer);
        $this->assertFalse(\App\Filament\Resources\PostResource::canViewAny());
        $this->assertFalse(\App\Filament\Widgets\UsersStats::canView());
    }
}
