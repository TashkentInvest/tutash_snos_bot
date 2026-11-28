<?php

namespace App\Support;

use App\Models\Dizayn\Post;
use App\Models\Dizayn\PostDelivery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dizayn-kod botiga post (SNOS panelidan): posts + post_deliveries yoziladi, status «sending» — botning o'zi yuboradi
 * (post_worker), «Tanishdim» va «Botdan o'chirish» ham bot tomonda. Rasm/video botning uploads papkasiga ko'chiriladi.
 * Auditoriya — Dizayn panelidagi bilan bir xil (PostAudience): tuman xodimlari, rahbarlar, aholi (faqat belgilansa).
 */
class DizaynPost
{
    public const STAFF = 'staff';

    public const LEADERS = 'leaders';

    public const CITIZENS = 'citizens';

    public const GROUPS = [self::STAFF => 'Tuman xodimlari', self::LEADERS => 'Rahbarlar (hisobot oluvchilar)', self::CITIZENS => 'Aholi (botdagi fuqarolar)'];

    /** Bot yeta oladiganlar: botda bor, bloklamagan. */
    public static function recipients(array $groups): Builder
    {
        $staff = fn ($q) => $q->select('user_id')->from('staff')->whereNotNull('user_id')->where('is_active', true);
        $anyStaff = fn ($q) => $q->select('user_id')->from('staff')->whereNotNull('user_id');
        $leaders = fn ($q) => $q->select('user_id')->from('report_recipients');

        return DB::connection('dizayn')->table('users')->whereNull('blocked_at')->whereNotNull('user_id')
            ->where(function ($q) use ($groups, $staff, $anyStaff, $leaders) {
                $any = false;
                if (in_array(self::STAFF, $groups, true)) {
                    $q->orWhereIn('user_id', $staff);
                    $any = true;
                }
                if (in_array(self::LEADERS, $groups, true)) {
                    $q->orWhereIn('user_id', $leaders);
                    $any = true;
                }
                if (in_array(self::CITIZENS, $groups, true)) {
                    $q->orWhere(fn ($w) => $w->whereNotIn('user_id', $anyStaff)->whereNotIn('user_id', $leaders));
                    $any = true;
                }
                $any || $q->whereRaw('1 = 0');
            });
    }

    /** Bo'lim nomi va oluvchilar soni (tanlov tugmalarida). */
    public static function groupOptions(): array
    {
        return collect(self::GROUPS)->mapWithKeys(fn ($label, $k) => [$k => $label.' ('.self::recipients([$k])->count().')'])->all();
    }

    /**
     * @param  string|null  $localMedia  SNOS «local» diskidagi fayl (Post yuborish formasidan)
     * @param  array<string,int>  $probe  video o'lchami (width, height, duration)
     */
    public static function create(?string $caption, ?string $localMedia, ?string $mediaType, array $probe, array $groups, bool $ack): Post
    {
        $mediaPath = null;
        if ($localMedia) {
            $root = rtrim((string) config('services.dizayn_bot.uploads_dir'), '/\\');
            $mediaPath = 'posts/snos-'.Str::random(16).'.'.strtolower(pathinfo($localMedia, PATHINFO_EXTENSION) ?: 'bin');
            $target = $root.'/'.$mediaPath;
            @mkdir(dirname($target), 0775, true);
            if (! @copy(Storage::disk('local')->path($localMedia), $target)) {
                throw new \RuntimeException('Rasm/videoni Dizayn-kod botining papkasiga ko‘chirib bo‘lmadi ('.$root.'): ruxsat yo‘q.');
            }
            @chmod($target, 0664);
        }
        $ids = self::recipients($groups)->pluck('user_id');

        return DB::connection('dizayn')->transaction(function () use ($caption, $mediaType, $mediaPath, $probe, $groups, $ack, $ids) {
            $post = Post::create([
                'caption' => filled($caption) ? trim($caption) : null, 'media_type' => $mediaType, 'media_path' => $mediaPath,
                'media_width' => $probe['width'] ?? null, 'media_height' => $probe['height'] ?? null, 'media_duration' => $probe['duration'] ?? null,
                'audience' => ['groups' => array_values($groups), 'districts' => [], 'from' => 'snos'],
                'ack_button' => $ack, 'status' => 'sending', 'total' => $ids->count(),
                'created_by' => null, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            foreach ($ids->chunk(500) as $chunk) {
                PostDelivery::insert($chunk->map(fn ($id) => ['post_id' => $post->id, 'user_id' => $id, 'status' => 'pending'])->values()->all());
            }

            return $post;
        });
    }
}
