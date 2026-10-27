<?php

namespace App\Http\Controllers;

use App\Jobs\MergeAttendanceVideo;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Support\Device;
use App\Support\Gps;
use App\Support\MediaTool;
use App\Telegram\Handlers\AttendanceHandler;
use App\Telegram\Support\Script;
use App\Telegram\Support\WebAppAuth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Davomat Mini App'i: joylashuv qurilma GPS'idan, video esa shu oynada jonli kameradan olinadi.
 * Natijalar Telegram imzosi (initData) bilan keladi, shuning uchun ularni boshqa chatdan uzatib (forward) yoki galereyadan yuklab bo'lmaydi.
 */
class AttendanceLocationController extends Controller
{
    private const MAX_VIDEO_KB = 1900;

    private const MAX_SECONDS = 15;

    private const EXT = ['video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'webm'];

    public function page()
    {
        return response()->view('attendance-location', [
            'minSeconds' => (int) config('tutash.attendance.min_video_seconds'),
        ])->header('Cache-Control', 'no-store');
    }

    /** Foydalanuvchi va uning bugungi davomat yozuvi (imzo to'g'ri bo'lsa). */
    private function resolve(Request $request): array
    {
        $user = WebAppAuth::user((string) $request->input('initData'));
        if (! $user) {
            return [null, null, response()->json(['ok' => false, 'message' => 'Telegram imzosi tasdiqlanmadi. Botdan tugmani qayta bosing.'], 403)];
        }

        $staff = Staff::where('telegram_id', $user['id'])->where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE)->first();
        $row = $staff ? Attendance::where('staff_id', $staff->id)->whereDate('date', today())->first() : null;

        return [$user, $row, null];
    }

    public function state(Request $request): JsonResponse
    {
        [$user, $row, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }

        if ($row && $row->isOpen() && now()->greaterThan(Attendance::deadline())) {
            $row->update(['status' => Attendance::MISSED]);
        }

        $step = match ($row?->status) {
            Attendance::LOCATING => 'location',
            Attendance::RECORDING => 'video',
            Attendance::PRESENT => 'done',
            default => 'none',
        };

        return response()->json(['ok' => true, 'step' => $step, 'max' => self::MAX_SECONDS]);
    }

