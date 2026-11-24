<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin paneldagi har bir o'zgarish (obyekt, ijara shartnomasi) bazaga yoziladi: kim (panel foydalanuvchisi), qachon,
 * qanday amal va har bir maydonning eski → yangi qiymati. Bot orqali (xodim) qilingan o'zgarishlar o'z loglarida.
 * Maxfiy maydonlar (STIR, pasport, JSHSHIR) qiymati yozilmaydi — faqat «o'zgardi».
 */
class PanelAuditObserver
{
    private const SKIP = ['updated_at', 'created_at', 'docx_path', 'pdf_path'];

    private const SECRET = ['inn', 'passport', 'jshshir', 'violator_passport'];

    public static function key(Model $m): string
    {
        return match (true) {
            $m instanceof \App\Models\Site => 'site',
            $m instanceof \App\Models\LeaseContract => 'lease',
            default => strtolower(class_basename($m)),
        };
    }

    private function log(Model $m, string $action, array $changes = []): void
    {
        $u = auth()->user();
        if (! $u instanceof \App\Models\User) {
            return; // panel foydalanuvchisi emas (bot, buyruq) — bu yerda yozilmaydi
        }
        AuditLog::record('panel.'.self::key($m).'.'.$action, null, [
            'id' => $m->getKey(), 'user_id' => $u->id, 'user' => $u->name, 'email' => $u->email,
            'changes' => $changes ?: null,
        ]);
    }

    public function created(Model $m): void
    {
        $this->log($m, 'created');
    }

    public function updated(Model $m): void
    {
        $changes = [];
        foreach ($m->getChanges() as $k => $new) {
            if (in_array($k, self::SKIP, true)) {
                continue;
            }
            $old = $m->getOriginal($k);
            $show = fn ($v) => in_array($k, self::SECRET, true) ? '***' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v));
            $changes[$k] = [$show($old), $show($m->getAttribute($k))];
        }
        $changes && $this->log($m, 'updated', $changes);
    }

    public function deleted(Model $m): void
    {
        $this->log($m, 'deleted');
    }

    public function restored(Model $m): void
    {
        $this->log($m, 'restored');
    }

    /** Shu yozuv bo'yicha panel tarixi (yangisi yuqorida). */
    public static function history(Model $m, int $limit = 50): \Illuminate\Support\Collection
    {
        return AuditLog::where('event', 'like', 'panel.'.self::key($m).'.%')->where('context->id', $m->getKey())
            ->latest('id')->limit($limit)->get();
    }

    /** Tarixda ko'rsatiladigan maydon nomi (bazadagi ustun nomi emas). */
    public const LABELS = [
        'district_id' => 'Tuman', 'mahalla_id' => 'MFY', 'source_no' => 'Ro‘yxat raqami', 'address' => 'Manzil', 'lat' => 'Kenglik', 'lng' => 'Uzunlik',
        'violator_name' => 'Huquqbuzar', 'violator_passport' => 'Pasport', 'org_inn' => 'STIR', 'area_m2' => 'Maydon, m²', 'is_tutash' => 'Tutash hududda',
        'court_date' => 'Sud qarori sanasi', 'court_number' => 'Sud qarori raqami', 'court_status' => 'Sud holati', 'status' => 'Holat',
        'demolished_at' => 'Buzilgan sana', 'last_inspected_at' => 'Oxirgi tekshiruv', 'photos' => 'Rasmlar', 'approval' => 'Tasdiq',
        'approval_note' => 'Rad etish sababi', 'street_id' => 'Ko‘cha', 'gastro_street_id' => 'Ko‘cha', 'staff_id' => 'Xodim',
        'party' => 'Tomon', 'tenant_name' => 'Ijarachi', 'tenant_address' => 'Ijarachi manzili', 'director' => 'Direktor', 'account' => 'Hisob raqami',
        'inn' => 'STIR', 'mfo' => 'MFO', 'phone' => 'Telefon', 'passport' => 'Pasport', 'passport_issuer' => 'Pasport bergan idora',
        'passport_year' => 'Pasport berilgan yil', 'jshshir' => 'JShShIR', 'mfy' => 'MFY', 'house' => 'Uy', 'cadastre' => 'Kadastr raqami',
        'activity' => 'Faoliyat turi', 'start_date' => 'Boshlanish sanasi', 'end_date' => 'Tugash sanasi', 'annual_amount' => 'Yillik summa',
        'application_number' => 'Ariza raqami', 'application_date' => 'Ariza sanasi', 'street_distance_m' => 'Ko‘chagacha masofa, m',
        'docx_path' => 'Shartnoma fayli', 'pdf_path' => 'PDF fayl', 'gps_accuracy' => 'GPS aniqligi', 'polygon' => 'Hudud chizmasi',
        'polygon_area_m2' => 'Chizma maydoni, m²', 'tariff' => 'Tarif (1 m² / oy)', 'deleted_at' => 'O‘chirilgan',
    ];

    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? str_replace('_', ' ', ucfirst($field));
    }

    /** Qiymat — odam o'qiydigan ko'rinishda (holat nomi, Ha/Yo‘q, sana d.m.Y, tuman nomi). */
    public static function value(string $field, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if (is_bool($v) || (in_array($field, ['is_tutash'], true))) {
            return $v ? 'Ha' : 'Yo‘q';
        }
        if (is_array($v)) {
            return in_array($field, ['polygon', 'photos'], true) ? count($v).' ta' : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $s = (string) $v;

        return match (true) {
            $field === 'status' => \App\Filament\Resources\SiteResource::statusLabel($s),
            $field === 'party' => \App\Models\LeaseContract::PARTIES[$s] ?? $s,
            $field === 'approval' => [Site::APPROVAL_PENDING => 'Tasdiq kutilmoqda', Site::APPROVAL_REJECTED => 'Rad etilgan', 'approved' => 'Tasdiqlangan'][$s] ?? $s,
            $field === 'district_id' => \App\Models\District::whereKey($s)->value('name') ?? $s,
            $field === 'gastro_street_id' => \App\Models\GastroStreet::whereKey($s)->value('name') ?? $s,
            $field === 'staff_id' => \App\Models\Staff::whereKey($s)->value('full_name') ?? $s,
            in_array($field, ['docx_path', 'pdf_path'], true) => 'yangilandi',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?/', $s) => \Illuminate\Support\Carbon::parse($s)->format(strlen($s) > 10 ? 'd.m.Y H:i' : 'd.m.Y'),
            default => $s,
        };
    }
}
