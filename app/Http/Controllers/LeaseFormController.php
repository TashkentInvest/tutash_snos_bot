<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\LeaseContract;
use App\Models\Mahalla;
use App\Models\Staff;
use App\Support\Gps;
use App\Support\LeaseContractDocument;
use App\Telegram\Handlers\BrowseHandler;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use App\Telegram\Support\WebAppAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * Ijara shartnomasi (Mini App): shartnoma mas'uli («Gastronomik ko'chalar → Mas'ullar») o'z tumanidagi gastronomik ko'chadagi
 * obyekt uchun sariq maydonlarni to'ldiradi — tizim namunadan Word (va PDF) yasab, botga yuboradi. Tasdiq yo'q: chop etilib imzolanadi.
 */
class LeaseFormController extends Controller
{
    public function page()
    {
        return response()->view('lease-form')->header('Cache-Control', 'no-store');
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    /** @return array{0: ?Staff, 1: array<int>, 2: ?JsonResponse} */
    private function staff(Request $request): array
    {
        $user = WebAppAuth::user((string) $request->input('initData'));
        if (! $user) {
            return [null, [], $this->fail('Oyna eskirgan. Uni yopib, botdagi tugmani qayta bosing.', 403)];
        }
        $staff = Staff::where('telegram_id', $user['id'])->where('status', Staff::ACTIVE)->first();
        $ids = $staff ? GastroResponsible::districtIdsFor($staff) : [];

        return $ids ? [$staff, $ids, null] : [null, [], $this->fail('Bu oyna faqat ijara shartnomasi mas’ullari uchun.', 403)];
    }

    public function state(Request $request): JsonResponse
    {
        $request->validate(['initData' => 'required|string|max:4096']);
        [, $ids, $error] = $this->staff($request);
        if ($error) {
            return $error;
        }
        $streets = GastroStreet::whereIn('district_id', $ids)->orderBy('name')->get()->groupBy('district_id');
        $mahallas = Mahalla::whereIn('district_id', $ids)->orderBy('name')->get(['district_id', 'name'])->groupBy('district_id');

        return response()->json([
            'ok' => true,
            'radius' => (int) config('tutash.max_distance_m'),
            'max_accuracy' => (int) config('tutash.max_gps_accuracy_m'),
            'activities' => LeaseContract::ACTIVITIES,
            'districts' => District::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'boundary'])->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'boundary' => $d->boundary ?? [],
                'streets' => ($streets[$d->id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'line' => $s->line])->values(),
                'mahallas' => ($mahallas[$d->id] ?? collect())->pluck('name')->values(),
            ])->values(),
        ]);
    }

    public function save(Request $request, Nutgram $bot): JsonResponse
    {
        $legal = $request->input('party') === LeaseContract::LEGAL;
        $data = $request->validate([
            'initData' => 'required|string|max:4096',
            'party' => 'required|in:'.LeaseContract::LEGAL.','.LeaseContract::INDIVIDUAL,
            'district_id' => 'required|integer',
            'gastro_street_id' => 'required|integer',
            'lat' => 'required|numeric|between:37,46',
            'lng' => 'required|numeric|between:55,74',
            'accuracy' => 'required|numeric|min:0', // faqat telefon GPS'i — xodim obyekt oldida
            'tenant_name' => 'required|string|min:3|max:255',
            'tenant_address' => ($legal ? 'required' : 'nullable').'|string|max:500',
            'director' => ($legal ? 'required' : 'nullable').'|string|min:5|max:150',
            'account' => 'nullable|string|max:40',
            'inn' => 'nullable|string|max:20',
            'mfo' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:30',
            'passport' => 'nullable|string|max:20',
            'passport_issuer' => 'nullable|string|max:200',
            'passport_year' => 'nullable|string|max:10',
            'jshshir' => 'nullable|string|max:20',
            'mfy' => 'required|string|min:3|max:150',
            'house' => 'nullable|string|max:100',
            'cadastre' => 'required|string|min:5|max:60',
            'area_m2' => 'required|numeric|min:0.5|max:100000',
            'activity' => 'required|string|min:3|max:150',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'annual_amount' => 'required|numeric|min:1|max:100000000000',
            'application_number' => 'nullable|string|max:40',
            'application_date' => 'nullable|date',
        ], [
            'tenant_address.required' => 'Tashkilot manzilini yozing.',
            'director.required' => 'Direktor F.I.SH ni yozing.',
            'end_date.after' => 'Tugash sanasi boshlanish sanasidan keyin bo‘lishi kerak.',
        ]);
        [$staff, $ids, $error] = $this->staff($request);
        if ($error) {
            return $error;
        }
        if (! in_array((int) $data['district_id'], $ids, true)) {
            return $this->fail('Bu tuman shartnomalari sizga biriktirilmagan.', 403);
        }
        $street = GastroStreet::where('district_id', $data['district_id'])->find((int) $data['gastro_street_id']);
        if (! $street) {
            return $this->fail('Ko‘chani ro‘yxatdan tanlang.');
        }
        // GPS aniqligi — tekshiruvlardagi kabi
        if ($message = Gps::accuracyError((float) $data['accuracy'])) {
            return $this->fail($message);
        }
        // faqat gastronomik ko'chadagi obyekt: tanlangan ko'cha chizig'idan ruxsat etilgan masofa ichida (obyektlardagi kabi)
        $radius = (int) config('tutash.max_distance_m');
        $distance = (int) round(Gps::distanceToLine((float) $data['lat'], (float) $data['lng'], $street->line) ?? INF);
        if ($distance > $radius) {
            return $this->fail("Siz «{$street->name}» ko‘chasidan taxminan {$distance} m uzoqdasiz. Shartnoma faqat gastronomik ko‘chadagi obyektlarga tuziladi (ko‘chadan {$radius} m ichida) — obyekt oldiga borib, joylashuvni qayta oling.");
        }

        $clean = fn ($k) => filled($data[$k] ?? null) ? trim((string) $data[$k]) : null;
        $contract = LeaseContract::create([
            'district_id' => (int) $data['district_id'], 'gastro_street_id' => $street->id, 'staff_id' => $staff->id,
            'party' => $data['party'], 'lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'street_distance_m' => $distance,
            'area_m2' => (float) $data['area_m2'], 'annual_amount' => round((float) $data['annual_amount'], 2),
            'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'application_date' => $data['application_date'] ?? null,
            ...collect(['tenant_name', 'tenant_address', 'director', 'account', 'inn', 'mfo', 'phone', 'passport', 'passport_issuer',
                'passport_year', 'jshshir', 'mfy', 'house', 'cadastre', 'activity', 'application_number'])
                ->mapWithKeys(fn ($k) => [$k => $clean($k)])->all(),
        ]);
        if (! $legal) { // yuridik shaxsga xos maydonlar jismoniyda saqlanmaydi (va aksincha)
            $contract->update(['director' => null, 'account' => null, 'inn' => null, 'mfo' => null]);
        } else {
            $contract->update(['passport' => null, 'passport_issuer' => null, 'passport_year' => null, 'jshshir' => null]);
        }

        [$docx, $pdf] = LeaseContractDocument::render($contract->refresh());
        $contract->update(['docx_path' => $docx, 'pdf_path' => $pdf]);
        AuditLog::record('lease.created', $staff->telegram_id, ['contract' => $contract->id, 'district' => $contract->district_id]);

        $sent = self::sendFiles($bot, $contract, $staff->telegram_id);

        return response()->json(['ok' => true, 'id' => $contract->id, 'sent' => $sent]);
    }

    /** Tayyor fayl(lar)ni botga yuboradi (Word va bo'lsa PDF). */
    public static function sendFiles(Nutgram $bot, LeaseContract $c, int|string $chatId): bool
    {
        $disk = Storage::disk('local');
        $caption = Script::apply('📄 <b>Ijara shartnomasi №'.$c->id.'</b> — '.Ui::e($c->tenant_name)
            ."\n📍 ".Ui::e($c->district?->name.', '.$c->street?->name.(filled($c->house) ? ', '.$c->house : ''))
            ."\n💰 ".LeaseContractDocument::money($c->annual_amount).' so‘m · '.Ui::e(rtrim(rtrim(number_format($c->area_m2, 2, ',', ''), '0'), ',')).' m²'
            ."\n\nChop etib, tomonlar imzolaydi. Raqam va sanani direksiya ro‘yxatga olishda yozadi.");
        try {
            foreach (array_filter([$c->docx_path, $c->pdf_path]) as $i => $path) {
                // xotiradan yuboriladi — fayl qulflanib qolmaydi
                $fh = fopen('php://temp', 'r+');
                fwrite($fh, $disk->get($path));
                rewind($fh);
                $bot->sendDocument(InputFile::make($fh, basename($path)), array_filter([
                    'chat_id' => $chatId, 'parse_mode' => ParseMode::HTML, 'caption' => $i === 0 ? $caption : null,
                    'reply_markup' => $i === 0 ? Ui::markup([[Ui::btn('➕ Yana shartnoma', 'ls')]]) : null,
                ]));
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
