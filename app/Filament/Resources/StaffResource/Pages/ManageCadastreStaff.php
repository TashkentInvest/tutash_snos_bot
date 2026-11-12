<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Models\Site;
use App\Models\Staff;
use Filament\Tables;
use Filament\Tables\Table;

/** Sozlamalar → «Kadastr xodimlari»: faqat kadastr roli, har birining qo'shgan obyektlari soni bilan. */
class ManageCadastreStaff extends ManageStaff
{
    protected const DEFAULT_ROLE = Staff::ROLE_CADASTRE;

    protected static ?string $title = 'Kadastr xodimlari';

    protected static ?string $breadcrumb = 'Kadastr xodimlari';

    public function getSubheading(): ?string
    {
        return 'Har tuman uchun kadastr xodimlari: o‘z tumanida buzilishni qayd etadi (tekshiruv) va yangi obyekt qo‘shadi — ikkalasini ham siz tasdiqlaysiz.';
    }

    /** Qo'shilgan, lekin botga hali kirmaganlar — shu sahifaning o'zida (aks holda «ro'yxatda yo'q» bo'lib ko'rinadi). */
    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        $all = \App\Models\District::count();
        $names = \App\Models\District::pluck('name', 'id');
        $invites = \App\Models\StaffInvite::whereNull('used_at')->where('role', Staff::ROLE_CADASTRE)->orderBy('full_name')->get()
            ->map(fn ($i) => [
                'name' => $i->full_name, 'phone' => $i->phone, 'at' => $i->created_at?->format('d.m.Y'),
                'districts' => count($i->district_ids ?? []) >= $all && $all > 0 ? 'Butun shahar ('.$all.' tuman)'
                    : collect($i->district_ids ?? [])->map(fn ($id) => $names[$id] ?? null)->filter()->join(', '),
            ]);

        return view('filament.cadastre-invites', ['invites' => $invites, 'url' => \App\Filament\Resources\StaffInviteResource::getUrl()]);
    }

    public function table(Table $table): Table
    {
        $added = fn (Staff $s, ?string $approval) => Site::withoutGlobalScope('approved')->where('added_by_staff_id', $s->id)
            ->when($approval, fn ($q) => $q->where('approval', $approval), fn ($q) => $q->whereNull('approval'))->count();

        $table = parent::table($table);
        // kadastrga soatlik qayd yo'q — ustun shovqin
        $table->getColumn('hourly_checkin')?->hidden();

        return $table
            ->heading('Botga kirganlar')
            ->modifyQueryUsing(fn ($query) => $query->where('role', Staff::ROLE_CADASTRE))
            ->pushColumns([
                Tables\Columns\TextColumn::make('cad_sites')->label('Qo‘shgan obyektlar')
                    ->state(fn (Staff $record) => $added($record, null).' tasdiqlangan')
                    ->description(fn (Staff $record) => collect([
                        ($n = $added($record, Site::APPROVAL_PENDING)) ? "{$n} kutilmoqda" : null,
                        ($n = $added($record, Site::APPROVAL_REJECTED)) ? "{$n} rad etilgan" : null,
                    ])->filter()->join(' · ') ?: null)
                    ->url(fn () => \App\Filament\Resources\SiteResource::getUrl('index', ['tableFilters' => ['approval' => ['value' => Site::APPROVAL_PENDING]]])),
            ]);
    }
}
