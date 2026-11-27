<!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<title>Kunlik hisobot — {{ $day->format('d.m.Y') }}</title>
<style>
    @page { margin: 40px 42px 46px; }
    * { font-family: 'DejaVu Serif', serif; }
    body { font-size: 10.5px; color: #1e293b; }
    .muted { color: #64748b; }
    .head { width: 100%; border-collapse: collapse; border-bottom: 1.5px solid #1e3a6e; margin-bottom: 18px; }
    .head td { padding: 0 0 10px; vertical-align: middle; }
    .org { font-weight: bold; font-size: 12px; line-height: 1.3; }
    .sys { background: #1e3a6e; color: #fff; text-align: center; padding: 7px 14px; font-weight: bold; font-size: 11px; }
    .sys span { display: block; font-weight: normal; font-size: 9.5px; margin-top: 2px; }
    h1 { text-align: center; font-size: 18px; margin: 2px 0 6px; letter-spacing: .5px; }
    .sub { text-align: center; font-size: 12px; }
    .date { text-align: right; font-size: 12px; margin: 6px 0 14px; }
    .hello { font-size: 12px; font-weight: bold; margin: 0 0 4px; }
    p { font-size: 11.5px; margin: 2px 0; line-height: 1.45; }
    h2 { font-size: 13px; margin: 18px 0 7px; }
    h2 small { font-weight: normal; color: #64748b; font-size: 10px; margin-left: 4px; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #eef2f7; color: #1e293b; font-weight: bold; font-size: 9.5px; padding: 7px 6px; border: 1px solid #d5dce6; text-align: center; }
    table.t td { padding: 6px; border: 1px solid #e1e6ee; font-size: 10px; text-align: center; vertical-align: middle; }
    table.t td.l, table.t th.l { text-align: left; }
    table.t tr.sum td { background: #eef2f7; font-weight: bold; }
    .n { color: #64748b; }
    .b { font-weight: bold; }
    .red { color: #b91c1c; font-weight: bold; }
    .green { color: #15803d; font-weight: bold; }
    .amber { color: #a16207; font-weight: bold; }
    .badge { display: inline-block; padding: 3px 6px; font-size: 9px; font-weight: bold; }
    .small { font-size: 8.5px; color: #64748b; }
    .foot { border-top: 1px solid #cbd5e1; margin-top: 26px; padding-top: 10px; width: 100%; border-collapse: collapse; }
    .foot td { vertical-align: top; }
    .page-break { page-break-before: always; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $pct = fn ($v) => str_replace('.', ',', (string) $v).'%';
    $t = $total;
@endphp

<table class="head">
    <tr>
        <td style="width:52px;">@if ($gerb)<img src="{{ $gerb }}" style="width:44px;height:44px;">@endif</td>
        <td class="org">Toshkent shahar<br>hokimligi</td>
        <td style="width:290px;"><div class="sys">“TUTASH HUDUD” AXBOROT TIZIMI<span>thudud-snos.toshkentinvest.uz</span></div></td>
    </tr>
</table>

<div class="sub">Tutash hududlardagi noqonuniy obyektlarni buzish bo‘yicha</div>
<h1>KUNLIK HISOBOT</h1>
<div class="date">{{ $day->format('d.m.Y') }} y.</div>

<div class="hello">Hurmatli {{ $name ? mb_strtoupper($name) : 'rahbar' }}!</div>
<p>Toshkent shahri bo‘yicha tutash hududlardagi noqonuniy obyektlar holati (sud qarori bekor qilinganlari jamiga kirmaydi).</p>
<p>Hisobot davri: {{ $period->label() }}. Holat: {{ $now->format('d.m.Y') }}, soat {{ $now->format('H:i') }} (Toshkent vaqti).</p>

<h2>Tumanlar kesimida</h2>
<table class="t">
    <thead>
        <tr>
            <th style="width:22px;">№</th><th class="l">Tuman</th><th>Jami</th><th>Buzilgan</th><th>Sud qarori<br>bekor</th>
            <th>Qolgan</th><th>Shundan<br>jarayonda</th><th>Davrda<br>tekshirildi</th><th>Davrda<br>buzildi</th><th>Bajarilish</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $i => $r)
            <tr>
                <td class="n">{{ $i + 1 }}</td>
                <td class="l">{{ $r['name'] }}</td>
                <td class="b">{{ $n($r['p']['all']) }}</td>
                <td class="green">{{ $n($r['p']['demolished']) }}</td>
                <td>{{ $n($r['court']) }}</td>
                <td class="{{ $r['left'] ? 'red' : 'n' }}">{{ $n($r['left']) }}</td>
                <td class="{{ $r['progress'] ? 'amber' : 'n' }}">{{ $n($r['progress']) }}</td>
                <td>{{ $n($r['checked']) }}</td>
                <td class="{{ $r['demolished_now'] ? 'green' : 'n' }}">{{ $n($r['demolished_now']) }}</td>
                <td class="b">{{ $pct($r['p']['pct']) }}</td>
            </tr>
        @endforeach
        <tr class="sum">
            <td></td><td class="l">Jami</td><td>{{ $n($t['p']['all']) }}</td><td class="green">{{ $n($t['p']['demolished']) }}</td>
            <td>{{ $n($t['court']) }}</td><td class="red">{{ $n($t['left']) }}</td><td class="amber">{{ $n($t['progress']) }}</td>
            <td>{{ $n($t['checked']) }}</td><td class="green">{{ $n($t['demolished_now']) }}</td><td>{{ $pct($t['p']['pct']) }}</td>
        </tr>
    </tbody>
</table>
<p class="small" style="margin-top:6px;">Bajarilish — buzilgan va sud qarori bekor qilingan obyektlar ulushi. «Davrda» — hisobot davridagi tekshiruvlar.</p>

@php($withData = $rows->filter(fn ($r) => $r['checked'] > 0)->values())
@if ($withData->isNotEmpty())
    <div class="page-break"></div>
    @foreach ($withData as $k => $r)
        <h2>{{ $k + 1 }}. {{ $r['name'] }} <small>{{ $r['checked'] }} ta tekshiruv</small></h2>
        <table class="t" style="table-layout:fixed;">
            <thead>
                <tr>
                    <th style="width:5%;">№</th><th class="l" style="width:30%;">Obyekt manzili</th><th class="l" style="width:20%;">Huquqbuzar</th>
                    <th style="width:13%;">Tekshirilgan<br>vaqti</th><th class="l" style="width:18%;">Xodim</th><th style="width:14%;">Natija</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['inspections'] as $j => $in)
                    @php($b = $badge($in))
                    <tr>
                        <td class="n">{{ $j + 1 }}</td>
                        <td class="l"><span class="b">{{ $in->site->address }}</span>@if ($in->site->mahalla)<br><span class="small">{{ $in->site->mahalla->name }}</span>@endif</td>
                        <td class="l">{{ $in->site->violator_name ?: '—' }}</td>
                        <td>{{ $in->submitted_at?->format('d.m.Y') }}<br><span class="small">{{ $in->submitted_at?->format('H:i') }}</span></td>
                        <td class="l">{{ $in->staff?->full_name ?: '—' }}</td>
                        <td><span class="badge" style="background:{{ $b[1] }};color:{{ $b[2] }};">{{ $b[0] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin-top:5px;"><b>Jami:</b> {{ $r['checked'] }} ta tekshiruv, shundan buzilgan: <span class="green">{{ $r['demolished_now'] }} ta</span>; tumanda qolgan obyektlar: <span class="red">{{ $r['left'] }} ta</span>.</p>
    @endforeach
@else
    <p class="muted" style="margin-top:14px;">Hisobot davrida tekshiruv o‘tkazilmagan.</p>
@endif

<table class="foot">
    <tr>
        <td>
            <div style="font-size:13px;font-weight:bold;margin-bottom:4px;">“Tutash hudud” axborot tizimi</div>
            <div class="small" style="font-size:9.5px;">Ma’lumotlar tizim orqali avtomatik shakllantirildi ({{ $now->format('d.m.Y H:i') }}).<br>QR-kod — tizimning admin paneli.</div>
        </td>
        <td style="width:80px;text-align:right;"><img src="{{ $qr }}" style="width:72px;height:72px;"></td>
    </tr>
</table>
</body>
</html>
