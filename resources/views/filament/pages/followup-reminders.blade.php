@php($cell = 'border-bottom:1px solid rgba(128,128,128,.2);padding:.5rem .4rem;vertical-align:top;text-align:left;')
@if ($items->isEmpty())
    <div style="font-size:.875rem;">Ochiq izoh yo‘q.</div>
@else
    <div style="font-size:.8rem;opacity:.75;margin-bottom:.5rem;">
        Har bir xodimga botda izoh va «Bajardingizmi?» savoli tugmalar bilan boradi ({{ $items->count() }} ta).
    </div>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
            <thead>
                <tr>
                    <th style="{{ $cell }}font-weight:600;">№</th>
                    <th style="{{ $cell }}font-weight:600;">Xodim</th>
                    <th style="{{ $cell }}font-weight:600;">Obyekt</th>
                    <th style="{{ $cell }}font-weight:600;">Moderator izohi</th>
                    <th style="{{ $cell }}font-weight:600;">Holat</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $in)
                    <tr>
                        <td style="{{ $cell }}">{{ $i + 1 }}</td>
                        <td style="{{ $cell }}white-space:nowrap;">{{ $in->staff?->full_name ?? '—' }}</td>
                        <td style="{{ $cell }}">
                            {{ \Illuminate\Support\Str::limit($in->site?->address ?? '—', 40) }}
                            <div style="opacity:.6;">{{ $in->reviewed_at?->format('d.m.Y') }}</div>
                        </td>
                        <td style="{{ $cell }}">{{ $in->review_note }}</td>
                        <td style="{{ $cell }}white-space:nowrap;">
                            {{ $in->review_status === \App\Models\Inspection::REVIEW_REJECTED ? 'Rad etilgan — tuzatilmagan' : \App\Support\FollowupReminder::label($in) }}
                            @if ($in->followup_reminders)
                                <div style="opacity:.6;">Eslatma: {{ $in->followup_reminders }} marta</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
