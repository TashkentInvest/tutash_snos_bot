<x-filament-panels::page>
    @php($cell = 'border:1px solid rgba(128,128,128,.25);padding:.45rem .4rem;text-align:center;vertical-align:middle;')
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;">
        <label style="font-size:.875rem;font-weight:600;">Kun</label>
        <input type="date" wire:model.live="day" max="{{ today()->toDateString() }}"
               style="border:1px solid rgba(128,128,128,.4);border-radius:.5rem;padding:.35rem .6rem;background:transparent;color:inherit;">
        <select wire:model.live="who"
                style="border:1px solid rgba(128,128,128,.4);border-radius:.5rem;padding:.35rem 2rem .35rem .6rem;background:transparent;color:inherit;">
            <option value="all">Hammasi</option>
            <option value="contract">Shartnoma xodimlari</option>
            <option value="employee">Xodimlar (soatlik qayd qo‘shilgan)</option>
        </select>
        <span style="font-size:.8rem;opacity:.7;">
            Soatlik so‘rov: {{ implode(', ', $slots) }} · javob muddati — keyingi soatgacha · 13:00–14:00 tushlik
        </span>
    </div>

    <x-filament::section>
        @if ($rows->isEmpty())
            <div style="font-size:.875rem;">Soatlik qayd yuritiladigan xodim yo‘q. Xodimlar sahifasida rolni «Shartnoma» qiling yoki xodimga «Soatlik qayd» belgisini yoqing.</div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                    <thead>
                        <tr style="background:rgba(112,173,71,.18);">
                            <th style="{{ $cell }}text-align:left;font-weight:600;">Xodim</th>
                            <th style="{{ $cell }}font-weight:600;">Davomat<br><span style="font-weight:400;opacity:.7;">08:00–09:00</span></th>
                            @foreach ($slots as $slot)
                                <th style="{{ $cell }}font-weight:600;">{{ $slot }}</th>
                            @endforeach
                            <th style="{{ $cell }}font-weight:600;">Qayd</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($a = $row['attendance'])
                            <tr>
                                <td style="{{ $cell }}text-align:left;white-space:nowrap;">
                                    {{ $row['staff']->full_name }}
                                    <div style="opacity:.6;">{{ $row['staff']->phone }} · {{ $row['staff']->isContract() ? 'Shartnoma' : 'Xodim' }}</div>
                                </td>
                                <td style="{{ $cell }}">
                                    @if (! $a)
                                        <span style="opacity:.5;">—</span>
                                    @elseif ($a->status === \App\Models\Attendance::PRESENT)
                                        <span style="color:rgb(22,163,74);font-weight:600;">{{ $a->responded_at?->format('H:i') }}</span>
                                        @if ($a->lat)<div><a href="{{ \App\Support\Maps::url($a->lat, $a->lng) }}" target="_blank" style="color:rgb(37,99,235);">xarita</a></div>@endif
                                    @elseif ($a->status === \App\Models\Attendance::ABSENT)
                                        <span style="color:rgb(217,119,6);" title="{{ $a->reason }}">Sabab bilan yo‘q</span>
                                    @elseif ($a->status === \App\Models\Attendance::MISSED)
                                        <span style="color:rgb(220,38,38);">Javob bermagan</span>
                                    @else
                                        <span style="opacity:.6;">Kutilmoqda</span>
                                    @endif
                                </td>
                                @foreach ($slots as $slot)
                                    @php($c = $row['cells'][$slot] ?? null)
                                    <td style="{{ $cell }}">
                                        @if (! $c)
                                            <span style="opacity:.35;">·</span>
                                        @elseif ($c->status === \App\Models\Checkin::PRESENT)
                                            <span style="color:rgb(22,163,74);font-weight:600;">{{ $c->responded_at?->format('H:i') }}</span>
                                            <div style="white-space:nowrap;">
                                                @if ($c->lat)<a href="{{ \App\Support\Maps::url($c->lat, $c->lng) }}" target="_blank" style="color:rgb(37,99,235);">xarita</a>@endif
                                                @if ($c->video_path) · <a href="{{ route('admin.checkin-video', ['checkin' => $c]) }}" target="_blank" style="color:rgb(37,99,235);">video</a>@endif
                                            </div>
                                            @if (! empty($c->flags))<div style="color:rgb(217,119,6);" title="{{ collect($c->flags)->map(fn ($f) => \App\Support\Gps::flagLabel($f))->join(', ') }}">belgi</div>@endif
                                        @elseif ($c->status === \App\Models\Checkin::MISSED)
                                            <span style="color:rgb(220,38,38);">yo‘q</span>
                                        @else
                                            <span style="opacity:.6;">kutilmoqda</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td style="{{ $cell }}font-weight:600;">{{ $row['present'] }} / {{ count($slots) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
