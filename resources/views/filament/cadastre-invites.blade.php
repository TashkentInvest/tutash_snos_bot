@if ($invites->isNotEmpty())
    <x-filament::section :heading="'Botga hali kirmaganlar — '.$invites->count().' ta'"
        description="Qo‘shilgan, lekin hali botda /start bosmagan. Telefon raqamini yuborganda avtomatik kadastr xodimi bo‘lib, yuqoridagi ro‘yxatga o‘tadi.">
        <x-slot name="headerEnd">
            <x-filament::link :href="$url" icon="heroicon-o-clock">Kutilayotganlar (o‘chirish, tahrirlash)</x-filament::link>
        </x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;opacity:.65;">
                        <th style="padding:8px 16px 8px 0;font-weight:500;">F.I.SH</th>
                        <th style="padding:8px 16px 8px 0;font-weight:500;">Telefon</th>
                        <th style="padding:8px 16px 8px 0;font-weight:500;">Tumanlar</th>
                        <th style="padding:8px 0;font-weight:500;">Qo‘shilgan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invites as $i)
                        <tr style="border-top:1px solid rgba(127,127,127,.25);">
                            <td style="padding:8px 16px 8px 0;">{{ $i['name'] }}</td>
                            <td style="padding:8px 16px 8px 0;white-space:nowrap;">{{ $i['phone'] }}</td>
                            <td style="padding:8px 16px 8px 0;">{{ $i['districts'] ?: '—' }}</td>
                            <td style="padding:8px 0;opacity:.65;">{{ $i['at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif
