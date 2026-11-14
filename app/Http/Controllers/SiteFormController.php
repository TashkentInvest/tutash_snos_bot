<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Mahalla;
use App\Models\Site;
use App\Models\Staff;
use App\Models\Street;
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

    /** Manzilning ko'chadan keyingi qismi (uy, mo'ljal) — tahrirlash oynasi uchun. */
    private static function house(Site $site): ?string
    {
        $street = $site->street?->name;
        if ($street && str_starts_with($site->address, $street)) {
            return ltrim(substr($site->address, strlen($street)), ', ') ?: null;
        }

        return $street ? null : $site->address;
    }

    /**
     * Ro'yxatdagi yozuv (shu tumanniki) yoki nomi bo'yicha topiladi / yangisi qo'shiladi.
     * Nom katta-kichik harf va ortiqcha bo'shliqlarsiz solishtiriladi — bir xil MFY ikki marta qo'shilmaydi.
     */
    private function pick(string $model, int $districtId, $id, ?string $name, Staff $staff): Mahalla|Street|null
    {
        if (! empty($id)) {
            return $model::where('district_id', $districtId)->find((int) $id);
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if (mb_strlen($name) < 3) {
            return null;
        }

        return $model::where('district_id', $districtId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? $model::create(['district_id' => $districtId, 'name' => $name, 'created_by_staff_id' => $staff->id]);
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
        $ids = $districts->pluck('id');
        $list = fn ($model) => $model::whereIn('district_id', $ids)->orderBy('name')->get(['id', 'district_id', 'name'])
            ->groupBy('district_id')->map(fn ($rows) => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all());
        $mahallas = $list(Mahalla::class);
        $streets = $list(Street::class);
        // xarita shu tuman obyektlari o'rtasidan ochiladi
        $centers = Site::whereIn('district_id', $ids)->whereNotNull('lat')->whereNotNull('lng')
            ->groupBy('district_id')->selectRaw('district_id, avg(lat) as lat, avg(lng) as lng')->get()->keyBy('district_id');

        return response()->json([
            'ok' => true,
            // xaritada «Gastronomik ko'chalar» qatlami (xodim tumanlaridagilari)
            'gastro' => \App\Models\GastroStreet::whereIn('district_id', $ids)->get(['name', 'line'])->map(fn ($s) => ['n' => $s->name, 'l' => $s->line])->values(),
            'districts' => $districts->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name,
                'mahallas' => $mahallas[$d->id] ?? [], 'streets' => $streets[$d->id] ?? [],
                'center' => isset($centers[$d->id]) ? [round((float) $centers[$d->id]->lat, 6), round((float) $centers[$d->id]->lng, 6)] : null,
            ])->all(),
            'site' => $site ? [
                'id' => $site->id, 'district_id' => $site->district_id, 'mahalla_id' => $site->mahalla_id, 'address' => $site->address,
                'street_id' => $site->street_id, 'street' => $site->street?->name, 'house' => self::house($site), 'status' => $site->status,
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
            'street_id' => 'nullable|integer',
            'street_name' => 'nullable|string|max:150',
            'house' => 'nullable|string|max:300',
            'status' => 'required|in:'.Site::NOT_DEMOLISHED.','.Site::IN_PROGRESS,
            'lat' => 'required|numeric|between:37,46',
            'lng' => 'required|numeric|between:55,74',
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

        // MFY va ko'cha: ro'yxatdan tanlangan (shu tumanniki) yoki yangi nomi yozilgan — yangisi kim qo'shgani bilan saqlanadi
        $mahalla = $this->pick(Mahalla::class, (int) $data['district_id'], $data['mahalla_id'] ?? null, $data['mahalla_name'] ?? null, $staff);
        if (! $mahalla) {
            return $this->fail('MFY ni tanlang yoki nomini yozing.');
        }
        $street = $this->pick(Street::class, (int) $data['district_id'], $data['street_id'] ?? null, $data['street_name'] ?? null, $staff);
        if (! $street) {
            return $this->fail('Ko‘chani tanlang yoki nomini yozing.');
        }
        $mahallaId = $mahalla->id;
        $house = filled($data['house'] ?? null) ? trim($data['house']) : null;

        $clean = fn ($v) => filled($v) ? trim((string) $v) : null;
        $attrs = [
            'district_id' => (int) $data['district_id'], 'mahalla_id' => $mahallaId, 'street_id' => $street->id,
            'address' => $street->name.($house ? ', '.$house : ''), 'status' => $data['status'],
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null, 'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'area_m2' => (float) $data['area_m2'], 'is_tutash' => (bool) ($data['is_tutash'] ?? true),
            'violator_name' => trim($data['violator_name']), 'violator_passport' => $clean($data['violator_passport'] ?? null),
            'org_inn' => $clean($data['org_inn'] ?? null), 'court_number' => $clean($data['court_number'] ?? null),
            'court_date' => $data['court_date'] ?? null, 'court_status' => $clean($data['court_status'] ?? null),
            // tahrirlansa — qayta tasdiqqa
            'approval' => Site::APPROVAL_PENDING, 'approval_note' => null,
        ];

        if ($site) {
            // kim va nimani o'zgartirgani: obyektda oxirgi tahrirlovchi, jurnalda o'zgargan maydonlar
            $site->fill($attrs + ['updated_by_staff_id' => $staff->id]);
            $changed = array_values(array_diff(array_keys($site->getDirty()), ['approval', 'approval_note', 'updated_by_staff_id']));
            $site->save();
            AuditLog::record('site.cadastre_updated', $staff->telegram_id, ['site' => $site->id, 'fields' => $changed]);
        } else {
            $site = Site::create($attrs + ['added_by_staff_id' => $staff->id]);
            AuditLog::record('site.cadastre_added', $staff->telegram_id, ['site' => $site->id]);
        }

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
