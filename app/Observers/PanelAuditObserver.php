<?php

namespace App\Observers;

use App\Models\AuditLog;
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
}
