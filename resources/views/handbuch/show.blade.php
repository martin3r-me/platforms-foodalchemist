<!DOCTYPE html>
{{--
  Spec 86 — Öffentliches Handbuch (Standards zum Nachschlagen). Eigenständige Seite ohne Login,
  rendert NUR aus dem eingefrorenen Snapshot. Tablet-first im Küchenmonitor-Stil (dunkel, große
  Flächen); auf dem Handy einspaltig mit „Zurück". Kein Build-Schritt: CSS + wenige Zeilen JS inline.
--}}
@php
    $gerichte = $snapshot['gerichte'] ?? [];
    $legende = $snapshot['legende'] ?? null;
    $codeLabel = [];
    foreach (['allergene', 'zusatzstoffe'] as $gruppe) {
        foreach (($legende[$gruppe] ?? []) as $e) {
            $codeLabel[$e['code']] = $e['label'];
        }
    }
@endphp
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $snapshot['title'] ?? 'Handbuch' }} · Handbuch</title>
    <style>
        :root { --bg:#17181a; --pan:#202225; --pan2:#26282b; --line:#2c2e31; --tx:#ececec; --mu:#8b8e93; --ac:#7fb0ff; --wa:#f5d58a; --wab:#4a3a14; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--tx); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .app { display: grid; grid-template-columns: 280px minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); height: 100%; }
        header { grid-column: 1 / -1; display: flex; align-items: baseline; gap: 12px; padding: 12px 20px; border-bottom: 1px solid var(--line); }
        header h1 { margin: 0; font-size: 18px; font-weight: 600; }
        header span { color: var(--mu); font-size: 14px; }
        nav { overflow-y: auto; border-right: 1px solid var(--line); padding: 8px 0 24px; }
        .sec { padding: 14px 20px 4px; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: var(--mu); }
        .sec-text { padding: 0 20px 4px; font-size: 13px; color: var(--mu); }
        nav a { display: block; padding: 12px 20px; color: var(--tx); text-decoration: none; border-left: 4px solid transparent; font-size: 16px; }
        nav a:hover { background: var(--pan); }
        nav a.on { background: var(--pan2); border-left-color: var(--ac); font-weight: 600; }
        main { overflow-y: auto; padding: 20px 28px 48px; }
        .gericht { display: none; max-width: 980px; }
        .gericht.on { display: block; }
        .kopf { display: flex; gap: 20px; flex-wrap: wrap; }
        .foto { width: 280px; max-width: 100%; aspect-ratio: 4 / 3; border-radius: 12px; background: var(--pan2); object-fit: cover; }
        .kopf h2 { margin: 0 0 4px; font-size: 26px; font-weight: 600; }
        .meta { color: var(--mu); font-size: 15px; }
        .chips { margin-top: 10px; display: flex; gap: 6px; flex-wrap: wrap; }
        .chip { padding: 3px 10px; border-radius: 8px; background: var(--wab); color: var(--wa); font-size: 13px; }
        .tabs { display: flex; gap: 8px; margin: 22px 0 8px; flex-wrap: wrap; }
        .tab { padding: 12px 20px; border-radius: 10px; background: var(--pan2); color: var(--tx); border: 0; font: inherit; font-size: 16px; cursor: pointer; }
        .tab.on { background: var(--ac); color: #0b1b33; font-weight: 600; }
        .pane { display: none; }
        .pane.on { display: block; }
        .step { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--line); }
        .nr { flex: none; width: 32px; height: 32px; border-radius: 50%; background: var(--pan2); display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .step p { margin: 0; font-size: 18px; }
        .fotos { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; margin-top: 10px; }
        .fotos img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 8px; background: var(--pan2); }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); font-size: 17px; }
        .row .k { color: var(--mu); text-align: right; }
        .leer { color: var(--mu); padding: 18px 0; }
        .legende { margin-top: 28px; padding-top: 14px; border-top: 1px solid var(--line); color: var(--mu); font-size: 13px; }
        .zurueck { display: none; padding: 10px 0; color: var(--ac); background: none; border: 0; font: inherit; font-size: 16px; cursor: pointer; }
        @media (max-width: 800px) {
            .app { grid-template-columns: 1fr; }
            nav { border-right: 0; }
            main { display: none; padding: 12px 16px 40px; }
            .app.detail nav { display: none; }
            .app.detail main { display: block; }
            .zurueck { display: block; }
            .foto { width: 100%; }
            .kopf h2 { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="app" id="app">
    <header>
        <h1>{{ $snapshot['title'] ?? 'Handbuch' }}</h1>
        @if(!empty($snapshot['subtitle']))<span>{{ $snapshot['subtitle'] }}</span>@endif
    </header>

    <nav aria-label="Gerichte">
        @foreach($snapshot['sections'] ?? [] as $sec)
            @if(($sec['title'] ?? '') !== '')<div class="sec">{{ $sec['title'] }}</div>@endif
            @if(!empty($sec['text']))<div class="sec-text">{{ $sec['text'] }}</div>@endif
            @foreach($sec['items'] as $it)
                <a href="#{{ $it['key'] }}" data-key="{{ $it['key'] }}">{{ $it['label'] !== '' ? $it['label'] : ($gerichte[$it['key']]['name'] ?? '') }}</a>
            @endforeach
        @endforeach
    </nav>

    <main>
        <button type="button" class="zurueck" id="zurueck">‹ Alle Gerichte</button>
        @foreach($gerichte as $key => $g)
            <article class="gericht" id="g-{{ $key }}" data-key="{{ $key }}">
                <div class="kopf">
                    @if(!empty($g['foto_url']))<img class="foto" src="{{ $g['foto_url'] }}" alt="" loading="lazy">@endif
                    <div>
                        <h2>{{ $g['name'] }}</h2>
                        @php
                            $portion = $g['portion'] ?? [];
                            $teile = array_filter([
                                $portion['form'] ?? null,
                                ($portion['gramm'] ?? null) ? rtrim(rtrim(number_format((float) $portion['gramm'], 1, ',', ''), '0'), ',') . ' g' : null,
                                $portion['geschirr'] ?? null,
                            ]);
                        @endphp
                        @if($teile)<div class="meta">{{ implode(' · ', $teile) }}</div>@endif
                        @if(!empty($g['codes']))
                            <div class="chips">
                                @foreach($g['codes'] as $c)<span class="chip">{{ $c }}@if(isset($codeLabel[$c])) · {{ $codeLabel[$c] }}@endif</span>@endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="tabs" role="tablist">
                    <button type="button" class="tab on" data-pane="zubereitung">Zubereitung</button>
                    <button type="button" class="tab" data-pane="anrichten">Anrichten</button>
                    <button type="button" class="tab" data-pane="regeneration">Regeneration</button>
                    <button type="button" class="tab" data-pane="bestandteile">Bestandteile</button>
                </div>

                @foreach(['zubereitung' => 'Keine Zubereitung hinterlegt.', 'anrichten' => 'Kein Anrichten hinterlegt.'] as $pane => $leerText)
                    <div class="pane {{ $pane === 'zubereitung' ? 'on' : '' }}" data-pane="{{ $pane }}">
                        @forelse($g[$pane] as $s)
                            <div class="step">
                                <div class="nr">{{ $s['nr'] }}</div>
                                <div>
                                    <p>{{ $s['text'] }}</p>
                                    @if(!empty($s['fotos']))
                                        <div class="fotos">
                                            @foreach($s['fotos'] as $f)@if(!empty($f['url']))<img src="{{ $f['url'] }}" alt="{{ $f['caption'] ?? '' }}" loading="lazy">@endif @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="leer">{{ $leerText }}</div>
                        @endforelse
                    </div>
                @endforeach

                <div class="pane" data-pane="regeneration">
                    @forelse($g['regeneration'] as $r)
                        <div class="row">
                            <span>{{ $r['komponente'] }}</span>
                            <span class="k">
                                @if($r['kalt'])
                                    Kalt servieren
                                @else
                                    {{ implode(' · ', array_filter([
                                        $r['geraet'],
                                        $r['temp_c'] !== null ? $r['temp_c'] . ' °C' : null,
                                        $r['dauer_min'] !== null ? $r['dauer_min'] . ' min' : null,
                                        $r['kern_c'] !== null ? 'Kern ' . $r['kern_c'] . ' °C' : null,
                                    ])) }}
                                @endif
                                @if(!empty($r['hinweis']))<br>{{ $r['hinweis'] }}@endif
                            </span>
                        </div>
                    @empty
                        <div class="leer">Keine Regeneration hinterlegt.</div>
                    @endforelse
                </div>

                <div class="pane" data-pane="bestandteile">
                    @forelse($g['bestandteile'] as $b)
                        <div class="row"><span>{{ $b['name'] }}</span>@if($b['garnitur'])<span class="k">Garnitur</span>@endif</div>
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

<script>
(function () {
    var app = document.getElementById('app');
    var links = [].slice.call(document.querySelectorAll('nav a'));
    var arts = [].slice.call(document.querySelectorAll('.gericht'));
    function show(key, mobileDetail) {
        arts.forEach(function (a) { a.classList.toggle('on', a.dataset.key === key); });
        links.forEach(function (l) { l.classList.toggle('on', l.dataset.key === key); });
        app.classList.toggle('detail', !!key && mobileDetail);
        var m = document.querySelector('main'); if (m) { m.scrollTop = 0; }
    }
    function fromHash(first) {
        var key = location.hash.replace('#', '');
        if (!document.getElementById('g-' + key)) { key = first && links.length ? links[0].dataset.key : ''; }
        show(key, !!location.hash && key !== '');
    }
    window.addEventListener('hashchange', function () { fromHash(false); });
    document.getElementById('zurueck').addEventListener('click', function () { history.pushState('', '', location.pathname); show('', false); fromHash(false); app.classList.remove('detail'); });
    arts.forEach(function (a) {
        var tabs = [].slice.call(a.querySelectorAll('.tab')), panes = [].slice.call(a.querySelectorAll('.pane'));
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
                panes.forEach(function (p) { p.classList.toggle('on', p.dataset.pane === t.dataset.pane); });
            });
        });
    });
    var breitQuery = window.matchMedia('(min-width: 801px)');
    fromHash(breitQuery.matches);
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
