@php
    $pdf = $istPdf ?? false;
    $opt = $optionen ?? [];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
        'kalkulation' => 'Kalkulation',
        'voll' => 'Volle Kaskade',
    ];
    $brand = '#6d28d9';
    $footerText = 'Erstellt mit Food Alchemist';
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
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; background: {{ $pdf ? '#fff' : '#f3f4f6' }}; margin: 0; padding: 0; font-size: 10.5px; line-height: 1.42; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.15cm; left: -1.4cm; width: 21cm;' : '' }}
            height: 1.25cm; background: {{ $brand }}; color: #fff; padding: 0 1.4cm;
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.44cm;' : 'display: block; line-height: 1.25cm;' }} font-size: 9.5px; letter-spacing: .08em; text-transform: uppercase; opacity: .94; }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.5cm; left: -1.4cm; width: 21cm;' : '' }}
            height: 0.95cm; border-top: 2px solid {{ $brand }}; color: #9ca3af; font-size: 8.5px; padding: 0 1.4cm;
        }
        .band-bottom .bb-foot { display: block; line-height: 0.95cm; }
        .actions { background: #111827; color: #fff; padding: 12px 16px; margin: {{ $pdf ? '0' : '-2.15cm -1.4cm 20px' }}; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: {{ $brand }}; border-color: {{ $brand }}; }
        .actions .secondary a { color: #e5e7eb; }
        .simulation-control { margin-bottom: 9px; padding-bottom: 9px; border-bottom: 1px solid rgba(255,255,255,.16); }
        .simulation-control form { display: inline-block; margin-left: 6px; }
        .simulation-control input { width: 90px; border: 1px solid rgba(255,255,255,.3); border-radius: 4px; background: #1f2937; color: #fff; padding: 5px 7px; font: inherit; }
        .simulation-control button { border: 0; border-radius: 4px; background: {{ $brand }}; color: #fff; padding: 6px 10px; font: inherit; cursor: pointer; }
        header { margin-bottom: 10px; }
        .kicker { font-size: 9.5px; letter-spacing: .14em; text-transform: uppercase; color: #6b7280; }
        h1 { font-size: 23px; margin: 2px 0 3px; letter-spacing: -.02em; color: #111827; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 8px 0 9px; }
        h2 { font-size: 15px; margin: 16px 0 6px; border-top: 2px solid #111827; padding-top: 7px; page-break-after: avoid; }
        h3 { font-size: 13px; margin: 13px 0 6px; padding-top: 0; page-break-after: avoid; }
        h4 { font-size: 10px; margin: 11px 0 4px; color: #374151; text-transform: uppercase; letter-spacing: .06em; page-break-after: avoid; }
        h5 { font-size: 10.5px; margin: 10px 0 5px; color: #4b5563; }
        .muted { color: #6b7280; font-weight: normal; }
        .warn { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; padding: 5px 7px; }
        .intro { margin: 9px 0 13px; color: #374151; white-space: pre-line; }

        /* Meta-Kacheln: 3 Spalten statt 4 (mehr Platz je Wert, weniger Umbruch),
           knapper gesetzt. Leere Werte rendert der Rezept-Partial nicht mehr mit. */
        .grid { width: 100%; margin: 5px 0 8px; font-size: 0; }
        .grid > div { display: inline-block; width: 33.33%; border: 1px solid #e5e7eb; padding: 4px 7px; vertical-align: top; font-size: 10.5px; margin-right: -1px; margin-bottom: -1px; overflow-wrap: anywhere; }
        .grid > div.wide { width: 66.66%; }
        .grid > div.full { width: 100%; }
        .grid span { display: block; color: #6b7280; font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 1px; }

        table { width: 100%; border-collapse: collapse; margin: 5px 0 9px; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #f9fafb; color: #374151; font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; }
        td.num, th.num { text-align: right; }
        .sum-line td { border-top: 2px solid #9ca3af; font-weight: 700; background: #f9fafb; }
        .copy { white-space: pre-line; color: #374151; margin: 4px 0 6px; }
        .copy p, .intro p { margin: 0 0 3px; }
        p { margin: 0 0 5px; }

@include('foodalchemist::dokumente.partials.report-node-css')
        .slot { border: 1px solid #e5e7eb; padding: 7px 9px; margin: 7px 0; page-break-inside: avoid; }
        .badge { display: inline-block; background: #f3f4f6; padding: 2px 6px; font-size: 9.5px; color: #374151; }
        .sensorik-radar { display: table; width: 100%; margin: 7px 0 9px; page-break-inside: avoid; }
        .sensorik-radar-chart { display: table-cell; width: 44%; vertical-align: top; text-align: center; border: 1px solid #e5e7eb; padding: 7px; }
        .sensorik-radar-values { display: table-cell; width: 56%; vertical-align: top; padding-left: 9px; }
        .sensorik-radar-values table { margin-top: 0; }
        .order-simulation { page-break-before: auto; }
        .order-simulation .sum-row td { border-top: 2px solid #d1d5db; font-weight: 700; }
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
    <span class="bt-label">{{ $titel ?? 'Report' }} · {{ $name ?? '' }}</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
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
                @foreach(['preise' => 'Preise', 'lieferanten' => 'Lieferanten', 'steps' => 'Anleitung', 'bilder' => 'Bilder', 'deklaration' => 'Deklaration', 'naehrwerte' => 'Nährwerte', 'sensorik' => 'Sensorik', 'produktion' => 'Produktion', 'notizen' => 'Notizen', 'kaskade' => 'Kaskade'] as $key => $label)
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
    </header>

    @if($report ?? null)
        @php($kind = $report['kind'] ?? null)

        @if($kind === 'gp')
            @php($gp = $report['gp'])
            <section>
                <h2>Grundprodukt</h2>
                <div class="grid meta">
                    <div><span>ID</span>#{{ $gp['id'] }}</div>
                    <div><span>Status</span>{{ $gp['status'] ?? '—' }}</div>
                    <div><span>Warengruppe</span>{{ $gp['warengruppe'] ?? '—' }}</div>
                    <div><span>Sub-Kategorie</span>{{ $gp['sub_category'] ?? '—' }}</div>
                </div>
                @if($gp['lead_la'])
                    <h3>Lead-Lieferantenartikel</h3>
                    <div class="grid meta">
                        <div><span>Lieferant</span>{{ $gp['lead_la']['supplier'] ?? '—' }}</div>
                        <div><span>Artikel-Nr.</span>{{ $gp['lead_la']['article_number'] ?? '—' }}</div>
                        <div><span>Gebinde</span>{{ $gp['lead_la']['packaging_unit'] ?? '—' }}</div>
                        <div><span>Preis</span>{{ $money($gp['lead_la']['price'] ?? null) }}</div>
                        <div class="wide"><span>Bezeichnung</span>{{ $gp['lead_la']['designation'] ?? '—' }}</div>
                    </div>
                @endif
                @if(count($gp['tags'] ?? []))
                    <p class="muted">Tags: {{ collect($gp['tags'])->map(fn ($v, $k) => $k . '=' . ($v ? 'ja' : 'nein'))->implode(' · ') }}</p>
                @endif
                @if($opt['deklaration'] ?? false)
                    @include('foodalchemist::dokumente.partials.report-declaration', ['deklaration' => $gp['deklaration'] ?? []])
                @endif
                @if($opt['naehrwerte'] ?? false)
                    @include('foodalchemist::dokumente.partials.report-nutrition', ['naehrwerte' => $gp['naehrwerte'] ?? []])
                @endif
            </section>

            <section>
                <h2>Lieferantenartikel / Mapping</h2>
                <table>
                    <thead><tr><th>Lieferant</th><th>ArtNr</th><th>Bezeichnung</th><th>Review</th></tr></thead>
                    <tbody>
                        @forelse($gp['strukturen'] as $s)
                            <tr><td>{{ $s['supplier'] ?? '—' }}</td><td>{{ $s['article_number'] ?? '—' }}</td><td>{{ $s['designation'] ?? '—' }}</td><td>{{ $s['needs_review'] ? 'ja' : 'nein' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="muted">Keine Lieferantenartikel verknüpft.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section>
                <h2>Verwendung</h2>
                <table>
                    <thead><tr><th>Typ</th><th>Rezept/Gericht</th><th>Menge</th><th>Rohtext</th></tr></thead>
                    <tbody>
                        @forelse($gp['verwendung'] as $v)
                            <tr><td>{{ $v['typ'] }}</td><td>{{ $v['recipe'] ?? '—' }}</td><td>{{ $v['quantity'] ?? '—' }}</td><td>{{ $v['raw_text'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="muted">Keine Verwendung gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @elseif($kind === 'supplier')
            @php($supplier = $report['supplier'])
            <section>
                <h2>Lieferant</h2>
                <div class="grid meta">
                    <div><span>ID</span>#{{ $supplier['id'] }}</div>
                    <div><span>Status</span>{{ $supplier['status'] ?? ($supplier['is_inactive'] ? 'inaktiv' : 'aktiv') }}</div>
                    <div><span>Ort</span>{{ $supplier['city'] ?? '—' }}</div>
                    <div><span>Bestell-E-Mail</span>{{ $supplier['email_order'] ?? '—' }}</div>
                    <div class="wide"><span>Homepage</span>{{ $supplier['homepage'] ?? '—' }}</div>
                </div>
            </section>
            <section>
                <h2>Artikel</h2>
                <table>
                    <thead><tr><th>ArtNr</th><th>Bezeichnung</th><th>Gebinde</th><th>Preis</th><th>GP</th><th>Lead</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($supplier['items'] as $item)
                            <tr>
                                <td>{{ $item['article_number'] ?? '—' }}</td>
                                <td>{{ $item['designation'] ?? '—' }}</td>
                                <td>{{ trim(($item['packaging_unit'] ?? '') . ' ' . ($item['qty'] ?? '') . ' ' . ($item['unit_code'] ?? '')) ?: '—' }}</td>
                                <td>{{ $money($item['price'] ?? null) }}</td>
                                <td>{{ $item['gp'] ?? '—' }}</td>
                                <td>{{ $item['is_lead'] ? '★' : '—' }}</td>
                                <td>{{ $item['is_discontinued'] ? 'ausgelistet' : 'aktiv' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="muted">Keine Artikel.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @elseif($kind === 'geschirr')
            @php($supplier = $report['supplier'])
            <section>
                <h2>Geschirr-Lieferant</h2>
                <div class="grid meta">
                    <div><span>ID</span>#{{ $supplier['id'] }}</div>
                    <div><span>Status</span>{{ $supplier['is_inactive'] ? 'inaktiv' : 'aktiv' }}</div>
                    <div><span>Ort</span>{{ $supplier['city'] ?? '—' }}</div>
                    <div><span>E-Mail</span>{{ $supplier['email_order'] ?? '—' }}</div>
                    <div class="wide"><span>Homepage</span>{{ $supplier['homepage'] ?? '—' }}</div>
                </div>
            </section>
            <section>
                <h2>Geschirr-Artikel</h2>
                <table>
                    <thead><tr><th>ArtNr</th><th>Bezeichnung</th><th>Kategorie</th><th>Material</th><th>Maße</th><th>Leihpreis</th><th>Pfand</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($supplier['items'] as $item)
                            <tr>
                                <td>{{ $item['artikel_nr'] ?? '—' }}</td>
                                <td>{{ $item['label'] ?? '—' }}</td>
                                <td>{{ $item['category'] ?? '—' }}</td>
                                <td>{{ $item['material'] ?? '—' }}</td>
                                <td>{{ $item['masse'] ?? '—' }}</td>
                                <td>{{ $money($item['rental_price'] ?? null) }}{{ $item['unit'] ? ' / ' . $item['unit'] : '' }}</td>
                                <td>{{ $money($item['pfand'] ?? null) }}</td>
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
                <p class="muted">{{ $report['n_favoriten'] ?? 0 }} gepinnt · {{ count($report['items'] ?? []) }} Zeilen</p>
                <table>
                    <thead><tr><th>★</th><th>Rang</th><th>GP</th><th>Nutzung</th><th>Lead-LA</th><th>Preis</th><th>Score</th><th>Convenience</th></tr></thead>
                    <tbody>
                        @forelse($report['items'] as $item)
                            <tr>
                                <td>{{ $item['is_favorite'] ? '★' : '—' }}</td>
                                <td>{{ $item['favorite_rank'] ?? '—' }}</td>
                                <td>{{ $item['name'] }} <span class="muted">#{{ $item['gp_id'] }}</span></td>
                                <td>{{ $item['usage'] }}</td>
                                <td>{{ $item['has_lead_la'] ? 'ja' : 'nein' }}</td>
                                <td>{{ $item['has_price'] ? 'ja' : 'nein' }}</td>
                                <td>{{ number_format((float) $item['score'], 2, ',', '.') }}</td>
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
            <div class="grid meta">
                <div><span>Status</span>{{ $format['status'] ?? '—' }}</div>
                <div><span>Herkunft</span>{{ $format['origin'] ?? '—' }}</div>
                <div><span>Servierform</span>{{ $format['serving_form'] ?? '—' }}</div>
                <div><span>Eventtyp</span>{{ $format['event_type'] ?? '—' }}</div>
                @php($pr = $format['price_range'] ?? ['min' => null, 'max' => null])
                <div><span>Preisspanne p. P.</span>{{ ($pr['min'] ?? null) === null ? '—' : ($pr['min'] === $pr['max'] ? $money($pr['min']) : $money($pr['min']) . ' – ' . $money($pr['max'])) }}</div>
                <div class="wide"><span>Konsumentenbezeichnung</span>{{ $format['consumer_name'] ?? '—' }}</div>
                <div class="wide"><span>Claim</span>{{ $format['claim'] ?? '—' }}</div>
            </div>
            @if($format['story'] ?? null)<p class="intro">{{ $format['story'] }}</p>@endif
            @if(count($format['moments'] ?? []) || count($format['seasons'] ?? []))
                <p class="muted">Einsatzmomente: {{ implode(', ', $format['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $format['seasons'] ?? []) ?: '—' }}</p>
            @endif
            @if(($opt['bilder'] ?? false) && ($format['hero'] ?? null))
                <div class="step-photos"><span class="step-photo"><img src="{{ $format['hero'] }}" alt="{{ $format['name'] ?? '' }}"></span></div>
            @endif
        </section>

        @forelse($format['positionen'] as $pos)
            @if($pos['kind'] === 'edition')
                <h2>Edition · {{ $pos['concept']['name'] }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h2>
                @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt])
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
            <div class="grid meta">
                <div class="wide"><span>Name</span>{{ $foodbook['name'] ?? '—' }}</div>
                <div><span>Kunde</span>{{ $foodbook['customer'] ?? '—' }}</div>
                <div><span>Profil</span>{{ $opt['profil'] ?? '—' }}</div>
            </div>
        </section>
        @forelse($foodbook['kapitel'] as $kap)
            @php($hTag = 'h' . min(4, 2 + (int) ($kap['depth'] ?? 0)))
            <{{ $hTag }} style="margin-left: {{ ($kap['depth'] ?? 0) * 12 }}px">{{ $kap['title'] }}</{{ $hTag }}>
            @forelse($kap['positionen'] as $pos)
                @if($pos['kind'] === 'concept')
                    <h3 style="margin-left: {{ (($kap['depth'] ?? 0) + 1) * 12 }}px">{{ $pos['concept']['name'] ?? '—' }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h3>
                    @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'recipe')
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $pos['recipe'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'header')
                    <h4>{{ $pos['text'] }}</h4>
                @elseif($pos['kind'] === 'text')
                    <p class="intro">{{ $pos['text'] }}</p>
                @endif
            @empty
                <p class="muted">— leer —</p>
            @endforelse
        @empty
            <p class="muted">Noch keine Kapitel — im Foodbook-Editor anlegen.</p>
        @endforelse
    @elseif($speisekarte ?? null)
        {{-- Technischer Speisekarte-Report — Rubriken × Positionen über den GETEILTEN Concept-/Rezept-Körper
             (Filter LITERAL dieselben wie Concept/Format/Foodbook). Die Produktions-Kaskade lebt HIER. --}}
        <section>
            <h2>Speisekarte-Übersicht</h2>
            <div class="grid meta">
                <div class="wide"><span>Name</span>{{ $speisekarte['name'] ?? '—' }}</div>
                <div><span>Kunde</span>{{ $speisekarte['customer'] ?? '—' }}</div>
                <div><span>Profil</span>{{ $opt['profil'] ?? '—' }}</div>
            </div>
        </section>
        @forelse($speisekarte['rubriken'] as $rub)
            @php($hTag = 'h' . min(4, 2 + (int) ($rub['depth'] ?? 0)))
            <{{ $hTag }} style="margin-left: {{ ($rub['depth'] ?? 0) * 12 }}px">{{ $rub['title'] }}</{{ $hTag }}>
            @forelse($rub['positionen'] as $pos)
                @if($pos['kind'] === 'concept')
                    <h3 style="margin-left: {{ (($rub['depth'] ?? 0) + 1) * 12 }}px">{{ $pos['concept']['name'] ?? '—' }}@if($pos['concept']['consumer_name'] ?? null)<span class="muted"> · {{ $pos['concept']['consumer_name'] }}</span>@endif</h3>
                    @include('foodalchemist::dokumente.partials.report-concept-body', ['concept' => $pos['concept'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'recipe')
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $pos['recipe'], 'optionen' => $opt])
                @elseif($pos['kind'] === 'header')
                    <h4>{{ $pos['text'] }}</h4>
                @elseif($pos['kind'] === 'text')
                    <p class="intro">{{ $pos['text'] }}</p>
                @endif
            @empty
                <p class="muted">— leer —</p>
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
