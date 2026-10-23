<!DOCTYPE html>
{{--
  Spec 86 — Öffentliches Handbuch (Standards zum Nachschlagen). Eigenständige Seite ohne Login,
  rendert NUR aus dem eingefrorenen Snapshot. Mischung aus Editor und Küchenmonitor: Editor-Größe
  (13 px), Wandmonitor-Palette (dunkelblau, Segment-Reiter, Kennzahl-Chips), umschaltbar auf hell.
  Tablet-first, Handy einspaltig. Kein Build-Schritt: CSS + wenige Zeilen JS inline.
--}}
@php
    $gerichte = $snapshot['gerichte'] ?? [];
    $basis = $snapshot['basisrezepte'] ?? [];
    $legende = $snapshot['legende'] ?? null;
    $personen = (int) ($snapshot['personen'] ?? 0) ?: 1;
    $codeLabel = [];
    foreach (['allergene', 'zusatzstoffe'] as $gruppe) {
        foreach (($legende[$gruppe] ?? []) as $e) {
            $codeLabel[$e['code']] = $e['label'];
        }
    }
    $nKapitel = count($snapshot['sections'] ?? []);
    $nSpeisen = count($gerichte);
    $fmtMenge = fn ($v) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, $v < 10 ? 3 : 1, ',', ''), '0'), ',');
