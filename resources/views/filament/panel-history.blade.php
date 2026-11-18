@php
    $record = $getRecord();
    $rows = \App\Observers\PanelAuditObserver::history($record);
    $actions = ['created' => 'yaratdi', 'updated' => 'tahrirladi', 'deleted' => 'o‘chirdi', 'restored' => 'tikladi'];
@endphp
@if ($rows->isEmpty())
    <div style="font-size:13px;opacity:.6;">Admin panelda hali o‘zgartirilmagan.</div>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr style="text-align:left;opacity:.6;">
                <th style="padding:6px 12px 6px 0;font-weight:500;white-space:nowrap;">Qachon</th>
                <th style="padding:6px 12px 6px 0;font-weight:500;">Kim</th>
                <th style="padding:6px 12px 6px 0;font-weight:500;">Amal</th>
                <th style="padding:6px 0;font-weight:500;">O‘zgarishlar (eski → yangi)</th>
            </tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr style="border-top:1px solid rgba(127,127,127,.2);vertical-align:top;">
                        <td style="padding:6px 12px 6px 0;white-space:nowrap;">{{ $r->created_at?->format('d.m.Y H:i') }}</td>
                        <td style="padding:6px 12px 6px 0;">{{ $r->context['user'] ?? '—' }}</td>
                        <td style="padding:6px 12px 6px 0;white-space:nowrap;">{{ $actions[\Illuminate\Support\Str::afterLast($r->event, '.')] ?? $r->event }}</td>
                        <td style="padding:6px 0;">
                            @foreach ($r->context['changes'] ?? [] as $field => [$old, $new])
                                <div><b>{{ $field }}</b>: <span style="opacity:.65;">{{ \Illuminate\Support\Str::limit((string) ($old ?? '—'), 80) }}</span> → {{ \Illuminate\Support\Str::limit((string) ($new ?? '—'), 80) }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
