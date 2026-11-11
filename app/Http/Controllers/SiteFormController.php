<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Telegram\Handlers\BrowseHandler;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use App\Telegram\Support\WebAppAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Kadastr xodimi Mini App'dan buzilishi kerak bo'lgan obyekt qo'shadi yoki o'zi qo'shgan (tasdiqlanmagan) obyektni tahrirlaydi.
 * Obyekt admin tasdiqlaguncha `approval = pending` — hech qayerda (ro'yxat, svod, boshqa xodimlar) ko'rinmaydi.
 */
class SiteFormController extends Controller
{
    public function page()
    {
        return response()->view('site-form')->header('Cache-Control', 'no-store');
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    /** @return array{0: ?Staff, 1: ?JsonResponse} */
    private function staff(Request $request): array
    {
        $user = WebAppAuth::user((string) $request->input('initData'));
        if (! $user) {
            return [null, $this->fail('Oyna eskirgan. Uni yopib, botdagi tugmani qayta bosing.', 403)];
        }
        $staff = Staff::where('telegram_id', $user['id'])->where('status', Staff::ACTIVE)->where('role', Staff::ROLE_CADASTRE)->first();

        return $staff ? [$staff, null] : [null, $this->fail('Bu oyna faqat kadastr xodimlari uchun.', 403)];
    }

    /** Tahrirlash mumkin: o'zi qo'shgan va hali tasdiqlanmagan (kutilmoqda yoki rad etilgan). */
    private function editable(Staff $staff, $id): ?Site
    {
        return $id ? $staff->addedSites()->whereNotNull('approval')->find((int) $id) : null;
    }

    public function state(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096', 'site' => 'nullable|integer']);
        [$staff, $error] = $this->staff($request);
        if ($error) {
            return $error;
        }
        $site = $this->editable($staff, $request->input('site'));
        if ($request->filled('site') && ! $site) {
            return $this->fail('Bu obyektni tahrirlab bo‘lmaydi (tasdiqlangan yoki sizniki emas).', 404);
        }

        $districts = $staff->accessibleDistricts()->orderBy('name')->get(['districts.id', 'districts.name']);

        return response()->json([
            'ok' => true,
            'districts' => $districts->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name,
                'mahallas' => Mahalla::where('district_id', $d->id)->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->all(),
            ])->all(),
            'site' => $site ? [
                'id' => $site->id, 'district_id' => $site->district_id, 'mahalla_id' => $site->mahalla_id, 'address' => $site->address,
                'lat' => $site->lat, 'lng' => $site->lng, 'area_m2' => $site->area_m2, 'is_tutash' => $site->is_tutash,
                'violator_name' => $site->violator_name, 'violator_passport' => $site->violator_passport, 'org_inn' => $site->org_inn,
                'court_number' => $site->court_number, 'court_date' => $site->court_date?->toDateString(), 'court_status' => $site->court_status,
                'approval' => $site->approval, 'approval_note' => $site->approval_note,
            ] : null,
        ]);
    }

    public function save(Request $request, Nutgram $bot): JsonResponse
    {
        $data = $request->validate([
            'initData' => 'required|string|max:4096',
            'site' => 'nullable|integer',
            'district_id' => 'required|integer',
            'mahalla_id' => 'nullable|integer',
            'mahalla_name' => 'nullable|string|max:150',
            'address' => 'required|string|min:5|max:500',
            'lat' => 'nullable|numeric|between:37,46',
            'lng' => 'nullable|numeric|between:55,74',
            'area_m2' => 'required|numeric|min:1|max:1000000',
            'is_tutash' => 'nullable|boolean',
            'violator_name' => 'required|string|min:3|max:255',
            'violator_passport' => 'nullable|string|max:20',
            'org_inn' => 'nullable|string|max:20',
            'court_number' => 'nullable|string|max:100',
            'court_date' => 'nullable|date',
            'court_status' => 'nullable|string|max:150',
        ]);
        [$staff, $error] = $this->staff($request);
        if ($error) {
            return $error;
        }
        if (! $staff->canAccessDistrict((int) $data['district_id'])) {
            return $this->fail('Bu tuman sizga biriktirilmagan.', 403);
        }
        $site = null;
        if ($request->filled('site') && ! ($site = $this->editable($staff, $data['site']))) {
            return $this->fail('Bu obyektni tahrirlab bo‘lmaydi (tasdiqlangan yoki sizniki emas).', 404);
        }

        // MFY: ro'yxatdan tanlangan (shu tumanniki) yoki yangi nomi yozilgan
        $mahallaId = null;
        if (! empty($data['mahalla_id'])) {
            $mahallaId = Mahalla::where('district_id', $data['district_id'])->whereKey($data['mahalla_id'])->value('id');
        } elseif (filled($data['mahalla_name'] ?? null)) {
            $mahallaId = Mahalla::firstOrCreate(['district_id' => $data['district_id'], 'name' => trim($data['mahalla_name'])])->id;
        }
        if (! $mahallaId) {
            return $this->fail('MFY ni tanlang yoki nomini yozing.');
        }

        $clean = fn ($v) => filled($v) ? trim((string) $v) : null;
        $attrs = [
            'district_id' => (int) $data['district_id'], 'mahalla_id' => $mahallaId, 'address' => trim($data['address']),
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null, 'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'area_m2' => (float) $data['area_m2'], 'is_tutash' => (bool) ($data['is_tutash'] ?? true),
            'violator_name' => trim($data['violator_name']), 'violator_passport' => $clean($data['violator_passport'] ?? null),
            'org_inn' => $clean($data['org_inn'] ?? null), 'court_number' => $clean($data['court_number'] ?? null),
            'court_date' => $data['court_date'] ?? null, 'court_status' => $clean($data['court_status'] ?? null),
            // tahrirlansa — qayta tasdiqqa
            'approval' => Site::APPROVAL_PENDING, 'approval_note' => null,
        ];

        if ($site) {
            $site->update($attrs);
        } else {
            $site = Site::create($attrs + ['status' => Site::NOT_DEMOLISHED, 'added_by_staff_id' => $staff->id]);
        }
        AuditLog::record($request->filled('site') ? 'site.cadastre_updated' : 'site.cadastre_added', $staff->telegram_id, ['site' => $site->id]);

        try { // saqlangan; Telegram sekin bo'lsa ham oynaga xato qaytmasin
            $bot->sendMessage(Script::apply('✅ <b>Obyekt yuborildi</b> — admin tasdiqlagach ro‘yxatga qo‘shiladi.'."\n📍 ".Ui::e($site->address)), [
                'chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML,
                'reply_markup' => Ui::markup([[BrowseHandler::siteFormButton('➕ Yana obyekt qo‘shish')], [Ui::btn('📋 Men qo‘shgan obyektlar', 'cad:mine')]]),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'id' => $site->id]);
    }
}
