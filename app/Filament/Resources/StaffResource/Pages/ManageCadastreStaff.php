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
        return 'Har tuman uchun kadastr xodimlari: botdan o‘z tumaniga yangi obyekt qo‘shadi, siz «Obyektlar» bo‘limida tasdiqlaysiz.';
    }

    public function table(Table $table): Table
    {
        $added = fn (Staff $s, ?string $approval) => Site::withoutGlobalScope('approved')->where('added_by_staff_id', $s->id)
            ->when($approval, fn ($q) => $q->where('approval', $approval), fn ($q) => $q->whereNull('approval'))->count();

        return parent::table($table)
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
