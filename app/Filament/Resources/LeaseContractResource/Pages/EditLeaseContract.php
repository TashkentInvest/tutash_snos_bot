<?php

namespace App\Filament\Resources\LeaseContractResource\Pages;

use App\Filament\Resources\LeaseContractResource;
use App\Models\AuditLog;
use App\Models\LeaseContract;
use App\Support\LeaseContractDocument;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Shartnomadagi xatoni tuzatish: saqlanganda to'lov qayta hisoblanadi va Word fayl yangilanadi (o'chirish bu yerda yo'q). */
class EditLeaseContract extends EditRecord
{
    protected static string $resource = LeaseContractResource::class;

    private array $before = [];

    public function getTitle(): string
    {
        return 'Shartnoma №'.$this->record->id.' — tuzatish';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->record->only(['party', 'tenant_name', 'tenant_address', 'director', 'phone', 'mfy', 'house', 'cadastre', 'area_m2', 'activity', 'start_date', 'end_date']);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // yuridik / jismoniy shaxsga xos bo'lmagan maydonlar tozalanadi (botdagi kabi)
        $legal = ($data['party'] ?? $this->record->party) === LeaseContract::LEGAL;
        foreach ($legal ? ['passport', 'passport_issuer', 'passport_year', 'jshshir'] : ['director', 'account', 'inn', 'mfo'] as $k) {
            $data[$k] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $c = $this->record->refresh();
        if ($c->tariff) { // to'lov — maydon × shartnomadagi tarif (birinchi oy kunlar bo'yicha)
            $c->update(['annual_amount' => LeaseContract::amounts($c->area_m2, $c->tariff, $c->start_date)['annual']]);
        }
        [$docx, $pdf] = LeaseContractDocument::render($c->refresh());
        $c->update(['docx_path' => $docx, 'pdf_path' => $pdf]);

        $after = $c->only(array_keys($this->before));
        $changed = array_keys(array_filter($after, fn ($v, $k) => (string) $v !== (string) ($this->before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        AuditLog::record('lease.edited', null, ['contract' => $c->id, 'by' => auth()->id(), 'changed' => $changed]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Saqlandi — Word fayl yangilandi');
    }

    protected function getRedirectUrl(): string
    {
        return LeaseContractResource::getUrl('view', ['record' => $this->record]);
    }
}
