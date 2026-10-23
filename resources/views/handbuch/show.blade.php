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
    $freie = $snapshot['freie_kapitel'] ?? [];
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
    <link rel="stylesheet" href="{{ route('foodalchemist.presentation.handbuch_css', ['v' => $cssVersion ?? 1]) }}">
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
        <span class="stepper stepper-top">Portionen <button type="button" data-step="-10" aria-label="weniger">−</button><input class="px" inputmode="numeric" value="{{ $personen }}" aria-label="Portionen"><button type="button" data-step="10" aria-label="mehr">+</button></span>
        <button type="button" class="ico" id="dk" aria-label="Zwischen dunkel und hell umschalten" title="Dunkel / Hell"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 2a6 6 0 0 1 0 12z" fill="currentColor"/></svg></button>
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
            @if($freie !== [])
                <div class="kapw open">
                    <div class="kap"><span>Allgemein</span><span class="n">{{ count($freie) }}</span></div>
                    <div class="liste">
                        @foreach($freie as $i => $fk)<a href="#k{{ $i }}" data-key="k{{ $i }}">{{ $fk['title'] }}</a>@endforeach
                    </div>
                </div>
            @endif
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
                        $portion['vehikel'] ?? null,
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
                        @if(!empty($z['zeilen']) && $skaliert)
                            <div class="stepper stepper-pane">Portionen <button type="button" data-step="-10" aria-label="weniger">−</button><input class="px" inputmode="numeric" value="{{ $personen }}" aria-label="Portionen"><button type="button" data-step="10" aria-label="mehr">+</button></div>
                        @endif
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
                        @if(!empty($g['behaelter']))
                            <div class="hint">@foreach($g['behaelter'] as $bh)<b>{{ $bh['zweck'] }}:</b> {{ $bh['name'] }}@if(!$loop->last) · @endif @endforeach</div>
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

            @foreach($freie as $i => $fk)
                <article class="gericht" id="g-k{{ $i }}" data-key="k{{ $i }}">
                    <h2>{{ $fk['title'] }}</h2>
                    <div class="freitext">{!! nl2br(e($fk['text'])) !!}</div>
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
    var pxs = [].slice.call(document.querySelectorAll('.px'));
    function aktuell() { return Math.max(1, parseFloat(pxs[0].value) || 1); }
    function setze(v, quelle) { pxs.forEach(function (e) { if (e !== quelle) { e.value = v; } }); rechne(); }
    function fmt(v, u) {
        if (u === 'g' && v >= 1000) { v = v / 1000; u = 'kg'; } else if (u === 'ml' && v >= 1000) { v = v / 1000; u = 'l'; }
        var s = v >= 100 ? v.toFixed(0) : (v >= 10 ? v.toFixed(1) : v.toFixed(2));
        return s.replace(/\.?0+$/, '').replace('.', ',') + ' ' + (u || '');
    }
    function rechne() {
        var n = aktuell();
        [].forEach.call(document.querySelectorAll('.pxs'), function (e) { e.textContent = n; });
        [].forEach.call(document.querySelectorAll('td[data-b]'), function (td) { td.textContent = fmt(parseFloat(td.dataset.b) * n, td.dataset.u); });
    }
    [].forEach.call(document.querySelectorAll('[data-step]'), function (b) {
        b.addEventListener('click', function () { setze(Math.max(1, aktuell() + parseInt(b.dataset.step, 10)), null); });
    });
    pxs.forEach(function (e) { e.addEventListener('input', function () { setze(e.value, e); }); });

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