@endphp
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $snapshot['title'] ?? 'Handbuch' }} · Handbuch</title>
    <style>
        :root { --onac:#fff; --ground:#f3f5f8; --surface:#fdfdfe; --line:#dde2ea; --line2:#c5ccd8; --ink:#131a26; --ink2:#4a5466; --ink3:#657084; --ac:#0a3dd6; --acs:#e6edfe; --warn:#fff4d6; --warnt:#7a5300; --r:6px; --rs:10px; }
        body.dunkel { --ground:#141d36; --surface:#1b2744; --line:#2d3b5f; --line2:#40507a; --ink:#eef2fb; --ink2:#b9c3da; --ink3:#8f9bb8; --ac:#8fb0ff; --acs:#243a6e; --onac:#0b1b33; --warn:#4a3a14; --warnt:#f5d58a; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body { background: var(--ground); color: var(--ink); font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-text-size-adjust: 100%; }
        .app { display: grid; grid-template-rows: auto minmax(0, 1fr); height: 100%; }
        .top { display: flex; align-items: center; gap: 10px; padding: 8px 14px; background: var(--surface); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
        .top .kopf { min-width: 0; }
        .top .klein { font-size: 11px; color: var(--ink3); }
        .top h1 { margin: 0; font-size: 16px; font-weight: 600; }
        .kpi { background: var(--ground); border: 1px solid var(--line); border-radius: var(--rs); padding: 3px 10px; color: var(--ink2); font-size: 12px; white-space: nowrap; }
        .kpi b { color: var(--ink); font-size: 14px; margin-right: 2px; }
        .sp { flex: 1; }
        .logo { font-weight: 600; font-size: 14px; } .logo span { color: var(--ac); }
        .pill { border: 1px solid var(--line2); background: var(--surface); color: var(--ink); border-radius: var(--r); padding: 4px 10px; font: inherit; cursor: pointer; min-height: 30px; }
        .stepper { display: inline-flex; align-items: center; gap: 6px; color: var(--ink3); }
        .stepper button { width: 30px; height: 30px; border: 1px solid var(--line2); background: var(--surface); color: var(--ink); border-radius: var(--r); cursor: pointer; font: inherit; }
        .stepper input { width: 56px; height: 30px; text-align: center; border: 1px solid var(--line2); background: var(--surface); color: var(--ink); border-radius: var(--r); font: inherit; }
        .body { display: grid; grid-template-columns: 250px minmax(0, 1fr); min-height: 0; }
        nav { overflow: auto; background: var(--surface); border-right: 1px solid var(--line); padding: 6px 0 24px; }
        .kap { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; font-weight: 600; cursor: pointer; }
        .kap .n { font-weight: 400; color: var(--ink3); font-size: 12px; }
        .kap-text { padding: 0 12px 4px; font-size: 12px; color: var(--ink3); }
        .liste { display: none; } .kapw.open .liste { display: block; }
        .liste a { display: block; padding: 8px 12px 8px 24px; color: var(--ink2); text-decoration: none; border-left: 3px solid transparent; }
        .liste a:hover { background: var(--ground); }
        .liste a.on { background: var(--acs); border-left-color: var(--ac); color: var(--ink); font-weight: 600; }
        main { overflow: auto; padding: 12px 16px 40px; }
        .back { display: none; margin: 0 0 8px; color: var(--ac); background: none; border: 0; font: inherit; cursor: pointer; padding: 6px 0; }
        .gericht { display: none; max-width: 920px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--rs); padding: 12px 14px; }
        .gericht.on { display: block; }
        .head { display: flex; gap: 12px; align-items: flex-start; }
        .foto { width: 92px; height: 68px; border-radius: var(--r); background: var(--ground); border: 1px solid var(--line); object-fit: cover; flex: none; }
        h2 { margin: 0 0 2px; font-size: 16px; }
        .meta { color: var(--ink3); }
        .chip { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 999px; background: var(--warn); color: var(--warnt); margin: 4px 4px 0 0; }
        .tabs { display: inline-flex; gap: 2px; background: var(--ground); border: 1px solid var(--line); border-radius: var(--rs); padding: 3px; margin: 10px 0 8px; max-width: 100%; overflow: auto; }
        .tabs { scrollbar-width: none; } .tabs::-webkit-scrollbar { display: none; }
        .tab { padding: 6px 12px; border: 0; background: none; color: var(--ink2); font: inherit; cursor: pointer; border-radius: var(--r); white-space: nowrap; }
        .tab.on { background: var(--ac); color: var(--onac); font-weight: 600; }
        .pane { display: none; } .pane.on { display: block; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 6px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { color: var(--ink3); font-weight: 500; font-size: 12px; }
        td.r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sub { color: var(--ac); cursor: pointer; background: none; border: 0; font: inherit; padding: 0; text-align: left; }
        .subbox { display: none; margin: 4px 0 6px 12px; padding: 8px 10px; background: var(--ground); border: 1px solid var(--line); border-radius: var(--r); }
        .subbox.open { display: block; }
        .subbox h4 { margin: 0 0 4px; font-size: 13px; }
        .step { display: flex; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--line); }
        .nr { flex: none; width: 22px; height: 22px; border-radius: 50%; background: var(--acs); color: var(--ac); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
        .fotos { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
        .fotos img { width: 84px; height: 60px; object-fit: cover; border-radius: var(--r); background: var(--ground); }
        .hint { color: var(--ink3); font-size: 12px; margin-top: 6px; }
        .leer { color: var(--ink3); padding: 12px 0; }
        .legende { max-width: 920px; margin-top: 14px; color: var(--ink3); font-size: 12px; }
        @media (max-width: 800px) {
            .body { grid-template-columns: 1fr; }
            .app.detail nav { display: none; }
            .app:not(.detail) main { display: none; }
            .back { display: inline-block; }
            .kpi, .logo { display: none; }
            .tabs { display: flex; }
            main { padding: 10px; }
            .gericht { padding: 10px 12px; }
            .foto { width: 72px; height: 54px; }
            .liste a, .kap { padding-top: 11px; padding-bottom: 11px; }
        }
        @media (pointer: coarse) { .tab { padding: 10px 12px; } }
    </style>
</head>
<body class="dunkel">
<div class="app" id="app">
    <div class="top">
        <div class="kopf">
            <div class="klein">Handbuch · {{ ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'angebot' => 'Angebot'][$snapshot['quelle'] ?? 'foodbook'] ?? '' }}@if(!empty($snapshot['subtitle'])) · {{ $snapshot['subtitle'] }}@endif</div>
            <h1>{{ $snapshot['title'] ?? 'Handbuch' }}</h1>
        </div>
        <span class="kpi"><b>{{ $nKapitel }}</b> Kapitel</span>
        <span class="kpi"><b>{{ $nSpeisen }}</b> Speisen</span>
        <span class="sp"></span>
        <span class="logo">Food<span>.Alchemist</span></span>
        <span class="sp"></span>
        <span class="stepper">Portionen <button type="button" id="m" aria-label="weniger">−</button><input id="px" inputmode="numeric" value="{{ $personen }}" aria-label="Portionen"><button type="button" id="p" aria-label="mehr">+</button></span>
        <button type="button" class="pill" id="dk" aria-label="Zwischen dunkel und hell umschalten">Dunkel / Hell</button>
    </div>

    <div class="body">
        <nav aria-label="Gerichte">
            @foreach($snapshot['sections'] ?? [] as $i => $sec)
                <div class="kapw {{ $i < 2 ? 'open' : '' }}">
                    <div class="kap"><span>{{ $sec['title'] !== '' ? $sec['title'] : 'Speisen' }}</span><span class="n">{{ count($sec['items']) }}</span></div>
                    @if(!empty($sec['text']))<div class="kap-text">{{ $sec['text'] }}</div>@endif
                    <div class="liste">
                        @foreach($sec['items'] as $it)
                            <a href="#{{ $it['key'] }}" data-key="{{ $it['key'] }}">{{ $it['label'] !== '' ? $it['label'] : ($gerichte[$it['key']]['name'] ?? '') }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <main>
            <button type="button" class="back" id="zurueck">‹ Alle Speisen</button>
            @foreach($gerichte as $key => $g)
                @php
                    $portion = $g['portion'] ?? [];
                    $teile = array_filter([
                        $portion['form'] ?? null,
                        ($portion['gramm'] ?? null) ? $fmtMenge($portion['gramm']) . ' g' : null,
                        $portion['geschirr'] ?? null,
                    ]);
                    $z = $g['zutaten'] ?? ['basis' => 'ansatz', 'zeilen' => []];
                    $skaliert = ($z['basis'] ?? '') === 'portion';
                @endphp
                <article class="gericht" id="g-{{ $key }}" data-key="{{ $key }}">
                    <div class="head">
                        @if(!empty($g['foto_url']))<img class="foto" src="{{ $g['foto_url'] }}" alt="" loading="lazy">@endif
                        <div>
                            <h2>{{ $g['name'] }}</h2>
                            @if($teile)<div class="meta">{{ implode(' · ', $teile) }}</div>@endif
                            @foreach(($g['codes'] ?? []) as $c)@php $cl = $codeLabel[rtrim($c, '*')] ?? null; @endphp<span class="chip">{{ $c }}@if($cl) · {{ $cl }}{{ str_ends_with($c, '*') ? ' (Spuren)' : '' }}@endif</span>@endforeach
                        </div>
                    </div>

                    <div class="tabs" role="tablist">
                        <button type="button" class="tab on" data-pane="zutaten">Zutaten</button>
                        <button type="button" class="tab" data-pane="zubereitung">Zubereitung</button>
                        <button type="button" class="tab" data-pane="anrichten">Anrichten</button>
                        <button type="button" class="tab" data-pane="regeneration">Regeneration</button>
                        <button type="button" class="tab" data-pane="bestandteile">Bestandteile</button>
                    </div>

                    <div class="pane on" data-pane="zutaten">
                        @if(!empty($z['zeilen']))
                            <table>
                                <tr><th>Zutat</th><th class="r">Menge</th></tr>
                                @foreach($z['zeilen'] as $zi)
                                    <tr>
                                        <td>
                                            @if(!empty($zi['sub']) && isset($basis[$zi['sub']]))
                                                <button type="button" class="sub" data-sub="{{ $key }}-{{ $zi['sub'] }}-{{ $loop->index }}">{{ $zi['name'] }}</button> <span class="meta">(Basisrezept)</span>
                                                <div class="subbox" id="sb-{{ $key }}-{{ $zi['sub'] }}-{{ $loop->index }}">
                                                    @include('foodalchemist::handbuch._basisrezept', ['b' => $basis[$zi['sub']], 'fmtMenge' => $fmtMenge, 'basis' => $basis])
                                                </div>
                                            @else
                                                {{ $zi['name'] }}
                                            @endif
                                        </td>
                                        <td class="r" @if($skaliert && $zi['menge'] !== null) data-b="{{ $zi['menge'] }}" data-u="{{ $zi['einheit'] }}" @endif>{{ $zi['menge'] !== null ? $fmtMenge($zi['menge']) . ' ' . $zi['einheit'] : '–' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                            <div class="hint">
                                @if($skaliert) Mengen für <b class="pxs">{{ $personen }}</b> Portionen · Basisrezepte antippen zeigt deren Zutaten und Schritte.
                                @else Mengen im Ansatz{{ !empty($z['ansatz_kg']) ? ' (' . $fmtMenge($z['ansatz_kg']) . ' kg)' : '' }}; für dieses Gericht ist kein Portionsgewicht hinterlegt.
                                @endif
                            </div>
                        @else
                            <div class="leer">Keine Zutaten hinterlegt.</div>
                        @endif
                    </div>

                    @foreach(['zubereitung' => 'Keine Zubereitung hinterlegt.', 'anrichten' => 'Kein Anrichten hinterlegt.'] as $pane => $leerText)
                        <div class="pane" data-pane="{{ $pane }}">
                            @forelse($g[$pane] as $s)
                                <div class="step">
                                    <div class="nr">{{ $s['nr'] }}</div>
                                    <div>
                                        <div>{{ $s['text'] }}</div>
                                        @if(!empty($s['fotos']))
                                            <div class="fotos">@foreach($s['fotos'] as $f)@if(!empty($f['url']))<img src="{{ $f['url'] }}" alt="{{ $f['caption'] ?? '' }}" loading="lazy">@endif @endforeach</div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="leer">{{ $leerText }}</div>
                            @endforelse
                        </div>
                    @endforeach

                    <div class="pane" data-pane="regeneration">
                        @if($g['regeneration'])
                            <table>
                                <tr><th>Komponente</th><th>Gerät</th><th class="r">°C</th><th class="r">min</th><th class="r">Kern</th></tr>
                                @foreach($g['regeneration'] as $r)
                                    <tr>
                                        <td>{{ $r['komponente'] }}@if(!empty($r['hinweis']))<div class="meta">{{ $r['hinweis'] }}</div>@endif</td>
                                        @if($r['kalt'])
                                            <td colspan="4">Kalt servieren</td>
                                        @else
                                            <td>{{ $r['geraet'] ?? '–' }}</td><td class="r">{{ $r['temp_c'] ?? '–' }}</td><td class="r">{{ $r['dauer_min'] ?? '–' }}</td><td class="r">{{ $r['kern_c'] ?? '–' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </table>
                        @else
                            <div class="leer">Keine Regeneration hinterlegt.</div>
                        @endif
                    </div>

                    <div class="pane" data-pane="bestandteile">
                        @forelse($g['bestandteile'] as $b)
                            <table><tr><td>{{ $b['name'] }}</td><td class="r">{{ $b['garnitur'] ? 'Garnitur' : '' }}</td></tr></table>
                        @empty
                            <div class="leer">Keine Bestandteile hinterlegt.</div>
                        @endforelse
                    </div>
                </article>
            @endforeach

            @if(!empty($codeLabel))
                <div class="legende">
                    @foreach($codeLabel as $code => $label)<span>{{ $code }} {{ $label }}</span>@if(!$loop->last) · @endif @endforeach
                    <div>* = Spuren möglich · ohne Angabe = nicht bewertet, nicht „frei“</div>
                </div>
            @endif
        </main>
    </div>
</div>

<script>
(function () {
    var app = document.getElementById('app'), body = document.body;
    var links = [].slice.call(document.querySelectorAll('nav .liste a'));
    var arts = [].slice.call(document.querySelectorAll('.gericht'));
    function show(key, mobileDetail) {
        arts.forEach(function (a) { a.classList.toggle('on', a.dataset.key === key); });
        links.forEach(function (l) {
            var an = l.dataset.key === key; l.classList.toggle('on', an);
            if (an) { l.closest('.kapw').classList.add('open'); }
        });
        app.classList.toggle('detail', !!key && mobileDetail);
        var m = document.querySelector('main'); if (m) { m.scrollTop = 0; }
    }
    function fromHash(first) {
        var key = location.hash.replace('#', '');
        if (!document.getElementById('g-' + key)) { key = first && links.length ? links[0].dataset.key : ''; }
        show(key, !!location.hash && key !== '');
    }
    window.addEventListener('hashchange', function () { fromHash(false); });
    document.getElementById('zurueck').addEventListener('click', function () { history.pushState('', '', location.pathname); show('', false); app.classList.remove('detail'); });
    [].forEach.call(document.querySelectorAll('.kap'), function (k) { k.addEventListener('click', function () { k.parentNode.classList.toggle('open'); }); });
    arts.forEach(function (a) {
        var tabs = [].slice.call(a.querySelectorAll('.tab')), panes = [].slice.call(a.children).filter(function (e) { return e.classList.contains('pane'); });
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
                panes.forEach(function (p) { p.classList.toggle('on', p.dataset.pane === t.dataset.pane); });
            });
        });
    });
    [].forEach.call(document.querySelectorAll('.sub'), function (b) {
        b.addEventListener('click', function () { var box = document.getElementById('sb-' + b.dataset.sub); if (box) { box.classList.toggle('open'); } });
    });

    // Portionen: skaliert nur Mengen, die der Snapshot je Portion trägt (data-b).
    var px = document.getElementById('px');
    function fmt(v, u) {
        if (u === 'g' && v >= 1000) { v = v / 1000; u = 'kg'; } else if (u === 'ml' && v >= 1000) { v = v / 1000; u = 'l'; }
        var s = v >= 100 ? v.toFixed(0) : (v >= 10 ? v.toFixed(1) : v.toFixed(2));
        return s.replace(/\.?0+$/, '').replace('.', ',') + ' ' + (u || '');
    }
    function rechne() {
        var n = Math.max(1, parseFloat(px.value) || 1);
        [].forEach.call(document.querySelectorAll('.pxs'), function (e) { e.textContent = n; });
        [].forEach.call(document.querySelectorAll('td[data-b]'), function (td) { td.textContent = fmt(parseFloat(td.dataset.b) * n, td.dataset.u); });
    }
    document.getElementById('m').addEventListener('click', function () { px.value = Math.max(1, (parseFloat(px.value) || 1) - 10); rechne(); });
    document.getElementById('p').addEventListener('click', function () { px.value = (parseFloat(px.value) || 1) + 10; rechne(); });
    px.addEventListener('input', rechne);

    // Dunkel/Hell merkt sich die Wahl, wenn der Browser Speicher erlaubt.
    var dk = document.getElementById('dk');
    function dunkel(an) { body.classList.toggle('dunkel', an); try { localStorage.setItem('fa-handbuch-dunkel', an ? '1' : '0'); } catch (e) {} }
    try { if (localStorage.getItem('fa-handbuch-dunkel') === '0') { dunkel(false); } } catch (e) {}
    dk.addEventListener('click', function () { dunkel(!body.classList.contains('dunkel')); });

    var breitQuery = window.matchMedia('(min-width: 801px)');
    fromHash(breitQuery.matches);
    rechne();
    // Tablet drehen / Fenster ändern: im breiten Layout darf die rechte Seite nie leer bleiben.
    function breitPruefen() {
        if (breitQuery.matches && !document.querySelector('.gericht.on')) { fromHash(true); }
        if (breitQuery.matches) { app.classList.remove('detail'); }
    }
    if (breitQuery.addEventListener) { breitQuery.addEventListener('change', breitPruefen); } else { breitQuery.addListener(breitPruefen); }
    window.addEventListener('resize', breitPruefen);
})();
</script>
</body>
</html>
