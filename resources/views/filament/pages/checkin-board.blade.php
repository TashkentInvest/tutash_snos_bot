<x-filament-panels::page>
    @php($cell = 'border:1px solid rgba(128,128,128,.25);padding:.45rem .4rem;text-align:center;vertical-align:middle;')
    <div class="fi-section" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;padding:.75rem 1rem;">
        {{-- kunlik jadval / oylik statistika --}}
        <x-filament::tabs>
            <x-filament::tabs.item :active="$mode === 'day'" wire:click="$set('mode', 'day')" icon="heroicon-o-calendar">Kunlik</x-filament::tabs.item>
            <x-filament::tabs.item :active="$mode === 'month'" wire:click="$set('mode', 'month')" icon="heroicon-o-chart-bar">Oylik statistika</x-filament::tabs.item>
        </x-filament::tabs>
        @if ($mode === 'month')
            <label style="font-size:.875rem;font-weight:600;">Oy</label>
            <x-filament::input.wrapper>
                <x-filament::input type="month" wire:model.live="month" max="{{ today()->format('Y-m') }}" style="color-scheme:light dark;" />
            </x-filament::input.wrapper>
        @else
        <label style="font-size:.875rem;font-weight:600;">Kun</label>
        {{-- Filament'ning o'z maydonlari — qorong'i rejimda ham variantlar va kalendar o'qiladi --}}
        <x-filament::input.wrapper>
            <x-filament::input type="date" wire:model.live="day" max="{{ today()->toDateString() }}" style="color-scheme:light dark;" />
        </x-filament::input.wrapper>
        @endif
        <x-filament::input.wrapper style="min-width:260px;">
            <x-filament::input.select wire:model.live="who">
                <option value="all">Hammasi</option>
                <option value="contract">Shartnoma xodimlari</option>
                <option value="employee">Xodimlar (soatlik qayd qo‘shilgan)</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <span style="font-size:.8rem;opacity:.7;">
            Soatlik so‘rov: {{ implode(', ', $slots) }} · javob muddati — keyingi soatgacha
        </span>
    </div>

    @if ($mode === 'month')
        @include('filament.pages.checkin-board-month', ['m' => $m, 'slots' => $slots, 'cell' => $cell])
    @else
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
                                        <span style="color:rgb(22,163,74);font-weight:600;" title="{{ $a->comment }}">{{ $a->responded_at?->format('H:i') }}</span>
                                        @if ($a->comment)<div style="font-size:.75rem;opacity:.75;max-width:12rem;margin:auto;" title="{{ $a->comment }}">{{ \Illuminate\Support\Str::limit($a->comment, 40) }}</div>@endif
                                        <div style="white-space:nowrap;">
                                            @if ($a->lat)<a href="{{ \App\Support\Maps::url($a->lat, $a->lng) }}" target="_blank" class="text-primary-600 dark:text-primary-400">xarita</a>@endif
                                            @if ($a->video_path) · <a href="{{ route('admin.attendance-video', ['attendance' => $a, 'v' => $a->updated_at?->timestamp]) }}" target="_blank" class="text-primary-600 dark:text-primary-400">video</a>@endif
                                            @if ($a->face_video_path) · <a href="{{ route('admin.attendance-video', ['attendance' => $a, 'face' => 1]) }}" target="_blank" class="text-primary-600 dark:text-primary-400">old kamera</a>@endif
                                        </div>
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
                                            <span style="opacity:.35;">—</span>
                                        @elseif ($c->status === \App\Models\Checkin::PRESENT)
                                            <span style="color:rgb(22,163,74);font-weight:600;" title="{{ $c->comment }}">{{ $c->responded_at?->format('H:i') }}</span>
                                            @if ($c->comment)<div style="font-size:.75rem;opacity:.75;max-width:9rem;margin:auto;" title="{{ $c->comment }}">{{ \Illuminate\Support\Str::limit($c->comment, 30) }}</div>@endif
                                            <div style="white-space:nowrap;">
                                                @if ($c->lat)<a href="{{ \App\Support\Maps::url($c->lat, $c->lng) }}" target="_blank" class="text-primary-600 dark:text-primary-400">xarita</a>@endif
                                                @if ($c->video_path) · <a href="{{ route('admin.checkin-video', ['checkin' => $c, 'v' => $c->updated_at?->timestamp]) }}" target="_blank" class="text-primary-600 dark:text-primary-400">video</a>@endif
                                                {{-- birlashtirilmagan bo'lsa (hali navbatda yoki xato) — old kamera lavhasi alohida --}}
                                                @if ($c->face_video_path) · <a href="{{ route('admin.checkin-video', ['checkin' => $c, 'face' => 1]) }}" target="_blank" class="text-primary-600 dark:text-primary-400">old kamera</a>@endif
                                            </div>
                                            @if (! empty($c->flags))<div style="color:rgb(217,119,6);" title="{{ collect($c->flags)->map(fn ($f) => \App\Support\Gps::flagLabel($f))->join(', ') }}">ogohlantirish</div>@endif
                                        @elseif ($c->status === \App\Models\Checkin::MISSED)
                                            <span style="color:rgb(220,38,38);">javob bermagan</span>
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
    @endif
</x-filament-panels::page>
