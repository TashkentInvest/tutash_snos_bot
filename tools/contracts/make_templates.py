"""Namunalardan (RONI1 — yuridik, Kadirov — jismoniy) {{kalit}} li shablon yasaydi.
Har almashtirish: (paragraf raqami, eski matn, yangi matn). Matn bir nechta run'ga bo'lingan bo'lsa ham topiladi;
yangi matn boshlanish run'iga yoziladi, qolgan bo'laklar o'chiriladi. Sariq bo'yoq hamma joyda olib tashlanadi.
Oylar jadvali: 1-qator {{m1}}..{{m4}}, 2-qator {{v1}}..{{v4}}; qolgan oy qatorlari o'chiriladi (ish vaqtida ko'paytiriladi)."""
import re, sys, zipfile, shutil, io
sys.stdout.reconfigure(encoding='utf-8')

def esc(s): return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

def replace_in_par(p, old, new):
    runs = list(re.finditer(r'<w:r[ >].*?</w:r>', p, re.S))
    texts = []
    for m in runs:
        ts = list(re.finditer(r'(<w:t(?: [^>]*)?>)(.*?)(</w:t>)', m.group(0), re.S))
        texts.append(''.join(t.group(2) for t in ts))
    full = ''.join(texts)
    i = full.replace(' ', ' ').find(esc(old).replace(' ', ' '))
    if i < 0:
        raise SystemExit(f'TOPILMADI: {old!r} in {full[:120]!r}')
    j = i + len(esc(old))
    out, pos, last = [], 0, 0
    for k, m in enumerate(runs):
        a, b = pos, pos + len(texts[k]); pos = b
        run = m.group(0)
        if b <= i or a >= j:
            continue
        keep_l = texts[k][: max(0, i - a)] if a < i else ''
        keep_r = texts[k][j - a:] if b > j else ''
        newtxt = keep_l + (esc(new) if a <= i < b else '') + keep_r
        # run ichidagi barcha w:t larni bitta matnga almashtiramiz
        ts = list(re.finditer(r'<w:t(?: [^>]*)?>.*?</w:t>', run, re.S))
        if not ts:
            continue
        first = ts[0]
        newrun = run[:first.start()] + '<w:t xml:space="preserve">' + newtxt + '</w:t>' + run[first.end():]
        for t in ts[1:]:
            newrun = newrun.replace(t.group(0), '', 1)
        out.append((m.start(), m.end(), newrun))
    for s, e, r in reversed(out):
        p = p[:s] + r + p[e:]
    return p

def build(src, dst, reps, months):
    z = zipfile.ZipFile(src)
    x = z.read('word/document.xml').decode('utf-8')
    paras = list(re.finditer(r'<w:p[ >].*?</w:p>', x, re.S))
    edits = {}
    for idx, old, new in reps:
        p = edits.get(idx, paras[idx].group(0))
        edits[idx] = replace_in_par(p, old, new)
    # oylar: birinchi 4 oy nomi va qiymati -> {{m1..4}} / {{v1..4}}
    for n, idx in enumerate(months['names'][:4], 1):
        edits[idx] = replace_in_par(edits.get(idx, paras[idx].group(0)), months['name_text'][n - 1], '{{m%d}}' % n)
    for n, idx in enumerate(months['values'][:4], 1):
        p = edits.get(idx, paras[idx].group(0))
        if months['value_text']:
            p = replace_in_par(p, months['value_text'], '{{v%d}}' % n)
        else:  # bo'sh katak — paragrafga run qo'shamiz
            p = re.sub(r'(</w:pPr>)', r'\1<w:r><w:t xml:space="preserve">{{v%d}}</w:t></w:r>' % n, p, 1) if '</w:pPr>' in p \
                else p.replace('</w:p>', '<w:r><w:t xml:space="preserve">{{v%d}}</w:t></w:r></w:p>' % n)
        edits[idx] = p
    for idx in sorted(edits, reverse=True):
        m = paras[idx]
        x = x[:m.start()] + edits[idx] + x[m.end():]
    # 3-4 oy qatorlari (5-oydan keyingilari) — o'chiramiz: {{m1}} li qator va keyingi qiymat qatoridan keyingi 2 qator
    rows = list(re.finditer(r'<w:tr[ >].*?</w:tr>', x, re.S))
    ri = next(k for k, r in enumerate(rows) if '{{m1}}' in r.group(0))
    extra = rows[ri + 2: ri + 4]
    for r in reversed(extra):
        x = x[:r.start()] + x[r.end():]
    x = re.sub(r'<w:highlight w:val="yellow"/>', '', x)
    keys = sorted(set(re.findall(r'\{\{(\w+)\}\}', x)))
    print(dst, 'kalitlar:', keys)
    tmp = dst + '.tmp'
    with zipfile.ZipFile(src) as zin, zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == 'word/document.xml':
                data = x.encode('utf-8')
            zout.writestr(item, data)
    shutil.move(tmp, dst)

