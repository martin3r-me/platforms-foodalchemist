<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Speiseplan — {{ $plan->name }} · {{ $kwLabel }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.45; margin: 0; padding: 28px; }
        .doc { max-width: 1040px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 10px; margin-bottom: 14px; }
        h1 { font-size: 20px; margin: 0 0 2px; color: #111827; }
        .sub { color: #6b7280; font-size: 12px; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th, table.grid td { border: 1px solid #e5e7eb; padding: 6px 7px; vertical-align: top; text-align: left; }
        table.grid thead th { background: #f5f3ff; color: #4c1d95; font-size: 11px; }
        table.grid th.linie { width: 120px; }
        td.linie { font-weight: bold; color: #374151; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .dish { margin-bottom: 4px; }
        .dish:last-child { margin-bottom: 0; }
        .codes { color: #6d28d9; font-size: 9px; font-weight: bold; }
        .leer { color: #d1d5db; }
        .kostform { margin: 14px 0 6px; font-size: 10px; }
        .kostform .kf { display: inline-block; margin-right: 10px; }
        .kf .ok { color: #15803d; }
        .kf .warn { color: #b45309; }
        .kf .miss { color: #9ca3af; }
        .legende { margin-top: 14px; border-top: 1px solid #ececec; padding-top: 10px; font-size: 10px; color: #4b5563; }
        .legende h3 { font-size: 11px; color: #374151; margin: 0 0 4px; }
        .legende .lg { margin-right: 12px; white-space: nowrap; line-height: 1.9; }
        .legende .c { color: #6d28d9; font-weight: bold; }
        .foot { margin-top: 20px; color: #9ca3af; font-size: 9px; border-top: 1px solid #ececec; padding-top: 8px; }
        .actions { margin-bottom: 16px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
        /* #3: Produktions-Kaskaden-Anhang — self-contained, gescopet. */
        .kaskade-anhang { margin-top: 24px; page-break-before: always; }
        .kaskade-anhang .kaskade-titel { font-size: 15px; color: #6d28d9; border-bottom: 2px solid #6d28d9; padding-bottom: 4px; margin: 0 0 12px; }
@include('foodalchemist::dokumente.partials.report-node-css')
        .kaskade-anhang .recipe-node { margin: 0 0 10px; }
        .kaskade-anhang .recipe-node.depth-1 { margin-left: 10px; }
        .kaskade-anhang .recipe-node.depth-2, .kaskade-anhang .recipe-node.depth-3, .kaskade-anhang .recipe-node.depth-4 { margin-left: 20px; }
        .kaskade-anhang h2, .kaskade-anhang h3 { font-size: 12px; font-weight: bold; color: #111827; margin: 10px 0 4px; }
        .kaskade-anhang .muted { color: #9ca3af; font-weight: normal; font-size: 10px; }
        .kaskade-anhang .grid.meta { margin: 2px 0 6px; font-size: 10px; color: #374151; }
        .kaskade-anhang .grid.meta > div { display: inline-block; margin-right: 12px; }
        .kaskade-anhang .grid.meta > div > span { color: #9ca3af; margin-right: 3px; }
        .kaskade-anhang .copy { color: #374151; font-size: 10px; margin: 2px 0 6px; white-space: pre-line; }
        .kaskade-anhang .warn { color: #b45309; font-size: 10px; }
        .kaskade-anhang table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 2px 0 6px; }
        .kaskade-anhang th, .kaskade-anhang td { text-align: left; padding: 2px 4px; border-bottom: 1px solid #eee; }
        .kaskade-anhang th { color: #6b7280; font-weight: normal; }
    </style>
</head>
<body>
<div class="doc">
    @unless($istPdf ?? false)
        <div class="actions">
            <a class="btn" href="?{{ http_build_query(array_merge(request()->query(), ['pdf' => 1])) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @php($istIntern = $intern ?? false)
            @if($istIntern)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => null, 'pdf' => null]) }}">→ Kundensicht</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => 1, 'pdf' => null]) }}">→ Interne Sicht (EK)</a>
            @endif
            {{-- #3: Produktions-Kaskaden-Anhang an/aus --}}
            @if(!empty($kaskaden))
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['kaskade' => null, 'pdf' => null]) }}">→ ohne Kaskade</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['kaskade' => 1, 'pdf' => null]) }}">→ mit Produktions-Kaskade</a>
            @endif
        </div>
    @endunless

    <div class="head">
        <h1>Speiseplan — {{ $plan->name }}</h1>
        <div class="sub">{{ $kwLabel }} · {{ $mahlzeitLabel }}</div>
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th class="linie">Menü-Linie</th>
                @foreach($tage as $t)
                    <th>{{ $t['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($zeilen as $z)
                <tr>
                    <td class="linie">
                        @if($z['color'])<span class="dot" style="background: {{ $z['color'] }}"></span>@endif{{ $z['linie'] }}
                    </td>
                    @foreach($tage as $t)
                        <td>
                            @php($cells = $z['zellen'][$t['ymd']] ?? [])
                            @forelse($cells as $c)
                                <div class="dish">{{ $c['name'] }}@if(!empty($c['codes'])) <span class="codes">{{ implode(', ', $c['codes']) }}</span>@endif</div>
                            @empty
                                <span class="leer">—</span>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($tage) + 1 }}" class="leer" style="text-align:center; padding:16px;">Keine Menü-Linien / keine Belegung in dieser Woche.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Kostformen-Abdeckung der Woche (GV-Qualität) --}}
    @if(!empty($kostformen))
        <div class="kostform">
            <strong>Kostformen (Werktage):</strong>
            @foreach($kostformen as $kf)
                <span class="kf">{{ $kf['label'] }}:
                    @if($kf['erfuellt'])<span class="ok">täglich ✓</span>
                    @elseif($kf['abgedeckt'] > 0)<span class="warn">{{ $kf['abgedeckt'] }}/{{ $kf['tage'] }}</span>
                    @else<span class="miss">—</span>@endif
                </span>
            @endforeach
        </div>
    @endif

    {{-- DGE-Nährwert-Wochenschnitt --}}
    @if(!empty($naehrwerte) && $naehrwerte['tage_mit_daten'] > 0)
        @php($n = $naehrwerte['schnitt'])
        <div class="kostform"><strong>Ø Nährwerte/Person/Tag:</strong>
            <span class="kf">kcal {{ $n['kcal'] !== null ? number_format($n['kcal'], 0, ',', '.') : '—' }}</span>
            <span class="kf">Eiweiß {{ $n['protein_g'] !== null ? number_format($n['protein_g'], 1, ',', '.') . ' g' : '—' }}</span>
            <span class="kf">Fett {{ $n['fett_g'] !== null ? number_format($n['fett_g'], 1, ',', '.') . ' g' : '—' }}</span>
            <span class="kf">davon ges. {{ $n['gesfett_g'] !== null ? number_format($n['gesfett_g'], 1, ',', '.') . ' g' : '—' }}</span>
            <span class="kf">Salz {{ $n['salz_g'] !== null ? number_format($n['salz_g'], 2, ',', '.') . ' g' : '—' }}</span>
            <span class="kf">Zucker {{ $n['zucker_g'] !== null ? number_format($n['zucker_g'], 1, ',', '.') . ' g' : '—' }}</span>
        </div>
    @endif

    {{-- Pflicht-Legende (LMIV): nur was in dieser Woche vorkommt --}}
    @if(!empty($legende['allergene']) || !empty($legende['zusatzstoffe']))
        <div class="legende">
            @if(!empty($legende['allergene']))
                <h3>Allergene</h3>
                <div>
                    @foreach($legende['allergene'] as $a)<span class="lg"><span class="c">{{ $a['code'] }}</span> = {{ $a['label'] }}</span>@endforeach
                </div>
            @endif
            @if(!empty($legende['zusatzstoffe']))
                <h3 style="margin-top:8px;">Zusatzstoffe</h3>
                <div>
                    @foreach($legende['zusatzstoffe'] as $z)<span class="lg"><span class="c">{{ $z['code'] }}</span> = {{ $z['label'] }}</span>@endforeach
                </div>
            @endif
            <div style="margin-top:6px; color:#9ca3af;">* = Spuren möglich. Angaben aus den hinterlegten Rezepturen (ALL-MAXIMAL); ohne Angabe = nicht bewertet, nicht „frei".</div>
        </div>
    @endif

    <div class="foot">Erstellt mit Food Alchemist · {{ $erzeugt }}</div>
</div>

{{-- #3: Produktions-Kaskaden-Anhang (nur wenn ?kaskade=1). Wiederverwendet report-recipe-node. --}}
@if(!empty($kaskaden))
    <div class="kaskade-anhang">
        <div class="kaskade-titel">Produktions-Kaskade{{ ($intern ?? false) ? ' · intern (mit EK)' : '' }}</div>
        @foreach($kaskaden as $kas)
            @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $kas['recipe'], 'optionen' => $kas['optionen']])
        @endforeach
    </div>
@endif
</body>
</html>
