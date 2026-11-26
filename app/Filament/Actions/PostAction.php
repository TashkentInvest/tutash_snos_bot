<?php

namespace App\Filament\Actions;

use App\Jobs\SendPost;
use App\Models\Post;
use App\Models\PostDelivery;
use App\Models\User;
use App\Support\Broadcast;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Bosh sahifa → «Post yuborish»: rasm yoki video va matn, bo'limlar (xohlansa aholi ham), tumanlar va aniq oluvchilar.
 * Yuborish navbatda (SendPost); statistika — «Postlar» sahifasida va bosh sahifada.
 */
class PostAction
{
    /** Oluvchilarni tanlash: bo'limlar → tumanlar → ro'yxat (hammasi belgilangan, keraksizi olib tashlanadi). */
    public static function audience(bool $citizens): array
    {
        $groups = Broadcast::GROUPS + ($citizens ? [Broadcast::CITIZENS => 'Aholi (botdagi barcha aholi)'] : []);
        $opts = fn (Forms\Get $get) => Broadcast::options(array_map('intval', $get('districts') ?? []), $get('groups') ?? []);
        $refresh = fn (Forms\Get $get, Forms\Set $set) => $set('people', array_map('strval', array_keys($opts($get))));

        return [
            Forms\Components\CheckboxList::make('groups')->label('Bo‘limlar')->options($groups)
                ->default(array_keys(Broadcast::GROUPS))->columns(2)->gridDirection('row')->required()->live()
                ->helperText($citizens ? 'Aholi tuman bo‘yicha saralanmaydi — belgilansa, botdagi barcha aholiga boradi.' : null)
                ->validationMessages(['required' => 'Kamida bitta bo‘limni belgilang.'])->afterStateUpdated($refresh),
            Forms\Components\Select::make('districts')->label('Tumanlar')->multiple()->native(false)
                ->options(fn () => \App\Models\District::orderBy('name')->pluck('name', 'id'))
                ->placeholder('Barcha tumanlar')->live()->afterStateUpdated($refresh),
            Forms\Components\CheckboxList::make('people')
                ->label(fn (Forms\Get $get) => 'Kimlarga — belgilangan: '.count($get('people') ?? []).' ta')
                ->options($opts)
                ->default(fn () => array_map('strval', array_keys(Broadcast::options())))
                ->searchable()->bulkToggleable()->columns(2)->required()->live()
                ->noSearchResultsMessage('Topilmadi')->searchPrompt('Ism yoki tuman bo‘yicha qidirish')
                ->validationMessages(['required' => 'Kamida bitta oluvchini belgilang.']),
        ];
    }

    public static function make(): Action
    {
        return Action::make('post')->label('Post yuborish')->icon('heroicon-o-photo')->color('gray')
            ->modalHeading('Botda post')
            ->modalDescription('Rasm yoki video va matn — tanlanganlarga botda boradi. Post ostida «✅ Tanishdim» tugmasi bo‘lsa, kim tanishganini ko‘rasiz.')
            ->modalWidth('3xl')->modalSubmitActionLabel('Yuborish')
            ->visible(fn () => User::currentIsAdmin())
            ->form([
                Forms\Components\FileUpload::make('media')->label('Rasm yoki video (ixtiyoriy)')
                    ->disk('local')->directory('posts')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime'])
                    ->maxSize(50 * 1024)->live()
                    ->helperText('JPG / PNG rasm yoki MP4 video, 50 MB gacha (Telegram cheklovi).'),
                Forms\Components\Textarea::make('caption')->label('Matn')->rows(6)
                    ->required(fn (Forms\Get $get) => blank($get('media')))
                    ->maxLength(fn (Forms\Get $get) => blank($get('media')) ? 4000 : 1000)
                    ->helperText(fn (Forms\Get $get) => blank($get('media')) ? 'Rasm/videosiz — faqat matn (4000 belgigacha).' : 'Rasm/video ostidagi matn — 1000 belgigacha (Telegram cheklovi).')
                    ->validationMessages(['required' => 'Matn yozing yoki rasm/video qo‘shing.']),
                ...self::audience(true),
                Forms\Components\Toggle::make('ack_button')->label('«✅ Tanishdim» tugmasi')->default(true)
                    ->helperText('Telegram shaxsiy chatda kim ko‘rganini botga bermaydi — tugma bosganlar aniq sanaladi.'),
            ])
            ->action(function (array $data) {
                $path = is_array($data['media'] ?? null) ? (array_values($data['media'])[0] ?? null) : ($data['media'] ?? null);
                $mime = $path ? (Storage::disk('local')->mimeType($path) ?: '') : '';
                $ids = Broadcast::recipients([], [...array_keys(Broadcast::GROUPS), Broadcast::CITIZENS])
                    ->whereKey(array_map('intval', $data['people'] ?? []))->pluck('id');
                $post = Post::create([
                    'caption' => filled($data['caption'] ?? null) ? trim($data['caption']) : null,
                    'media_type' => $path ? (str_starts_with($mime, 'video/') ? 'video' : 'photo') : null, 'media_path' => $path,
                    'audience' => ['groups' => $data['groups'] ?? [], 'districts' => array_map('intval', $data['districts'] ?? [])],
                    'ack_button' => (bool) ($data['ack_button'] ?? true), 'status' => Post::SENDING, 'total' => $ids->count(), 'created_by' => auth()->id(),
                ]);
                $now = now();
                foreach ($ids->chunk(500) as $chunk) {
                    PostDelivery::insert($chunk->map(fn ($id) => ['post_id' => $post->id, 'staff_id' => $id, 'status' => PostDelivery::PENDING, 'created_at' => $now, 'updated_at' => $now])->all());
                }
                \App\Models\AuditLog::record('post.created', null, ['post' => $post->id, 'by' => auth()->id(), 'recipients' => $ids->count()]);
                SendPost::dispatch($post->id);
                Notification::make()->title("Post yuborilmoqda: {$ids->count()} ta oluvchi")
                    ->body('Natija «Postlar» sahifasida va bosh sahifada ko‘rinadi.')->success()->send();
            });
    }
}
