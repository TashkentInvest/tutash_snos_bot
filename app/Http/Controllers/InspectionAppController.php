<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionMedia;
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
 *
 * location -> before_photo -> before_video(ixtiyoriy) -> demolished
 *   yo'q: reason -> confirm
 *   ha:   after_photo -> after_video(majburiy) -> confirm
 */
class InspectionAppController extends Controller
{
    private const MAX_KB = 1900;

    private const MAX_SECONDS = 15;

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

    private function snapshot(Inspection $in): array
    {
        $in->loadMissing('site.mahalla', 'media');

        return [
            'ok' => true,
            'step' => $in->step,
            'address' => $in->site->address,
            'mahalla' => $in->site->mahalla?->name,
            'demolished' => $in->is_demolished,
            'reason' => $in->reason,
            'distance' => $in->distance_m,
            'far' => in_array('far_from_site', $in->flags ?? [], true),
            'media' => $in->media->pluck('kind')->values(),
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
            'step' => 'before_photo',
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
        $kind = match ($in->step) {
            'before_photo' => InspectionMedia::BEFORE_PHOTO,
            'after_photo' => InspectionMedia::AFTER_PHOTO,
            default => null,
        };
        if (! $kind) {
            return $this->wrongStep();
        }

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

        $in->update(['step' => $kind === InspectionMedia::BEFORE_PHOTO ? 'before_video' : 'after_video', 'device' => Device::clean($request->input('device')) ?? $in->device]);

        return response()->json($this->snapshot($in->refresh()));
    }

    /** Video: yuz lavhasi + hudud lavhasi bitta mp4 ga birlashtiriladi (bo'lmasa — asosiy lavha o'zi saqlanadi). */
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
        $kind = match ($in->step) {
            'before_video' => InspectionMedia::BEFORE_VIDEO,
            'after_video' => InspectionMedia::AFTER_VIDEO,
            default => null,
        };
        if (! $kind) {
            return $this->wrongStep();
        }

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
        $faceOk = $face && isset(self::EXT[$face->getMimeType()]) && $face->getSize() >= 3_000 && ! $tool->isBlack($face->getRealPath());

        set_time_limit(180);
        $merged = tempnam(sys_get_temp_dir(), 'snos').'.mp4';
        try {
            $mergedOk = $faceOk && $tool->merge($face->getRealPath(), $main->getRealPath(), $merged);
            $error = $mergedOk
                ? $this->saveMedia($in, $kind, $merged, 'mp4', 'video/mp4', $user['id'])
                : $this->saveMedia($in, $kind, $main->getRealPath(), $ext, $main->getMimeType(), $user['id']);
        } finally {
            @unlink($merged);
        }
        if ($error) {
            return $error;
        }

        $in->update(['step' => $kind === InspectionMedia::BEFORE_VIDEO ? 'demolished' : 'confirm', 'device' => Device::clean($request->input('device')) ?? $in->device]);

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

    public function skip(Request $request): JsonResponse
    {
        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'before_video') {
            return $this->wrongStep();
        }
        $in->update(['step' => 'demolished']);

        return response()->json($this->snapshot($in->refresh()));
    }

    public function demolished(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096', 'value' => 'required|in:0,1']);

        [, , $in, $error] = $this->context($request);
        if ($error) {
            return $error;
        }
        if ($in->step !== 'demolished') {
            return $this->wrongStep();
        }

        $yes = $request->input('value') === '1' || $request->input('value') === 1;
        $in->update(['is_demolished' => $yes, 'step' => $yes ? 'after_photo' : 'reason']);

        return response()->json($this->snapshot($in->refresh()));
    }

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
            return $this->fail('Sabab 10 tadan 1000 tagacha belgidan iborat bo‘lishi kerak.');
        }

        $in->update(['reason' => $text, 'step' => 'confirm']);

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

        AuditLog::record('inspection.submitted', $user['id'], ['inspection' => $done->id, 'site' => $done->site_id, 'demolished' => $done->is_demolished, 'suspicious' => $done->suspicious]);

        $bot->sendMessage(Script::apply('✅ Tekshiruv natijasi saqlandi.'."\n".($done->is_demolished ? 'Obyekt «buzilgan» deb yuborildi. Natija <b>moderator tasdig‘idan keyin</b> hisobga olinadi.' : 'Obyekt <b>buzilmagan</b> holatda qoldi.')), [
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
