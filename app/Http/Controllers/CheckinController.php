<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\Staff;
use App\Support\Device;
use App\Support\Gps;
use App\Support\MediaTool;
use App\Telegram\Support\Script;
use App\Telegram\Support\WebAppAuth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Shartnoma xodimining soatlik qaydi (Mini App): joylashuv qurilma GPS'idan, video shu oynada jonli kameradan.
 * Oyna davomat oynasi bilan bir xil (attendance-location), faqat manzillar va matnlar boshqa.
 */
class CheckinController extends Controller
{
    private const MAX_VIDEO_KB = 1900;

    private const MAX_SECONDS = 15;

    private const EXT = ['video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'webm'];

    public function page()
    {
        return response()->view('attendance-location', [
            'minSeconds' => (int) config('tutash.attendance.min_video_seconds'),
            'title' => 'Soatlik qayd',
            'endpoints' => ['state' => '/tg/checkin-state', 'location' => '/tg/checkin-location', 'video' => '/tg/checkin-video'],
            'doneText' => '✅ Soatlik qaydingiz qabul qilindi. Oynani yopishingiz mumkin.',
            'alreadyText' => '✅ Bu soatlik qayd allaqachon qabul qilingan.',
            'noneText' => 'Hozir soatlik so‘rov yo‘q yoki javob muddati tugagan. Keyingi so‘rovni kuting.',
        ])->header('Cache-Control', 'no-store');
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    /** Foydalanuvchi va uning ochiq (muddati o'tmagan) soatlik qaydi; bo'lmasa — bugungi oxirgisi. */
    private function resolve(Request $request): array
    {
        $user = WebAppAuth::user((string) $request->input('initData'));
        if (! $user) {
            return [null, null, $this->fail('Oyna eskirgan. Uni yopib, botdagi tugmani qayta bosing.', 403)];
        }
        $staff = Staff::where('telegram_id', $user['id'])->where('status', Staff::ACTIVE)->first();
        $rows = $staff ? Checkin::with('staff')->where('staff_id', $staff->id)->whereDate('date', today())->whereNotNull('sent_at')->orderByDesc('slot')->get() : collect();

        // video yozilayotgan (muddat + qo'shimcha daqiqalar ichida) -> ochiq so'rov -> bugungi oxirgisi
        $recording = $rows->first(fn (Checkin $c) => $c->status === Checkin::RECORDING && $c->acceptsVideo());

        return [$user, $recording ?? $rows->first(fn (Checkin $c) => $c->isOpen()) ?? $rows->first(), null];
    }

    public function state(Request $request): JsonResponse
    {
        [, $row, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }
        $open = $row?->isOpen();

        return response()->json(['ok' => true, 'max' => self::MAX_SECONDS, 'step' => match (true) {
            $open && $row->status === Checkin::SENT => 'location',
            $row?->status === Checkin::RECORDING && $row->acceptsVideo() => 'video',
            $row?->status === Checkin::PRESENT => 'done',
            default => 'none',
        }]);
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
        [$user, $row, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }
        if ($row?->status === Checkin::PRESENT || ($row?->status === Checkin::RECORDING && $row->acceptsVideo())) {
            // joylashuv avval saqlangan (javob tarmoqda yo'qolgan) — xato emas
            return response()->json(['ok' => true, 'already' => true, 'step' => $row->status === Checkin::PRESENT ? 'done' : 'video']);
        }
        if (! $row?->isOpen() || $row->status !== Checkin::SENT) {
            return $this->fail('Hozir soatlik so‘rov yo‘q yoki javob muddati tugagan.', 409);
        }

        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;
        if ($message = Gps::accuracyError($accuracy)) {
            return $this->fail($message);
        }
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $distance = Gps::nearestSiteDistance($row->staff, $lat, $lng);
        $far = $distance !== null && $distance > config('tutash.attendance.max_distance_m');
        $flags = [...($far ? ['far_from_district'] : []), ...Gps::flags($accuracy)];

        $row->update(['lat' => $lat, 'lng' => $lng, 'nearest_site_m' => $distance, 'flags' => $flags ?: null, 'status' => Checkin::RECORDING,
            'device' => Device::clean($request->input('device')) ?? $row->device]);
        AuditLog::record('checkin.location', $user['id'], ['checkin' => $row->id, 'accuracy' => $accuracy, 'nearest_site_m' => $distance]);

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
        if ($row?->status === Checkin::PRESENT && $row->responded_at?->greaterThan(now()->subMinutes(10))) {
            return response()->json(['ok' => true, 'already' => true]); // saqlangan, javob yo'qolgan edi
        }
        if ($row?->status !== Checkin::RECORDING || ! $row->acceptsVideo()) {
            return $this->fail('Hozir video qabul qilinmaydi. Avval joylashuvni yuboring (yoki javob muddati tugagan).', 409);
        }

        $min = (int) config('tutash.attendance.min_video_seconds');
        $duration = (float) $request->input('duration');
        if ($duration < $min) {
            return $this->fail("Video juda qisqa. Kamida {$min} soniya yozing.");
        }
        $file = $request->file('video');
        $ext = self::EXT[$file->getMimeType()] ?? null;
        if (! $ext || $file->getSize() < 20_000) {
            return $this->fail('Video formati qabul qilinmadi. Qayta yozing.');
        }
        if ($tool->isBlack($file->getRealPath())) {
            return $this->fail('Kamera qora tasvir berdi. Telegramni yangilang, kamera ruxsatini tekshiring va qayta urinib ko‘ring.');
        }

        $hash = sha1_file($file->getRealPath());
        $dir = "checkins/{$row->date->format('Y-m-d')}";
        $relative = "{$dir}/{$row->id}_".substr($hash, 0, 12).".{$ext}";
        $face = $request->file('face');
        $faceExt = $face && ! $tool->isBlack($face->getRealPath()) ? (self::EXT[$face->getMimeType()] ?? null) : null;
        $faceRelative = $faceExt && $face->getSize() >= 3_000 ? "{$dir}/{$row->id}_face_".substr($hash, 0, 12).".{$faceExt}" : null;

        // avval fayllar diskka, keyin baza: "qayd etildi" yozuvi yo'q faylga ishora qilmaydi
        $disk = Storage::disk(config('tutash.media_disk'));
        $saved = $disk->putFileAs($dir, $file, basename($relative)) && (! $faceRelative || $disk->putFileAs($dir, $face, basename($faceRelative)));
        $discard = fn () => $disk->delete(array_filter([$relative, $faceRelative]));
        if (! $saved) {
            $discard();

            return $this->fail('Videoni saqlab bo‘lmadi. Qayta urinib ko‘ring.', 500);
        }
        try {
            $row->update(['video_unique_id' => $hash, 'video_duration' => (int) round($duration), 'video_path' => $relative, 'face_video_path' => $faceRelative,
                'status' => Checkin::PRESENT, 'responded_at' => now(), 'device' => Device::clean($request->input('device')) ?? $row->device]);
        } catch (UniqueConstraintViolationException) {
            $discard();

            return $this->fail('Bu video avval yuborilgan. Yangi video yozing.');
        }
        if ($faceRelative) {
            \App\Jobs\MergeCheckinVideo::dispatch($row->id); // old va orqa kamera lavhalari bitta videoga (davomatdagidek)
        }
        AuditLog::record('checkin.present', $user['id'], ['checkin' => $row->id, 'slot' => $row->slot]);

        try { // saqlangan; Telegram sekin bo'lsa ham Mini App'ga xato qaytmasin
            $bot->sendMessage(Script::apply("✅ Soatlik qayd ({$row->slot}) qabul qilindi — ".now()->format('H:i')), [
                'chat_id' => $user['id'], 'parse_mode' => ParseMode::HTML,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
