@php
    $pdf = $istPdf ?? false;
    $opt = $optionen ?? [];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
        'kalkulation' => 'Kalkulation',
        'voll' => 'Volle Kaskade',
    ];
    /* fa-pass Druck-Muster (2026-10-05): interner Report im Food.Alchemist-Design. Feste Werte statt
       CSS-Variablen (DomPDF kann keine), abgeleitet aus den --fa-*-Tokens (foodalchemist-pass.css). */
    $brand = '#0a3dd6';            // --fa-accent (Logo-Blau)
    $c = [
        'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'soft' => '#f3f5f8', 'accentSoft' => '#e6edfe',
        'rail' => '#0d1424', 'warn' => '#8a5200', 'warnSoft' => '#fbefd9', 'warnLine' => '#f0d49a',
    ];
    $footerText = 'Erstellt mit Food.Alchemist';
    // Wortmarke als data-URI aus dem Modul selbst: gleich im Browser und im PDF, unabhängig vom public/-Ordner.
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $titel ?? 'Report' }} – {{ $name ?? '' }}</title>
    <style>
        /* DomPDF-Leitplanken wie Speisekarte: keine CSS-Variablen, kein Flex/Grid, feste Bänder,
           Tabellen/Blocks statt App-CSS. Zwei Ausgabewege teilen dieses Blatt: DomPDF ($pdf=true)
           und der Browser-Druck (@media print) — deshalb hat @page IMMER Ränder. Vorher stand dort
           im HTML-Modus `margin: 0`, zusammen mit `.doc { padding: 0 }` im Druck: der Satz klebte
           am Blattrand und lief in den nicht druckbaren Bereich. */
        @page { size: A4 portrait; margin: {{ $pdf ? '2.15cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; padding: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
        /* DomPDF zählt Padding zur Breite: 18,2 cm + 2 × 1,4 cm = 21 cm Blattbreite. */
        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.15cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 1.25cm; background: #fff; color: {{ $c['ink3'] }}; padding: 0 1.4cm; border-bottom: 2px solid {{ $brand }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto;' }}
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.44cm;' : 'display: block; line-height: 1.25cm;' }} font-size: 9.5px; letter-spacing: .02em; }
        .band-top img { height: {{ $pdf ? '0.78cm' : '0.8cm' }}; vertical-align: middle; }
        .band-top .bt-logo { {{ $pdf ? 'position: absolute; top: 0.22cm; left: 1.4cm;' : 'float: left; padding-top: 0.22cm;' }} }
        .band-top .bt-label { text-align: right; }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.5cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 0.95cm; border-top: 1px solid {{ $c['line'] }}; color: {{ $c['ink3'] }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto; background: #fff;' }} font-size: 8.5px; padding: 0 1.4cm;
        }
        .band-bottom .bb-foot { display: block; line-height: 0.95cm; }
        .actions { background: {{ $c['rail'] }}; color: #fff; padding: 12px 16px; margin: {{ $pdf ? '0' : '-2.15cm -1.4cm 20px' }}; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: {{ $brand }}; border-color: {{ $brand }}; }
        .actions .secondary a { color: #b6c0d2; }
        .actions strong { color: #b6c0d2; font-weight: bold; margin-right: 4px; }
        .simulation-control { margin-bottom: 9px; padding-bottom: 9px; border-bottom: 1px solid rgba(255,255,255,.16); }
        .simulation-control form { display: inline-block; margin-left: 6px; }
        .simulation-control input { width: 90px; border: 1px solid rgba(182,192,210,.3); border-radius: 6px; background: #151e33; color: #fff; padding: 5px 7px; font: inherit; }
        .simulation-control button { border: 0; border-radius: 6px; background: {{ $brand }}; color: #fff; padding: 6px 10px; font: inherit; cursor: pointer; }
        header { margin-bottom: 6px; }
        .kicker { font-size: 9.5px; letter-spacing: .04em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        h2 { font-size: 14px; margin: 12px 0 4px; border-top: 1px solid {{ $c['line'] }}; padding-top: 6px; color: {{ $c['ink'] }}; page-break-after: avoid; }
        h3 { font-size: 12.5px; margin: 10px 0 4px; padding-top: 0; page-break-after: avoid; }
        h4 { font-size: 10.5px; margin: 9px 0 3px; color: {{ $brand }}; letter-spacing: .01em; page-break-after: avoid; }
        h5 { font-size: 10px; margin: 7px 0 3px; color: {{ $c['ink2'] }}; }
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        .warn { color: {{ $c['warn'] }}; background: {{ $c['warnSoft'] }}; border: 1px solid {{ $c['warnLine'] }}; padding: 5px 7px; }
        .intro { margin: 6px 0 8px; color: {{ $c['ink2'] }}; white-space: pre-line; }

        /* Meta-Kacheln: 3 Spalten statt 4 (mehr Platz je Wert, weniger Umbruch),
           knapper gesetzt. Leere Werte rendert der Rezept-Partial nicht mehr mit. */
        .grid { width: 100%; margin: 3px 0 6px; font-size: 0; }
        .grid > div { display: inline-block; width: {{ $pdf ? '22.4%' : '24.6%' }}; border: 1px solid {{ $c['line'] }}; padding: 2px 6px; vertical-align: top; font-size: 10px; margin-right: -1px; margin-bottom: -1px; overflow-wrap: anywhere; }
        .grid > div.wide { width: {{ $pdf ? '47.3%' : '49.8%' }}; }
        header > .muted { display: block; margin-bottom: 6px; }
        .grid > div.full { width: {{ $pdf ? '97.4%' : '100%' }}; }
        .grid span { display: block; color: {{ $c['ink3'] }}; font-size: 8.5px; letter-spacing: .02em; margin-bottom: 0; }

        table { width: 100%; border-collapse: collapse; margin: 3px 0 6px; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        td.num, th.num { text-align: right; }
        table.deklaration th { width: 24%; }
        .sum-line td { border-top: 2px solid {{ $c['ink3'] }}; font-weight: 700; background: {{ $c['soft'] }}; }
        .copy { white-space: pre-line; color: {{ $c['ink2'] }}; margin: 3px 0 4px; }
        .copy p, .intro p { margin: 0 0 3px; }
        p { margin: 0 0 5px; }

@include('foodalchemist::dokumente.partials.report-node-css')
        .slot { border: 1px solid {{ $c['line'] }}; padding: 5px 7px; margin: 5px 0; page-break-inside: avoid; }
        .badge { display: inline-block; background: {{ $c['soft'] }}; padding: 2px 6px; font-size: 9.5px; color: {{ $c['ink2'] }}; }
        .sensorik-radar { display: table; width: 100%; margin: 4px 0 6px; page-break-inside: avoid; }
        .sensorik-radar-chart { display: table-cell; width: 32%; vertical-align: top; text-align: center; border: 1px solid {{ $c['line'] }}; padding: 3px; }
        .sensorik-radar-values { display: table-cell; width: 68%; vertical-align: top; padding-left: 9px; }
        .sensorik-radar-values table { margin-top: 0; }
        .order-simulation { page-break-before: auto; }
        .order-simulation .sum-row td { border-top: 2px solid {{ $c['line'] }}; font-weight: 700; }
        .order-simulation .accent-row td { color: {{ $brand }}; }
        .order-simulation table { font-size: 9px; }

        @media print {
            /* Ränder kommen aus @page (oben), .doc bringt keine eigenen mehr mit.
               Die Bänder bleiben im Fluss: als `position: fixed` legt Chrome sie beim
               Drucken NICHT in den Seitenrand, sondern über den Satz (getestet — Kopf-
               und Fußband landeten mitten in Tabelle und Anleitung). Seitenzahl/Titel
               liefert der Browser-Druckdialog selbst. */
            .actions { display: none !important; }
            body { background: #fff; }
            .doc { max-width: none; margin: 0; padding: 0; }
            .band-top { margin-bottom: 12px; }
            .band-bottom { margin-top: 14px; }
            .recipe-node, .photo-strip, .grid { page-break-inside: auto; }
        }
    </style>
</head>
<body>
<div class="band-top">
    @if($logo)<span class="bt-logo"><img src="{{ $logo }}" alt="Food.Alchemist"></span>@endif
    <span class="bt-label">{{ $titel ?? 'Report' }}</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            {{-- E (2026-09-04): Bedarfs-Hochrechnung am Rezept/Gericht. Basisrezept in Ziel-Kilo,
                 Gericht in „N × Darreichung" (Standard vorgewählt, umschaltbar) — dieselbe
                 Mechanik wie die Concept-Auftragssimulation, nur die Eingabe unterscheidet sich. --}}
            @if(($recipe ?? null) && ($hochrechnung ?? null))
                <div class="simulation-control" data-report-hochrechnung>
                    <strong>Bedarf hochrechnen:</strong>
                    <form method="get" action="{{ request()->url() }}">
                        @foreach(request()->except(['ziel_kg', 'ziel_menge', 'darreichung', 'pdf']) as $key => $value)
                            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                        @endforeach
                        @if(($typ ?? '') === 'gericht' && ($hochrechnung['darreichungen'] ?? []) !== [])
                            <input type="number" min="1" max="1000000" step="1" name="ziel_menge"
                                   value="{{ (int) ($hochrechnung['ziel_menge'] ?? 0) ?: '' }}"
                                   placeholder="Anzahl" aria-label="Anzahl der Verkaufseinheiten">
                            <select name="darreichung" aria-label="Darreichung">
                                @foreach($hochrechnung['darreichungen'] as $d)
                                    <option value="{{ $d['id'] }}"
                                        @selected(($hochrechnung['darreichung']['id'] ?? null) === $d['id']
                                            || (($hochrechnung['darreichung'] ?? null) === null && $d['is_standard']))>
                                        {{ $d['label'] }}{{ $d['gramm'] !== null ? ' · ' . number_format($d['gramm'], 0, ',', '.') . ' g' : ' · kein Gewicht' }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" name="ziel_kg" inputmode="decimal"
                                   value="{{ ($hochrechnung['ziel_kg'] ?? null) !== null ? str_replace('.', ',', (string) $hochrechnung['ziel_kg']) : '' }}"
                                   placeholder="z. B. 50" aria-label="Zielmenge in Kilogramm" style="width:6em">
                            <span>kg</span>
                        @endif
                        <button type="submit">Hochrechnen</button>
                    </form>
                    @if($hochrechnung['aktiv'] ?? false)
                        <a class="active" href="{{ request()->fullUrlWithQuery(['ziel_kg' => null, 'ziel_menge' => null, 'darreichung' => null, 'pdf' => null]) }}">
                            {{ number_format((float) $hochrechnung['ziel_kg'], 3, ',', '.') }} kg
                            (Ansatz {{ number_format((float) ($hochrechnung['ansatz_kg'] ?? 0), 3, ',', '.') }} kg ·
                            ×{{ number_format((float) $hochrechnung['faktor'], 2, ',', '.') }}) ×
                        </a>
                    @endif
                    @if($hochrechnung['hinweis'] ?? null)
                        <span style="color:#f2c26b">{{ $hochrechnung['hinweis'] }}</span>
                    @endif
                </div>
            @endif
            @if($concept ?? null)
                <div class="simulation-control" data-report-simulation-control>
                    <strong>Auftragssimulation:</strong>
                    <form method="get" action="{{ request()->url() }}">
                        @foreach(request()->except(['pax', 'simulation', 'pdf']) as $key => $value)
                            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                        @endforeach
                        <input type="hidden" name="simulation" value="1">
                        <input type="number" min="1" max="1000000" step="1" name="pax" value="{{ (int) ($opt['pax'] ?? 0) ?: '' }}" placeholder="Pax" aria-label="Pax für Auftragssimulation">
                        <button type="submit">Simulieren</button>
                    </form>
                    @if(($opt['simulation'] ?? false) && (int) ($opt['pax'] ?? 0) > 0)
                        <a class="active" href="{{ request()->fullUrlWithQuery(['simulation' => 0, 'pax' => null, 'pdf' => null]) }}">{{ number_format((int) $opt['pax'], 0, ',', '.') }} Pax aktiv ×</a>
                    @endif
                </div>
            @endif
            <div>
                <strong>Report-Profile:</strong>
                @foreach($profile as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery(['profil' => $key, 'pdf' => null]) }}" class="{{ ($opt['profil'] ?? '') === $key ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
                <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
                <a href="javascript:window.print()">Drucken</a>
            </div>
            <div class="secondary" style="margin-top:6px">
                <strong>Filter:</strong>
                {{-- Die drei Anleitungs-Ebenen (Regelwerk Verkaufsgerichte §3) sind einzeln
                     zuschaltbar, damit Produktionsküche, Satellit und Pass sich jeweils ihr Blatt
                     ziehen. „Anleitung" bleibt neutral benannt: dasselbe Flag rendert am
                     Basisrezept die Produktion und am Gericht die Fertigstellung. --}}
                @foreach(['preise' => 'Preise', 'lieferanten' => 'Lieferanten', 'steps' => 'Anleitung', 'regeneration' => 'Regeneration', 'behaelter' => 'Behälter', 'anrichten' => 'Anrichten', 'bilder' => 'Bilder', 'deklaration' => 'Deklaration', 'naehrwerte' => 'Nährwerte', 'sensorik' => 'Sensorik', 'produktion' => 'Produktion', 'notizen' => 'Notizen', 'kaskade' => 'Kaskade'] as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery([$key => ($opt[$key] ?? false) ? 0 : 1, 'pdf' => null]) }}" class="{{ ($opt[$key] ?? false) ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    @endunless

    <header>
        <div class="kicker">{{ $titel ?? 'Report' }} · {{ now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $name ?? 'Report' }}</h1>
        <div class="rule"></div>
        <div class="muted">Profil: {{ $profile[$opt['profil'] ?? 'produktion'] ?? ($opt['profil'] ?? '—') }}</div>
        {{-- Steht AUCH im PDF: ein hochgerechnetes Blatt darf nicht wie der Ansatz aussehen.
             Die Arbeitszeit ist bewusst nicht mitskaliert (Rüstzeit fällt einmal an, Batch-
             Grenzen und Standzeit rechnet der Produktionsplaner) — das wird hier gesagt,
             statt eine linear hochgerechnete Zahl hinzustellen. --}}
        @if($hochrechnung['aktiv'] ?? false)
            <div style="margin-top:6px;padding:6px 10px;border-left:3px solid {{ $brand }};background:{{ $c['accentSoft'] }};font-size:11px">
                <strong>Bedarf für {{ number_format((float) $hochrechnung['ziel_kg'], 3, ',', '.') }} kg</strong>
                @if(($hochrechnung['ziel_menge'] ?? null) && ($hochrechnung['darreichung'] ?? null))
                    — {{ $hochrechnung['ziel_menge'] }} × {{ $hochrechnung['darreichung']['label'] }}
                @endif
                · Ansatz {{ number_format((float) ($hochrechnung['ansatz_kg'] ?? 0), 3, ',', '.') }} kg
                · Faktor ×{{ number_format((float) $hochrechnung['faktor'], 3, ',', '.') }}<br>
                Mengen, Ausbeute und Einkaufswerte sind hochgerechnet. <strong>Arbeitszeiten nicht</strong> —
                die sind nicht linear (Rüstzeit einmalig, Batch-Grenzen, Standzeit); dafür den Produktionsplaner nutzen.
            </div>
        @endif
    </header>

    @php
        /* Inhaltsteil im Druck-Muster (2026-10-05): Küchensprache statt Rohwerte — keine IDs,
           keine Status-Codes, keine Feldnamen. Kacheln wie im Rezept-Partial: leere Werte
           werden nicht als „—"-Kachel gesetzt (Platz), alles mit Inhalt bleibt drin. */
        $kachel = function (string $label, $wert, string $klasse = '') {
            if ($wert === null || $wert === '' || $wert === '—') {
                return '';
            }

            return '<div' . ($klasse ? ' class="' . $klasse . '"' : '') . '><span>'
                . e($label) . '</span>' . e($wert) . '</div>';
        };
        $gitter = function (array $kacheln) {
            $html = implode('', array_filter($kacheln));

            return $html === '' ? '' : '<div class="grid meta keep">' . $html . '</div>';
        };
        // Rohzahl („2000.0000") → „2.000"; „3.8100" → „3,81".
        $zahl = fn ($v) => $v === null || $v === '' || ! is_numeric($v) ? ($v ?: '—')
            : rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
        $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'];
        $herkunftLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'];
        // GP-Merkmale (FoodAlchemistGp::TAG_FIELDS) — Wortlaut wie in der Deklaration.
        $merkmalLabel = [
            'is_vegan' => 'Vegan', 'is_vegetarian' => 'Vegetarisch', 'is_halal' => 'Halal',
            'contains_pork' => 'Enthält Schwein', 'contains_beef' => 'Enthält Rind',
            'is_organic' => 'Bio', 'is_regional' => 'Regional', 'is_staple_food' => 'Grundnahrungsmittel',
            'is_convenience' => 'Convenience', 'is_lactose_free' => 'Laktosefrei', 'is_gluten_free' => 'Glutenfrei',
        ];
    @endphp
    @if($report ?? null)
        @php $kind = $report['kind'] ?? null; @endphp

        @if($kind === 'gp')
            @php
                $gp = $report['gp'];
                $gpStatus = ($gp['status'] ?? '') === '' ? null
                    : (\Platform\FoodAlchemist\Enums\GpStatus::tryFrom((string) $gp['status'])?->label() ?? $gp['status']);
                $lead = $gp['lead_la'] ?? null;
                $merkmale = collect($gp['tags'] ?? []);
                $merkmalText = fn (bool $wert) => $merkmale->filter(fn ($v) => (bool) $v === $wert)
                    ->keys()->map(fn ($k) => $merkmalLabel[$k] ?? $k)->implode(', ');
            @endphp
            <section>
                <h2>Grundprodukt</h2>
                {!! $gitter([
                    $kachel('Status', $gpStatus),
                    $kachel('Warengruppe', $gp['warengruppe'] ?? null),
                    $kachel('Unterkategorie', $gp['sub_category'] ?? null),
                ]) !!}
                @if($lead)
                    <h4>Hauptartikel</h4>
                    {!! $gitter([
                        $kachel('Lieferant', $lead['supplier'] ?? null),
                        $kachel('Artikel-Nr.', $lead['article_number'] ?? null),
                        $kachel('Gebinde', trim(($lead['packaging_unit'] ?? '') . ' ' . (($lead['qty'] ?? null) !== null ? $zahl($lead['qty']) : '') . ' ' . ($lead['unit_code'] ?? ''))),
                        $kachel('Preis', ($lead['price'] ?? null) !== null ? $money($lead['price']) : null),
                        $kachel('Bezeichnung', $lead['designation'] ?? null, 'wide'),
                    ]) !!}
                @else
                    <p class="muted">Kein Hauptartikel gesetzt.</p>
                @endif
                @if($merkmale->isNotEmpty())
                    <p class="muted">
                        @if($merkmalText(true) !== '')Merkmale: <strong>{{ $merkmalText(true) }}</strong>@endif
                        @if($merkmalText(true) !== '' && $merkmalText(false) !== '') · @endif
                        @if($merkmalText(false) !== '')nicht: {{ $merkmalText(false) }}@endif
                    </p>
                @endif
                @if($opt['deklaration'] ?? false)
                    @include('foodalchemist::dokumente.partials.report-declaration', ['deklaration' => $gp['deklaration'] ?? []])
                @endif
                @if($opt['naehrwerte'] ?? false)
                    @include('foodalchemist::dokumente.partials.report-nutrition', ['naehrwerte' => $gp['naehrwerte'] ?? []])
                @endif
            </section>

            <section>
                <h2>Lieferantenartikel</h2>
                <table>
                    <thead><tr><th width="30%">Lieferant</th><th width="14%">Art.-Nr.</th><th>Bezeichnung</th><th width="13%">Zuordnung</th></tr></thead>
                    <tbody>
                        @forelse($gp['strukturen'] as $s)
                            <tr><td>{{ $s['supplier'] ?? '—' }}</td><td>{{ $s['article_number'] ?? '—' }}</td><td>{{ $s['designation'] ?? '—' }}</td><td>{{ $s['needs_review'] ? 'zu prüfen' : 'geprüft' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="muted">Keine Lieferantenartikel verknüpft.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section>
                <h2>Verwendung</h2>
                <table>
                    <thead><tr><th width="13%">Typ</th><th>Rezept</th><th width="11%" class="num">Menge</th><th width="34%">Originaltext</th></tr></thead>
                    <tbody>
                        @forelse($gp['verwendung'] as $v)
                            <tr><td>{{ $v['typ'] }}</td><td>{{ $v['recipe'] ?? '—' }}</td><td class="num">{{ $zahl($v['quantity'] ?? null) }}</td><td>{{ $v['raw_text'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="muted">In keinem Rezept verwendet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @elseif($kind === 'supplier')
            @php
                $supplier = $report['supplier'];
                $lieferStatus = ($supplier['status'] ?? null)
                    ? (\Platform\FoodAlchemist\Enums\SupplierStatus::tryFrom((string) $supplier['status'])?->label() ?? $supplier['status'])
                    : ($supplier['is_inactive'] ? 'inaktiv' : 'aktiv');
            @endphp
            <section>
                <h2>Lieferant</h2>
                {!! $gitter([
                    $kachel('Status', $lieferStatus),
                    $kachel('Ort', $supplier['city'] ?? null),
                    $kachel('Bestell-E-Mail', $supplier['email_order'] ?? null, 'wide'),
                    $kachel('Homepage', $supplier['homepage'] ?? null, 'wide'),
                ]) !!}
            </section>
            <section>
                <h2>Artikel</h2>
                <table>
                    <thead><tr><th width="11%">Art.-Nr.</th><th width="25%">Bezeichnung</th><th width="11%">Gebinde</th><th width="10%" class="num">Preis</th><th>Grundprodukt</th><th width="13%">Hauptartikel</th><th width="10%">Status</th></tr></thead>
                    <tbody>
                        @forelse($supplier['items'] as $item)
                            <tr>
                                <td>{{ $item['article_number'] ?? '—' }}</td>
                                <td>{{ $item['designation'] ?? '—' }}</td>
                                <td>{{ trim(($item['packaging_unit'] ?? '') . ' ' . (($item['qty'] ?? null) !== null ? $zahl($item['qty']) : '') . ' ' . ($item['unit_code'] ?? '')) ?: '—' }}</td>
                                <td class="num">{{ $money($item['price'] ?? null) }}</td>
                                <td>{{ $item['gp'] ?? '—' }}</td>
                                <td>{{ $item['is_lead'] ? 'ja' : '—' }}</td>
                                <td>{{ $item['is_discontinued'] ? 'ausgelistet' : 'aktiv' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="muted">Keine Artikel.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @elseif($kind === 'geschirr')
            @php $supplier = $report['supplier']; @endphp
            <section>
                <h2>Geschirr-Lieferant</h2>
                {!! $gitter([
                    $kachel('Status', $supplier['is_inactive'] ? 'inaktiv' : 'aktiv'),
                    $kachel('Ort', $supplier['city'] ?? null),
                    $kachel('E-Mail', $supplier['email_order'] ?? null, 'wide'),
                    $kachel('Homepage', $supplier['homepage'] ?? null, 'wide'),
                ]) !!}
            </section>
            <section>
                <h2>Geschirr-Artikel</h2>
                <table>
                    <thead><tr><th width="10%">Art.-Nr.</th><th width="22%">Bezeichnung</th><th>Kategorie</th><th>Material</th><th>Maße</th><th width="12%" class="num">Leihpreis</th><th width="9%" class="num">Pfand</th><th width="8%">Status</th></tr></thead>
                    <tbody>
                        @forelse($supplier['items'] as $item)
                            <tr>
                                <td>{{ $item['artikel_nr'] ?? '—' }}</td>
                                <td>{{ $item['label'] ?? '—' }}</td>
                                <td>{{ $item['category'] ?? '—' }}</td>
                                <td>{{ $item['material'] ?? '—' }}</td>
                                <td>{{ $item['masse'] ?? '—' }}</td>
                                <td class="num">{{ $money($item['rental_price'] ?? null) }}{{ $item['unit'] ? ' / ' . $item['unit'] : '' }}</td>
                                <td class="num">{{ $money($item['pfand'] ?? null) }}</td>
                                <td>{{ $item['is_inactive'] ? 'inaktiv' : 'aktiv' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="muted">Keine Artikel.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @elseif($kind === 'favoriten')
            <section>
                <h2>Favoriten-Grundprodukte</h2>
                <p class="muted">{{ $report['n_favoriten'] ?? 0 }} als Favorit markiert · {{ count($report['items'] ?? []) }} Grundprodukte gelistet</p>
                <table>
                    <thead><tr><th width="8%">Favorit</th><th width="6%" class="num">Rang</th><th>Grundprodukt</th><th width="9%" class="num">Nutzung</th><th width="11%">Hauptartikel</th><th width="7%">Preis</th><th width="9%" class="num">Relevanz</th><th width="11%">Convenience</th></tr></thead>
                    <tbody>
                        @forelse($report['items'] as $item)
                            <tr>
                                <td>{{ $item['is_favorite'] ? 'ja' : '—' }}</td>
                                <td class="num">{{ $item['favorite_rank'] ?? '—' }}</td>
                                <td>{{ $item['name'] }}</td>
                                <td class="num">{{ $item['usage'] }}</td>
                                <td>{{ $item['has_lead_la'] ? 'ja' : 'fehlt' }}</td>
                                <td>{{ $item['has_price'] ? 'ja' : 'fehlt' }}</td>
                                <td class="num">{{ number_format((float) $item['score'], 2, ',', '.') }}</td>
                                <td>{{ $item['is_convenience'] ? 'ja' : 'nein' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="muted">Keine Favoriten.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @endif
    @elseif($concept)
        @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $concept, 'optionen' => $opt])
    @elseif($format ?? null)
        {{-- F3b: Technischer Format-Report — Format-Übersicht + Editionen (jede über den
             GETEILTEN Concept-Körper, damit die Filter LITERAL dieselben sind) + Struktur. --}}
        <section>
            <h2>Format-Übersicht</h2>
            @php $pr = $format['price_range'] ?? ['min' => null, 'max' => null]; @endphp
            {!! $gitter([
                $kachel('Status', ($format['status'] ?? null) ? ($statusLabel[$format['status']] ?? $format['status']) : null),
                $kachel('Herkunft', ($format['origin'] ?? null) ? ($herkunftLabel[$format['origin']] ?? $format['origin']) : null),
                $kachel('Servierform', $format['serving_form'] ?? null),
                $kachel('Eventtyp', $format['event_type'] ?? null),
                $kachel('Preisspanne p. P.', ($pr['min'] ?? null) === null ? null : ($pr['min'] === $pr['max'] ? $money($pr['min']) : $money($pr['min']) . ' – ' . $money($pr['max']))),
                $kachel('Konsumentenbezeichnung', $format['consumer_name'] ?? null, 'wide'),
                $kachel('Claim', $format['claim'] ?? null, 'wide'),
            ]) !!}
            @if($format['story'] ?? null)<p class="intro">{{ $format['story'] }}</p>@endif
            @if(count($format['moments'] ?? []) || count($format['seasons'] ?? []))
                <p class="muted">Einsatzmomente: {{ implode(', ', $format['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $format['seasons'] ?? []) ?: '—' }}</p>
            @endif
            @if(($opt['bilder'] ?? false) && ($format['hero'] ?? null))
                {{-- `.step-photos`/`.step-photo` hatten NIE eine CSS-Regel — das Bild rendert
                     sonst in Naturgroesse (1024 px) und laeuft aus der Spalte. Hier gilt die
                     Haus-Regel wie ueberall sonst im Bericht. --}}
                <div class="photo-strip"><span class="ps-item"><img src="{{ $format['hero'] }}" alt="{{ $format['name'] ?? '' }}"></span></div>
            @endif
        </section>

        @forelse($format['positionen'] as $pos)
            @if($pos['kind'] === 'edition')
                <h2>Edition · {{ $pos['concept']['name'] }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h2>
                @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt, 'eingebettet' => true])
            @elseif($pos['kind'] === 'header')
                <h2>{{ $pos['text'] }}</h2>
            @elseif($pos['kind'] === 'text')
                <p class="intro">{{ $pos['text'] }}</p>
            @elseif($pos['kind'] === 'spacer')
                <div style="height: {{ ['klein' => 8, 'mittel' => 16, 'gross' => 28][$pos['height'] ?? 'mittel'] ?? 16 }}px"></div>
            @endif
        @empty
            <p class="muted">Noch kein Aufbau — im Format-Editor Editionen einfügen.</p>
        @endforelse
    @elseif($recipe)
        @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $recipe, 'optionen' => $opt, 'istDokumentKopf' => true])
    @elseif($foodbook ?? null)
        {{-- #5a: Technischer Foodbook-Report — Kapitel × Positionen, jede über den GETEILTEN Concept-/
             Rezept-Körper (Filter LITERAL dieselben wie Concept/Format). Die Produktions-Kaskade lebt HIER. --}}
        <section>
            <h2>Foodbook-Übersicht</h2>
            {!! $gitter([
                $kachel('Name', $foodbook['name'] ?? null, 'wide'),
                $kachel('Kunde', $foodbook['customer'] ?? null),
                $kachel('Profil', $profile[$opt['profil'] ?? ''] ?? ($opt['profil'] ?? null)),
            ]) !!}
        </section>
        @forelse($foodbook['kapitel'] as $kap)
            @php $hTag = 'h' . min(4, 2 + (int) ($kap['depth'] ?? 0)); @endphp
            <{{ $hTag }} style="margin-left: {{ ($kap['depth'] ?? 0) * 12 }}px">{{ $kap['title'] }}</{{ $hTag }}>
            @forelse($kap['positionen'] as $pos)
                @if($pos['kind'] === 'concept')
                    <h3 style="margin-left: {{ (($kap['depth'] ?? 0) + 1) * 12 }}px">{{ $pos['concept']['name'] ?? '—' }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h3>
                    @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt, 'eingebettet' => true])
                @elseif($pos['kind'] === 'recipe')
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $pos['recipe'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'header')
                    <h4>{{ $pos['text'] }}</h4>
                @elseif($pos['kind'] === 'text')
                    <p class="intro">{{ $pos['text'] }}</p>
                @endif
            @empty
                <p class="muted">Keine Positionen in diesem Kapitel.</p>
            @endforelse
        @empty
            <p class="muted">Noch keine Kapitel — im Foodbook-Editor anlegen.</p>
        @endforelse
    @elseif($speisekarte ?? null)
        {{-- Technischer Speisekarte-Report — Rubriken × Positionen über den GETEILTEN Concept-/Rezept-Körper
             (Filter LITERAL dieselben wie Concept/Format/Foodbook). Die Produktions-Kaskade lebt HIER. --}}
        <section>
            <h2>Speisekarte-Übersicht</h2>
            {!! $gitter([
                $kachel('Name', $speisekarte['name'] ?? null, 'wide'),
                $kachel('Kunde', $speisekarte['customer'] ?? null),
                $kachel('Profil', $profile[$opt['profil'] ?? ''] ?? ($opt['profil'] ?? null)),
            ]) !!}
        </section>
        @forelse($speisekarte['rubriken'] as $rub)
            @php $hTag = 'h' . min(4, 2 + (int) ($rub['depth'] ?? 0)); @endphp
            <{{ $hTag }} style="margin-left: {{ ($rub['depth'] ?? 0) * 12 }}px">{{ $rub['title'] }}</{{ $hTag }}>
            @forelse($rub['positionen'] as $pos)
                @if($pos['kind'] === 'concept')
                    <h3 style="margin-left: {{ (($rub['depth'] ?? 0) + 1) * 12 }}px">{{ $pos['concept']['name'] ?? '—' }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h3>
                    @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt, 'eingebettet' => true])
                @elseif($pos['kind'] === 'recipe')
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $pos['recipe'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'header')
                    <h4>{{ $pos['text'] }}</h4>
                @elseif($pos['kind'] === 'text')
                    <p class="intro">{{ $pos['text'] }}</p>
                @endif
            @empty
                <p class="muted">Keine Positionen in dieser Rubrik.</p>
            @endforelse
        @empty
            <p class="muted">Noch keine Rubriken — im Speisekarte-Editor anlegen.</p>
        @endforelse
    @endif
</main>
<div class="band-bottom">
    <span class="bb-foot">{{ $footerText }}</span>
</div>
</body>
</html>