    public function store(Request $request, Nutgram $bot): JsonResponse
    {
        $data = $request->validate([
            'initData' => 'required|string|max:4096',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'device' => 'nullable|string|max:1500',
        ]);

        [$user, $row, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }

        if (! $row || $row->status !== Attendance::LOCATING) {
            return response()->json(['ok' => false, 'message' => 'Hozir joylashuv qabul qilinmaydi. Botga qaytib, «Ha, ishni boshladim» tugmasini bosing.'], 409);
        }
        if (now()->greaterThan(Attendance::deadline())) {
            $row->update(['status' => Attendance::MISSED]);

            return response()->json(['ok' => false, 'message' => 'Davomatni qabul qilish muddati tugagan.'], 409);
        }

        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;
        if ($message = Gps::accuracyError($accuracy)) {
            AuditLog::record('attendance.low_gps_accuracy', $user['id'], ['attendance' => $row->id, 'accuracy' => $accuracy]);

            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        // Xodim o'z hududida ekanini tekshirish: biriktirilgan tumanlardagi eng yaqin obyektgacha masofa.
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $distance = Gps::nearestSiteDistance($row->staff, $lat, $lng);
        $far = $distance !== null && $distance > config('tutash.attendance.max_distance_m');
        $flags = [...($far ? ['far_from_district'] : []), ...Gps::flags($accuracy)];

        $row->update([
            'lat' => $lat, 'lng' => $lng, 'status' => Attendance::RECORDING,
            'flags' => $flags ?: null,
            'device' => Device::clean($request->input('device')) ?? $row->device,
        ]);
        AuditLog::record('attendance.location', $user['id'], ['attendance' => $row->id, 'accuracy' => $accuracy, 'nearest_site_m' => $distance, 'flags' => $flags]);

        $bot->sendMessage(Script::apply("📍 Joylashuv qurilmadan qabul qilindi.\n\n<b>2/2.</b> Endi hududdagi holatingizni aks ettiruvchi videoni (".config('tutash.attendance.min_video_seconds').'–'.self::MAX_SECONDS." soniya) shu oynada kamera orqali yozing.\nOyna yopilgan bo‘lsa, pastdagi tugma orqali qayta oching.\n<i>Faqat shu oynada jonli yozilgan video qabul qilinadi.</i>"), [
            'chat_id' => $user['id'], 'parse_mode' => ParseMode::HTML,
            'reply_markup' => AttendanceHandler::webAppMarkup('🎥 Videoni yozish'),
        ]);

        return response()->json(['ok' => true]);
    }

    public function video(Request $request, Nutgram $bot, MediaTool $tool): JsonResponse
    {
        $request->validate([
            'initData' => 'required|string|max:4096',
            'video' => 'required|file|max:'.self::MAX_VIDEO_KB,
            'face' => 'nullable|file|max:'.self::MAX_VIDEO_KB,
            'duration' => 'required|numeric|min:0|max:'.(self::MAX_SECONDS + 2),
            'device' => 'nullable|string|max:1500',
        ]);

        [$user, $row, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }

        if (! $row || $row->status !== Attendance::RECORDING) {
            return response()->json(['ok' => false, 'message' => 'Hozir video qabul qilinmaydi. Avval joylashuvni yuboring.'], 409);
        }
        if (now()->greaterThan(Attendance::deadline())) {
            $row->update(['status' => Attendance::MISSED]);

            return response()->json(['ok' => false, 'message' => 'Davomatni qabul qilish muddati tugagan.'], 409);
        }

        $min = (int) config('tutash.attendance.min_video_seconds');
        $duration = (float) $request->input('duration');
        if ($duration < $min) {
            return response()->json(['ok' => false, 'message' => "Video juda qisqa. Kamida {$min} soniya yozing."], 422);
        }

        $file = $request->file('video');
        $ext = self::EXT[$file->getMimeType()] ?? null;
        if (! $ext || $file->getSize() < 20_000) {
            return response()->json(['ok' => false, 'message' => 'Video formati qabul qilinmadi. Qayta yozing.'], 422);
        }

        $hash = sha1_file($file->getRealPath());
        $dir = "attendance/{$row->date->format('Y-m-d')}";
        $relative = "{$dir}/{$row->id}_".substr($hash, 0, 12).".{$ext}";

        $device = Device::clean($request->input('device')) ?? $row->device;
        if ($tool->isBlack($file->getRealPath())) {
            AuditLog::record('attendance.black_video', $user['id'], ['attendance' => $row->id, 'device' => Device::label($device)]);
            $row->update(['device' => $device]);

            return response()->json(['ok' => false, 'message' => 'Kamera qora tasvir berdi. Telegramni yangilang, kamera ruxsatini tekshiring va qayta urinib ko‘ring.'], 422);
        }

        // Yuz ko'rinadigan qisqa old-kamera lavhasi (ixtiyoriy); qora bo'lsa tashlab yuboriladi.
        $face = $request->file('face');
        if ($face && $tool->isBlack($face->getRealPath())) {
            $face = null;
        }
        $faceExt = $face ? (self::EXT[$face->getMimeType()] ?? null) : null;
        $faceRelative = $faceExt && $face->getSize() >= 3_000 ? "{$dir}/{$row->id}_face_".substr($hash, 0, 12).".{$faceExt}" : null;

        // Avval fayllar diskka yoziladi, keyin baza: "keldi" deb belgilangan yozuv hech qachon yo'q faylga ishora qilmaydi.
        $disk = Storage::disk(config('tutash.media_disk'));
        $saved = $disk->putFileAs($dir, $file, basename($relative))
            && (! $faceRelative || $disk->putFileAs($dir, $face, basename($faceRelative)));
        $discard = fn () => $disk->delete(array_filter([$relative, $faceRelative]));
        if (! $saved) {
            $discard();

            return response()->json(['ok' => false, 'message' => 'Videoni saqlab bo‘lmadi. Qayta urinib ko‘ring.'], 500);
        }

        try {
            $row->update([
                'video_unique_id' => $hash, 'video_duration' => (int) round($duration),
                'status' => Attendance::PRESENT, 'responded_at' => now(),
                'video_path' => $relative, 'face_video_path' => $faceRelative, 'video_downloaded_at' => now(), 'device' => $device,
            ]);
        } catch (UniqueConstraintViolationException) {
            $discard();

            return response()->json(['ok' => false, 'message' => 'Bu video avval yuborilgan. Yangi video yozing.'], 422);
        } catch (\Throwable $e) {
            $discard();

            throw $e;
        }

        if ($faceRelative) {
            MergeAttendanceVideo::dispatch($row->id); // ikki lavha bitta videoga birlashtiriladi
        }
        AuditLog::record('attendance.present', $user['id'], ['attendance' => $row->id, 'duration' => $duration]);

        $bot->sendMessage(Script::apply('✅ Davomatingiz qayd etildi ('.now()->format('H:i').'). Ishingizda muvaffaqiyat tilaymiz.'), [
            'chat_id' => $user['id'], 'parse_mode' => ParseMode::HTML,
        ]);

        return response()->json(['ok' => true]);
    }
}
