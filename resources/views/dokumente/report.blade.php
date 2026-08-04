@php
    $pdf = $istPdf ?? false;
    $opt = $optionen ?? [];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
        'kalkulation' => 'Kalkulation',
        'voll' => 'Volle Kaskade',
    ];
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $titel ?? 'Report' }} – {{ $name ?? '' }}</title>
    <style>
        @page { margin: {{ $pdf ? '1.6cm 1.25cm' : '0' }}; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; background: {{ $pdf ? '#fff' : '#f3f4f6' }}; margin: 0; font-size: 11px; line-height: 1.45; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '32px' }}; }
        .actions { background: #111827; color: #fff; padding: 12px 16px; margin: {{ $pdf ? '0' : '-32px -32px 24px' }}; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: #7c3aed; border-color: #7c3aed; }
        .actions .secondary a { color: #e5e7eb; }
        h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: -.02em; }
        h2 { font-size: 18px; margin: 24px 0 8px; border-top: 2px solid #111827; padding-top: 10px; }
        h3 { font-size: 15px; margin: 18px 0 8px; border-top: 1px solid #d1d5db; padding-top: 8px; }
        h4 { font-size: 12px; margin: 14px 0 6px; color: #374151; text-transform: uppercase; letter-spacing: .05em; }
        h5 { font-size: 11px; margin: 12px 0 6px; color: #4b5563; }
        .muted { color: #6b7280; font-weight: normal; }
        .warn { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; padding: 6px 8px; border-radius: 6px; }
        .intro { margin: 12px 0 18px; color: #374151; white-space: pre-line; }
        .grid { display: table; width: 100%; border-collapse: collapse; margin: 8px 0 10px; }
        .grid > div { display: table-cell; width: 25%; border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        .grid > div.wide { width: 50%; }
        .grid span { display: block; color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; color: #374151; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; }
        .copy { white-space: pre-line; color: #374151; }
        .step { margin: 4px 0; }
        .recipe-node.depth-1 { margin-left: {{ $pdf ? '0' : '12px' }}; }
        .recipe-node.depth-2, .recipe-node.depth-3, .recipe-node.depth-4 { margin-left: {{ $pdf ? '0' : '20px' }}; }
        .slot { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; margin: 8px 0; page-break-inside: avoid; }
        .badge { display: inline-block; border-radius: 999px; background: #f3f4f6; padding: 2px 7px; font-size: 10px; color: #374151; }
        @media print { .actions { display: none; } body { background: #fff; } .doc { padding: 0; } }
    </style>
</head>
<body>
<main class="doc">
    @unless($pdf)
        <div class="actions">
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
                @foreach(['preise' => 'Preise', 'lieferanten' => 'Lieferanten', 'steps' => 'Steps', 'sensorik' => 'Sensorik', 'produktion' => 'Produktion', 'notizen' => 'Notizen', 'kaskade' => 'Kaskade'] as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery([$key => ($opt[$key] ?? false) ? 0 : 1, 'pdf' => null]) }}" class="{{ ($opt[$key] ?? false) ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    @endunless

    <header>
        <div class="muted">{{ $titel ?? 'Report' }} · {{ now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $name ?? 'Report' }}</h1>
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
        <section>
            <h2>Concept-Übersicht</h2>
            <div class="grid meta">
                <div><span>Status</span>{{ $concept['status'] ?? '—' }}</div>
                <div><span>Anlass</span>{{ $concept['occasion'] ?? '—' }}</div>
                <div><span>Niveau</span>{{ $concept['level'] ?? '—' }}</div>
                <div><span>Kategorie</span>{{ $concept['category'] ?? '—' }}</div>
                <div><span>Preis/Person</span>{{ $money($concept['price_per_person_cache'] ?? null) }}</div>
                <div><span>EK/Person</span>{{ $money($concept['ek_per_person_cache'] ?? null) }}</div>
                <div><span>Arbeitszeit</span>{{ ($concept['work_time_min_cache'] ?? null) !== null ? $concept['work_time_min_cache'] . ' min' : '—' }}</div>
                <div><span>Servierform</span>{{ $concept['serving_form'] ?? '—' }}</div>
            </div>
            @if($concept['description'] ?? null)<p class="intro">{{ $concept['description'] }}</p>@endif
            @if(count($concept['moments'] ?? []) || count($concept['seasons'] ?? []))
                <p class="muted">Einsatzmomente: {{ implode(', ', $concept['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $concept['seasons'] ?? []) ?: '—' }}</p>
            @endif
        </section>

        <section>
            <h2>Slots</h2>
            @forelse($concept['slots'] as $slot)
                <div class="slot">
                    <h3>{{ $slot['role'] ?: 'Slot' }} @if($slot['title'])<span class="muted">· {{ $slot['title'] }}</span>@endif <span class="badge">{{ $slot['type'] }}</span></h3>
                    @if($slot['package'])
                        <p class="muted">Paket: {{ $slot['package']['name'] }} · Preis {{ $money($slot['package']['price_per_person']) }} p. P. · EK {{ $money($slot['package']['ek_per_person'], 2) }} p. P.</p>
                    @endif
                    @forelse($slot['gerichte'] as $g)
                        @if($g['paket'] ?? null)<p class="muted">Aus Paket {{ $g['paket'] }}{{ $g['menge'] ? ' · ' . $g['menge'] : '' }}</p>@endif
                        @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $g['recipe'], 'optionen' => $opt])
                    @empty
                        <p class="muted">Leer.</p>
                    @endforelse
                </div>
            @empty
                <p class="muted">Keine Slots.</p>
            @endforelse
        </section>
    @elseif($recipe)
        @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $recipe, 'optionen' => $opt])
    @endif
</main>
</body>
</html>