OUT = sys.argv[1]
legal = [
    (11, '“GLOBAL PROPERTY DEVELOPMENT” MÇJ', '{{tenant_name}}'),
    (16, 'Toşkent şahri, Mirobod tumani, Oybek MFY,', 'Toşkent şahri, {{district}}, {{mfy}},'),
    (17, 'Taras Şevçenko ko‘çasi 21A-uy,', '{{street_house}},'),
    (44, '202__ yil ____ da kelib tuşgan ___-son', '{{application}}'),
    (47, 'Toşkent şahri, Mirobod tumani, Taras Şevçenko ko‘çasi, 21A-uyda joylaşgan “GLOBAL PROPERTY DEVELOPMENT” MÇJ', '{{tenant_address}}da joylaşgan {{tenant_name}}'),
    (47, 'V.R.Yermatov', '{{signature}}'),
    (53, "Mirobod tumani, Oybek MFY, Taras Şevçenko ko'çasi 21A-uyda", '{{district}}, {{mfy}}, {{street_house}}da'),
    (53, '10:11:40:02:01:0612', '{{cadastre}}'),
    (53, '58,50', '{{area}}'),
    (54, 'Umumiy ovqatlaniş', '{{activity}}'),
    (67, 'Umumiy ovqatlaniş', '{{activity}}'),
    (71, '20_____ yil “___” _________ dan 20_____ yil “___” _________', '{{period}}'),
    (79, '21 787 682,67', '{{amount}}'),
    (183, '“GLOBAL PROPERTY DEVELOPMENT” MÇJ', '{{tenant_name}}'),
    (193, 'Toşkent şahri, Mirobod tumani, Taras Şevçenko ko‘çasi, 21A-uy', '{{tenant_address}}'),
    (194, '20208000005495269001', '{{account}}'),
    (195, '309303138', '{{inn}}'),
    (195, '00445', '{{mfo}}'),
    (196, '+998997607007', '{{phone}}'),
    (200, 'V.R.Yermatov', '{{signature}}'),
]
build('D:/TG/RONI1.docx', OUT + '/lease-legal.docx', legal,
      {'names': [85, 86, 87, 88], 'values': [89, 90, 91, 92], 'name_text': ['Iyul', 'Avgust', 'Sentyabr', 'Oktyabr'], 'value_text': '3 631 280,44'})

ind = [
    (11, 'KADIROV ABDURAXMON ABDUGAFUROVIÇ', '{{tenant_name}}'),
    (17, 'Toşkent şahri, Şayxontohur tumani, Yangi Kamolon MFY,', 'Toşkent şahri, {{district}}, {{mfy}},'),
    (18, "Bunyodkor ko'çasi 4A-uy,", '{{street_house}},'),
    (44, '202__ yil ____ da kelib tuşgan ___-son', '{{application}}'),
    (46, '___________________________________', '{{tenant_address}}'),
    (46, 'KADIROV ABDURAXMON ABDUGAFUROVIÇ', '{{tenant_name}}'),
    (51, "Şayxontohur tumani, Yangi Kamolon MFY, Bunyodkor ko'çasi 4A-uyda", '{{district}}, {{mfy}}, {{street_house}}da'),
    (51, '10:10:03:03:02:0035', '{{cadastre}}'),
    (51, 'hududdagi 20 kv.m', 'hududdagi {{area}} kv.m'),
    (52, 'Savdo', '{{activity}}'),
    (65, 'Savdo', '{{activity}}'),
    (69, '20_____ yil “___” _________ dan 20_____ yil “___” _________', '{{period}}'),
    (77, '31 657 316,7', '{{amount}}'),
    (182, 'KADIROV ABDURAXMON ABDUGAFUROVIÇ _________________________', '{{tenant_name}}'),
    (192, 'Pasport maʻlumoti: ___________', 'Pasport maʻlumoti: {{passport_issuer}}'),
    (193, 'tomonidan _________-yil berilgan', 'tomonidan {{passport_year}}-yil berilgan'),
    (194, 'AD 7739566', '{{passport}}'),
    (194, '31912820020026', '{{jshshir}}'),
    (195, '998042075', '{{phone}}'),
    (199, 'A.A.Abdugafuroviç', '{{signature}}'),
]
build('D:/TG/Kadirov Abduraxmon Abdugafurovich.docx', OUT + '/lease-individual.docx', ind,
      {'names': [83, 84, 85, 86], 'values': [87, 88, 89, 90], 'name_text': ['Avgust', 'Sentyabr', 'Oktyabr', 'Noyabr'], 'value_text': None})
