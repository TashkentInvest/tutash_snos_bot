<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Dizayn\PostResource\Pages\ListPosts;
use App\Models\Dizayn\Post as DzPost;
use App\Models\Post;
use App\Models\Staff;
use App\Models\User;
use App\Support\DizaynPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\UsesDizaynDb;
use Tests\TestCase;

/** Bitta «Post yuborish» — ikkala botga: Tutash hudud (o'zi yuboradi) va Dizayn-kod (bazasiga yoziladi, bot o'zi yuboradi). */
class DizaynPostTest extends TestCase
{
    use RefreshDatabase, UsesDizaynDb;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDizaynDb();
        $this->uploads = sys_get_temp_dir().'/dz-uploads-'.uniqid();
        config(['services.dizayn_bot.uploads_dir' => $this->uploads]);
        $now = now()->utc()->format('Y-m-d H:i:s');
        foreach ([701, 702, 703, 704, 705] as $id) {
            $this->dz('users', ['user_id' => $id, 'first_name' => "U$id", 'first_seen' => $now, 'last_seen' => $now, 'blocked_at' => $id === 705 ? $now : null]);
        }
        $this->dz('staff', ['full_name' => 'Tuman xodimi', 'phone' => '+998901000001', 'user_id' => 701, 'is_active' => true]);
        $this->dz('staff', ['full_name' => 'Ishdan ketgan', 'phone' => '+998901000002', 'user_id' => 702, 'is_active' => false]);
        $this->dz('report_recipients', ['user_id' => 703, 'label' => 'Rahbar']);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory($this->uploads);
        parent::tearDown();
    }

    public function test_audience_groups(): void
    {
        $ids = fn (array $g) => DizaynPost::recipients($g)->orderBy('user_id')->pluck('user_id')->all();
        $this->assertSame([701], $ids([DizaynPost::STAFF]));          // faol xodim; ishdan ketgan emas
        $this->assertSame([703], $ids([DizaynPost::LEADERS]));
        $this->assertSame([704], $ids([DizaynPost::CITIZENS]));       // 702 sobiq xodim — aholi emas; 705 bloklagan
        $this->assertSame([701, 703, 704], $ids(array_keys(DizaynPost::GROUPS)));
        $this->assertSame([], $ids([]));
    }

    public function test_one_form_posts_to_both_bots(): void
    {
        Storage::fake('local');
        Queue::fake();
        Staff::create(['telegram_id' => 2001, 'full_name' => 'Xodim Bir', 'phone' => '902000001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)->mountAction('post')->assertSee('Dizayn-kod boti')->assertDontSee('Rahbarlar (hisobot oluvchilar)')
            ->set('mountedActionsData.0.bots', ['snos', 'dizayn'])->assertSee('Rahbarlar (hisobot oluvchilar) (1)')
            ->set('mountedActionsData.0.dizayn_groups', [DizaynPost::STAFF, DizaynPost::LEADERS])
            ->set('mountedActionsData.0.media', [UploadedFile::fake()->image('e.jpg', 800, 600)])
            ->set('mountedActionsData.0.caption', 'Ertaga shanbalik')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(1, Post::sole()->total);
        $dz = DzPost::sole();
        $this->assertSame(['sending', 2, 'photo', 'Ertaga shanbalik', true], [$dz->status, $dz->total, $dz->media_type, $dz->caption, $dz->ack_button]);
        $this->assertSame('snos', $dz->audience['from']);
        $this->assertFileExists($this->uploads.'/'.$dz->media_path); // bot o'zining uploads papkasidan o'qiydi
        $this->assertSame([701, 703], DB::connection('dizayn')->table('post_deliveries')->where('status', 'pending')->orderBy('user_id')->pluck('user_id')->all());
        // vaqt bazada UTC, panelda Toshkent
        $this->assertSame(now()->utc()->format('Y-m-d H'), substr(DB::connection('dizayn')->table('posts')->value('created_at'), 0, 13));
        $this->assertSame(now()->format('Y-m-d H'), $dz->created_at->format('Y-m-d H'));

        // faqat Dizayn — SNOS'ga ikkinchi post yozilmaydi
        Livewire::test(Dashboard::class)->mountAction('post')
            ->set('mountedActionsData.0.bots', ['dizayn'])
            ->set('mountedActionsData.0.dizayn_groups', [DizaynPost::CITIZENS])
            ->set('mountedActionsData.0.caption', 'Aholiga')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(1, Post::count());
        $this->assertSame(2, DzPost::count());

        // ro'yxat va «Botdan o'chirish» (bot bajaradi)
        DzPost::whereKey($dz->id)->update(['status' => 'sent', 'delivered' => 2]);
        DB::connection('dizayn')->table('post_deliveries')->where('user_id', 703)->update(['status' => 'sent', 'ack_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $this->get('/admin/dizayn/postlar')->assertOk()->assertSee('Ertaga shanbalik')->assertSee('1 (50%)')->assertSee('shu paneldan');
        Livewire::test(ListPosts::class)->callTableAction('recall', DzPost::find($dz->id))->assertHasNoTableActionErrors();
        $this->assertNotNull(DzPost::find($dz->id)->recalled_at);
    }

    public function test_without_dizayn_db_form_is_unchanged(): void
    {
        config(['services.dizayn_bot.enabled' => false]);
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->mountAction('post')->assertDontSee('Dizayn-kod boti')->assertSee('Bo‘limlar');
    }
}
