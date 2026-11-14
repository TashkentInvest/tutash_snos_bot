<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\LeaseSvod;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/** Gastronomik ko'chalar → «Shartnomalar svodi»: tumanlar, ko'chalar va xodimlar kesimida; Excel yuklab olish. */
class LeaseSvodPage extends Page
{
    protected static ?string $navigationGroup = 'Gastronomik ko‘chalar';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Shartnomalar svodi';

    protected static ?string $title = 'Ijara shartnomalari — svod';

    protected static ?string $slug = 'lease-svod';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.lease-svod';

    public ?string $from = null;

    public ?string $to = null;

    public bool $unique = true;   // takroriy (qayta tuzilgan) shartnomalarsiz

    public bool $onsite = false;  // faqat joyida (ko'cha yonida) tuzilganlar

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    /** Tez tanlash: bugun / shu oy / shu yil / hammasi */
    public function preset(string $p): void
    {
        [$this->from, $this->to] = match ($p) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), null],
            'year' => [now()->startOfYear()->toDateString(), null],
            default => [null, null],
        };
    }

    /** «Telegramga yuborish»: hozirgi holat (bugun) — oluvchilar sozlamadagilar, o'zgartirish mumkin. */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('sendTelegram')->label('Telegramga yuborish')->icon('heroicon-o-paper-airplane')
                ->modalHeading('Gastronomik svod — '.today()->format('d.m.Y'))->modalWidth('3xl')->modalSubmitActionLabel('Yuborish')
                ->form(fn () => [
                    \Filament\Forms\Components\Toggle::make('onsite')->label('Faqat joyida tuzilganlar')->default($this->onsite || \App\Models\ReportSetting::gastroOptions()['onsite'])->live(),
                    \Filament\Forms\Components\CheckboxList::make('recipients')->label('Kimlarga (oldindan — Sozlamalar → Hisobotlar)')
                        ->options(fn () => ReportSettings::people())
                        ->default(fn () => array_map('strval', \App\Models\ReportSetting::for(\App\Models\ReportSetting::GASTRO)->recipients ?? []))
                        ->required()->searchable()->bulkToggleable()->columns(2)
                        ->validationMessages(['required' => 'Kamida bitta oluvchini belgilang.']),
                    \Filament\Forms\Components\Placeholder::make('preview')->label('Botda shunday ko‘rinadi')
                        ->content(fn (\Filament\Forms\Get $get) => new \Illuminate\Support\HtmlString(
                            '<img src="'.e(route('admin.lease-svod.png', ['onsite' => $get('onsite') ? 1 : 0])).'&t='.now()->timestamp
                            .'" style="width:100%;border-radius:.5rem;background:#fff;margin-bottom:.75rem;"><div style="font-size:.875rem;line-height:1.5;">'
                            .nl2br(\App\Telegram\Support\Script::apply(\App\Telegram\Support\LeaseReport::caption(today(), (bool) $get('onsite')))).'</div>')),
                ])
                ->action(function (array $data) {
                    $to = \App\Models\Staff::whereIn('id', array_map('intval', $data['recipients'] ?? []))
                        ->where('status', \App\Models\Staff::ACTIVE)->whereNotNull('telegram_id')->get();
                    $sent = \App\Telegram\Support\LeaseReport::send(app(\SergiX44\Nutgram\Nutgram::class), today(), $to, (bool) ($data['onsite'] ?? false));
                    \App\Models\AuditLog::record('report.manual.gastro_svod', null, ['by' => auth()->id(), 'sent' => $sent]);
                    \Filament\Notifications\Notification::make()->title($sent ? "Yuborildi: {$sent} ta xabar" : 'Yuborilmadi: oluvchilar botni bloklagan bo‘lishi mumkin')
                        ->{$sent ? 'success' : 'warning'}()->send();
                }),
        ];
    }

    private function date(?string $v): ?Carbon
    {
        try {
            return filled($v) ? Carbon::parse($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getViewData(): array
    {
        return [
            'd' => LeaseSvod::data($this->date($this->from), $this->date($this->to), $this->unique, $this->onsite),
            'xlsx' => route('admin.lease-svod', array_filter(['from' => $this->from, 'to' => $this->to,
                'unique' => $this->unique ? 1 : 0, 'onsite' => $this->onsite ? 1 : 0], fn ($v) => $v !== null)),
        ];
    }
}
