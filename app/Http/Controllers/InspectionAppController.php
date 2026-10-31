<?php

namespace App\Http\Controllers;

use App\Jobs\MergeInspectionVideo;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\Site;
use App\Models\Staff;
use App\Support\Device;
use App\Support\Gps;
use App\Support\MediaTool;
use App\Telegram\Handlers\InspectionHandler;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use App\Telegram\Support\WebAppAuth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Obyekt tekshiruvi Mini App'i: joylashuv qurilma GPS'idan, rasm va video jonli kameradan olinadi.
 * Holat (step) bazada saqlanadi; barcha so'rovlar Telegram imzosi (initData) bilan tasdiqlanadi.
 * Tekshiruv turi botda tanlanadi (Inspection::KINDS); har bir turda rasm va video majburiy:
 *
 * location -> photo -> video -> [court: documents] -> confirm
 * Izoh bo'yicha tasdiq (followup): location -> photo -> video -> reason -> confirm
 * Tuzatish (rework): edit (qaysi qismini qayta olish) -> ... -> edit -> confirm
 */
class InspectionAppController extends Controller
{
    private const MAX_KB = 1900;

    private const MAX_SECONDS = 15;

    private const MAX_DOC_KB = 4500; // nginx client_max_body_size 5m

    private const DOC_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    private const EXT = ['video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'webm'];

    public function page()
    {
        return response()->view('inspection-app', [
            'minSeconds' => (int) config('tutash.attendance.min_video_seconds'),
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{0: ?array, 1: ?Staff, 2: ?Inspection, 3: ?JsonResponse} */
    private function context(Request $request): array
    {
        $user = WebAppAuth::user((string) $request->input('initData'));
        if (! $user) {
            return [null, null, null, $this->fail('Telegram imzosi tasdiqlanmadi. Botdan tugmani qayta bosing.', 403)];
        }

        $staff = Staff::where('telegram_id', $user['id'])->where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE)->first();
        $in = $staff ? InspectionHandler::draft($staff) : null;
        if (! $in) {
            return [$user, $staff, null, $this->fail('Faol tekshiruv topilmadi. Botda obyektni tanlab, «Tekshiruvni boshlash» tugmasini bosing.', 409)];
        }

        return [$user, $staff, $in, null];
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    private function wrongStep(): JsonResponse
    {
        return $this->fail('Bu bosqich hozir kutilmayapti. Oynani yopib, qayta oching.', 409);
    }

    /**
     * Keyingi bosqich. Tuzatishda (rework) tanlangan qism qayta olingach yoki zanjir oxirida tanlash menyusiga ('edit') qaytiladi.
     */
    private function next(Inspection $in, string $finished): array
    {
        $normal = match ($finished) {
            'photo' => 'video',
            'video' => $in->isFollowup() ? 'reason' : ($in->kindValue() === Inspection::KIND_COURT ? 'documents' : 'confirm'),
            default => 'confirm', // documents, reason
        };
        if ($in->isRework() && ($normal === 'confirm' || $in->edit_target === $finished)) {
            return ['step' => 'edit', 'edit_target' => null];
        }

        return ['step' => $normal];
    }

    private function snapshot(Inspection $in): array
    {
        $in->loadMissing('site.mahalla', 'media');
        $parent = $in->rework_of ? $in->reworkOf : ($in->followup_of ? $in->followupOf : null);

        return [
            'ok' => true,
            'step' => $in->step,
            'kind' => $in->isFollowup() ? 'followup' : $in->kindValue(),
            'kind_label' => $in->isFollowup() ? 'Izoh bo‘yicha bajarilgan ish' : Inspection::KINDS[$in->kindValue()],
            'rework' => $in->isRework(),
            'followup' => $in->isFollowup(),
            'note' => $parent?->review_note,
            'located' => (bool) $in->rework_located,
            'complete' => $in->isRework() && (new InspectionHandler)->complete($in),
            'address' => $in->site->address,
            'mahalla' => $in->site->mahalla?->name,
            'documents' => $in->documents()->map(fn ($m) => ['kind' => $m->kind, 'pdf' => $m->isPdf()])->all(),
            'max_documents' => Inspection::MAX_DOCUMENTS,
            'reason' => $in->reason,
            'distance' => $in->distance_m,
            'far' => in_array('far_from_site', $in->flags ?? [], true),
            'media' => $in->media->pluck('kind')->values(),
            'photo_taken' => (bool) $in->mediaOf($in->mediaKinds()[0]),
            'video_taken' => (bool) $in->mediaOf($in->mediaKinds()[1]),
            'max' => self::MAX_SECONDS,
        ];
    }

    public function state(Request $request): JsonResponse
    {
        [, , $in, $error] = $this->context($request);

        return $error ?? response()->json($this->snapshot($in));
    }

    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'initData' => 'required|string|max:4096',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'device' => 'nullable|string|max:1500',
        ]);

        [$user, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'location') {
            return $this->wrongStep();
        }

        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;
        if ($message = Gps::accuracyError($accuracy)) {
            AuditLog::record('inspection.low_gps_accuracy', $user['id'], ['inspection' => $in->id, 'accuracy' => $accuracy]);

            return $this->fail($message);
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $site = $in->site;
        $distance = ($site->lat && $site->lng) ? Site::distanceMeters($lat, $lng, $site->lat, $site->lng) : null;
        $tooFar = $distance !== null && $distance > config('tutash.max_distance_m');
        $flags = [...($tooFar ? ['far_from_site'] : []), ...Gps::flags($accuracy)];

        if ($tooFar && config('tutash.strict_distance')) {
            AuditLog::record('inspection.too_far_rejected', $user['id'], ['site' => $in->site_id, 'distance' => $distance]);

            $radius = (int) config('tutash.max_distance_m');

            return response()->json([
                'ok' => false, 'distance' => $distance, 'radius' => $radius,
                'message' => "Siz obyekt hududidan uzoqdasiz (taxminan {$distance} m). Tekshiruvni faqat obyekt hududiga {$radius} m radius ichida yaqinlashib o‘tkazish mumkin. Obyekt yoniga borib, qayta urinib ko‘ring.",
            ], 422);
        }

        $in->update([
            'staff_lat' => $lat, 'staff_lng' => $lng, 'distance_m' => $distance,
            'suspicious' => $flags !== [], 'flags' => $flags ?: null,
            ...($in->isRework() ? ['step' => $in->edit_target ?? 'edit', 'rework_located' => true] : ['step' => 'photo']),
            'device' => Device::clean($request->input('device')) ?? $in->device,
        ]);

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Rasm: jonli kameradan olingan qisqa lavhadan bitta kadr ajratib olinadi. */
    public function photo(Request $request, MediaTool $tool): JsonResponse
    {
        $request->validate([
            'initData' => 'required|string|max:4096',
            'clip' => 'required|file|max:'.self::MAX_KB,
            'device' => 'nullable|string|max:1500',
        ]);

        [$user, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'photo') {
            return $this->wrongStep();
        }
        $kind = $in->mediaKinds()[0];

        $clip = $request->file('clip');
        if (! isset(self::EXT[$clip->getMimeType()]) || $clip->getSize() < 3_000) {
            return $this->fail('Rasm olinmadi. Qayta urinib ko‘ring.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'snos').'.jpg';
        try {
            if (! $tool->frame($clip->getRealPath(), $tmp)) {
                return $this->fail('Rasmni qayta ishlab bo‘lmadi. Qayta urinib ko‘ring.');
            }
            if ($tool->isBlack($tmp)) {
                return $this->blackFail($in, $request, $user['id']);
            }

            $error = $this->saveMedia($in, $kind, $tmp, 'jpg', 'image/jpeg', $user['id']);
        } finally {
            @unlink($tmp);
        }
        if ($error) {
            return $error;
        }

        $in->update([...$this->next($in, 'photo'), 'device' => Device::clean($request->input('device')) ?? $in->device]);

        return response()->json($this->snapshot($in->refresh()));
    }

    /**
     * Video (majburiy): asosiy lavha darhol saqlanadi, yuz lavhasi bilan birlashtirish navbatda (MergeInspectionVideo) bajariladi —
     * xodim ffmpeg kodlashini kutmaydi va PHP-FPM workerlari band bo'lib qolmaydi.
     */
    public function video(Request $request, MediaTool $tool): JsonResponse
    {
        $request->validate([
            'initData' => 'required|string|max:4096',
            'video' => 'required|file|max:'.self::MAX_KB,
            'face' => 'nullable|file|max:'.self::MAX_KB,
            'duration' => 'required|numeric|min:0|max:'.(self::MAX_SECONDS + 2),
            'device' => 'nullable|string|max:1500',
        ]);

        [$user, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'video') {
            return $this->wrongStep();
        }
        $kind = $in->mediaKinds()[1];

        $min = (int) config('tutash.attendance.min_video_seconds');
        if ((float) $request->input('duration') < $min) {
            return $this->fail("Video juda qisqa. Kamida {$min} soniya yozing.");
        }

        $main = $request->file('video');
        $ext = self::EXT[$main->getMimeType()] ?? null;
        if (! $ext || $main->getSize() < 20_000) {
            return $this->fail('Video formati qabul qilinmadi. Qayta yozing.');
        }
        if ($tool->isBlack($main->getRealPath())) {
            return $this->blackFail($in, $request, $user['id']);
        }
        $face = $request->file('face');
        $faceExt = $face ? (self::EXT[$face->getMimeType()] ?? null) : null;
        $faceOk = $faceExt && $face->getSize() >= 3_000 && ! $tool->isBlack($face->getRealPath());

        // Takroriy faylni aniqlash xom (qayta kodlanmagan) asosiy lavha bo'yicha — bir xil video har doim bir xil hash beradi.
        if ($error = $this->saveMedia($in, $kind, $main->getRealPath(), $ext, $main->getMimeType(), $user['id'])) {
            return $error;
        }

        if ($faceOk) {
            $media = $in->media()->where('kind', $kind)->first();
            $facePath = dirname($media->path).'/'.pathinfo($media->path, PATHINFO_FILENAME)."_face.{$faceExt}";
            if (Storage::disk(config('tutash.media_disk'))->putFileAs(dirname($facePath), $face, basename($facePath))) {
                MergeInspectionVideo::dispatch($media->id, $facePath);
            }
        }

        $in->update([...$this->next($in, 'video'), 'device' => Device::clean($request->input('device')) ?? $in->device]);

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Qora tasvir: rad etiladi, qurilma ma'lumoti saqlanadi va jurnalga yoziladi. */
    private function blackFail(Inspection $in, Request $request, int $userId): JsonResponse
    {
        $device = Device::clean($request->input('device')) ?? $in->device;
        $in->update(['device' => $device]);
        AuditLog::record('inspection.black_media', $userId, ['inspection' => $in->id, 'step' => $in->step, 'device' => Device::label($device)]);

        return $this->fail('Kamera qora tasvir berdi. Telegramni yangilang, kamera ruxsatini tekshiring va qayta urinib ko‘ring.');
    }

    /**
     * Faylni saqlaydi; xuddi shu fayl avval ishlatilgan bo'lsa — rad etadi va tekshiruvni belgilaydi.
     * Avval fayl yangi nom bilan diskka yoziladi, keyin baza yangilanadi: biror qadam muvaffaqiyatsiz bo'lsa,
     * bazadagi yozuv hech qachon mavjud bo'lmagan faylga ishora qilmaydi va eski fayl yo'qolmaydi.
     */
    private function saveMedia(Inspection $in, string $kind, string $source, string $ext, string $mime, int $userId): ?JsonResponse
    {
        $hash = sha1_file($source);
        $relative = "inspections/{$in->id}/{$kind}_".substr($hash, 0, 12).".{$ext}";
        $disk = Storage::disk(config('tutash.media_disk'));
        $old = $in->media()->where('kind', $kind)->value('path');

        if (! $disk->putFileAs(dirname($relative), new \Illuminate\Http\File($source), basename($relative))) {
            return $this->fail('Faylni saqlab bo‘lmadi. Qayta urinib ko‘ring.', 500);
        }

        try {
            $in->media()->updateOrCreate(['kind' => $kind], [
                'telegram_file_id' => 'webapp', 'file_unique_id' => $hash, 'mime' => $mime,
                'size' => filesize($source), 'path' => $relative, 'downloaded_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $relative !== $old && $disk->delete($relative);
            AuditLog::record('inspection.media_reused', $userId, ['inspection' => $in->id, 'kind' => $kind]);
            $in->update(['suspicious' => true, 'flags' => array_values(array_unique([...($in->flags ?? []), 'reused_media']))]);

            return $this->fail('Bu fayl avval yuborilgan. Hozir olingan yangi rasm yoki videoni yuboring.');
        } catch (\Throwable $e) {
            $relative !== $old && $disk->delete($relative);

            throw $e;
        }

        // qayta olingan holatda eski fayl o'chiriladi
        $old && $old !== $relative && $disk->delete($old);

        return null;
    }

    /** Tuzatish menyusi: qaysi qismini qayta olish (rasm/video uchun avval joylashuv tekshiriladi) yoki yuborishga o'tish. */
    public function edit(Request $request): JsonResponse
    {
        $request->validate([
            'initData' => 'required|string|max:4096',
            'item' => 'required|in:photo,video,documents,confirm',
        ]);

        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if (! $in->isRework() || $in->step !== 'edit') {
            return $this->wrongStep();
        }

        $item = $request->input('item');
        if ($item === 'documents' && $in->kindValue() !== Inspection::KIND_COURT) {
            return $this->wrongStep();
        }
        if ($item === 'confirm') {
            if (! (new InspectionHandler)->complete($in)) {
                return $this->fail('Natija to‘liq emas: kerakli rasm, video yoki hujjatni qayta oling.');
            }
            $in->update(['step' => 'confirm']);

            return response()->json($this->snapshot($in->refresh()));
        }

        // rasm yoki videoni qayta olish faqat obyekt hududida — joylashuv bir marta qayta tekshiriladi
        $here = $item === 'documents' || $in->rework_located;
        $in->update(['edit_target' => $item, 'step' => $here ? $item : 'location']);

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Sud qarori nusxasi: rasm yoki PDF, bittadan yuklanadi. */
    public function document(Request $request): JsonResponse
    {
        $request->validate([
            'initData' => 'required|string|max:4096',
            'file' => 'required|file|max:'.self::MAX_DOC_KB,
        ]);

        [$user, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'documents') {
            return $this->wrongStep();
        }

        $file = $request->file('file');
        $ext = self::DOC_EXT[$file->getMimeType()] ?? null;
        if (! $ext || $file->getSize() < 1_000) {
            return $this->fail('Faqat rasm (JPG, PNG) yoki PDF fayl yuklash mumkin.');
        }

        $docs = $in->documents();
        if ($docs->count() >= Inspection::MAX_DOCUMENTS) {
            return $this->fail('Ko‘pi bilan '.Inspection::MAX_DOCUMENTS.' ta fayl yuklash mumkin.');
        }

        // bitta qaror bir nechta obyektga tegishli bo'lishi mumkin — takror tekshiruvi faqat shu tekshiruv ichida
        $hash = sha1_file($file->getRealPath());
        if ($docs->contains('file_unique_id', "doc:{$in->id}:{$hash}")) {
            return $this->fail('Bu fayl allaqachon yuklangan.');
        }

        $n = 1 + (int) $docs->map(fn ($m) => (int) substr($m->kind, strlen('document_')))->max();
        $kind = "document_{$n}";
        $relative = "inspections/{$in->id}/{$kind}_".substr($hash, 0, 12).".{$ext}";
        if (! Storage::disk(config('tutash.media_disk'))->putFileAs(dirname($relative), $file, basename($relative))) {
            return $this->fail('Faylni saqlab bo‘lmadi. Qayta urinib ko‘ring.', 500);
        }
        $in->media()->create([
            'kind' => $kind, 'telegram_file_id' => 'webapp', 'file_unique_id' => "doc:{$in->id}:{$hash}",
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'path' => $relative, 'downloaded_at' => now(),
        ]);
        AuditLog::record('inspection.document_uploaded', $user['id'], ['inspection' => $in->id, 'kind' => $kind]);

        return response()->json($this->snapshot($in->refresh()));
    }

    public function documentRemove(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096', 'kind' => ['required', 'string', 'regex:/^document_\d{1,2}$/']]);

        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'documents') {
            return $this->wrongStep();
        }
        if ($media = $in->media()->where('kind', $request->input('kind'))->first()) {
            Storage::disk(config('tutash.media_disk'))->delete($media->path);
            $media->delete();
        }

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Hujjatlar yuklandi; ixtiyoriy izoh (qaysi sud, qaror raqami va sanasi). */
    public function documentsDone(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096', 'reason' => 'nullable|string|max:2000']);

        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'documents') {
            return $this->wrongStep();
        }
        if ($in->documents()->isEmpty()) {
            return $this->fail('Sud qarori nusxasini (rasm yoki PDF) yuklang.');
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $request->input('reason')));
        $in->update([...$this->next($in, 'documents'), ...($text !== '' ? ['reason' => mb_substr($text, 0, 1000)] : [])]);

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Izoh bo'yicha tasdiqda: nima qilingani haqida qisqa izoh. */
    public function reason(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096', 'reason' => 'required|string|max:2000']);

        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'reason') {
            return $this->wrongStep();
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $request->input('reason')));
        if (mb_strlen($text) < 10 || mb_strlen($text) > 1000) {
            return $this->fail('Izoh 10 tadan 1000 tagacha belgidan iborat bo‘lishi kerak.');
        }

        $in->update(['reason' => $text, ...$this->next($in, 'reason')]);

        return response()->json($this->snapshot($in->refresh()));
    }

    public function submit(Request $request, Nutgram $bot): JsonResponse
    {
        [$user, $staff, $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }

        $done = InspectionHandler::finalize($staff);
        if (! $done) {
            return $this->fail('Bu tekshiruv avval yuborilgan yoki to‘liq emas.', 409);
        }

        AuditLog::record('inspection.submitted', $user['id'], ['inspection' => $done->id, 'site' => $done->site_id, 'kind' => $done->kindValue(), 'suspicious' => $done->suspicious]);

        $text = $done->isFollowup() ? 'Moderator izohi bo‘yicha bajarilgan ish yuborildi. Moderator ko‘rib chiqadi.'
            : ($done->isRework() ? 'Tuzatilgan natija qayta yuborildi. ' : '').match ($done->kindValue()) {
                Inspection::KIND_BEFORE => 'Obyektning <b>oldingi holati</b> qayd etildi. Buzish boshlanganda «Tekshiruvni boshlash → Tekshirish» orqali jarayonni yuboring.',
                Inspection::KIND_PROCESS => 'Obyekt <b>buzish jarayonida</b> deb belgilandi. Buzib bo‘lingach, keyingi holatni yuboring.',
                Inspection::KIND_COURT => 'Obyekt bo‘yicha <b>sud qarori bekor qilingani</b> yuborildi. Moderator tasdiqlagach, obyekt umumiy hisobdan chiqariladi.',
                default => 'Obyekt «buzilgan» deb yuborildi. Natija <b>moderator tasdig‘idan keyin</b> hisobga olinadi.',
            };

        $bot->sendMessage(Script::apply("✅ Tekshiruv natijasi saqlandi.\n".$text), [
            'chat_id' => $user['id'], 'parse_mode' => ParseMode::HTML,
            'reply_markup' => Ui::markup([[Ui::btn('🏠 Bosh sahifa', 'home')]]),
        ]);

        return response()->json(['ok' => true, 'done' => true]);
    }

    public function cancel(Request $request): JsonResponse
    {
        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }

        $in->delete(); // fayllar Inspection::deleted hodisasida o'chiriladi

        return response()->json(['ok' => true, 'done' => true]);
    }
}
