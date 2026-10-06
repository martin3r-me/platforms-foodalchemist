@php
    // Geteilter Concept-Report-Körper (Übersicht + Positionen): einmal für den Concept-Zweig,
    // einmal je Edition im Format-Zweig — so ist der Filter-Satz (opt) LITERAL derselbe.
    $opt = $optionen ?? [];
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
    $simulationRequirements = collect($concept['order_simulation']['requirements'] ?? [])->keyBy('recipe_id')->all();

    /* Druck-Muster (2026-10-05): Küchensprache statt Rohwerte — Status/Niveau über die
       Labels der App (Concepter-Browser), leere Kacheln fallen weg wie im Rezept-Partial. */
    $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'];
    $niveauLabel = ['klassisch' => 'Klassisch', 'gehoben' => 'Gehoben', 'haute' => 'Haute Cuisine', 'haute_cuisine' => 'Haute Cuisine'];
    $typLabel = ['paket' => 'Paket', 'gericht' => 'Gericht', 'leer' => 'Noch leer'];
    $kachel = function (string $label, $wert, string $klasse = '') {
        if ($wert === null || $wert === '' || $wert === '—') {
            return '';
        }

        return '<div' . ($klasse ? ' class="' . $klasse . '"' : '') . '><span>'
            . e($label) . '</span>' . e($wert) . '</div>';
    };
    $kacheln = implode('', array_filter([
        $kachel('Status', ($concept['status'] ?? null) ? ($statusLabel[$concept['status']] ?? $concept['status']) : null),
        $kachel('Anlass', $concept['occasion'] ?? null),
        $kachel('Niveau', ($concept['level'] ?? null) ? ($niveauLabel[$concept['level']] ?? $concept['level']) : null),
        $kachel('Kategorie', $concept['category'] ?? null),
        $kachel('Preis / Person', ($concept['price_per_person_cache'] ?? null) !== null ? $money($concept['price_per_person_cache']) : null),
        $kachel('Wareneinsatz / Person', ($concept['ek_per_person_cache'] ?? null) !== null ? $money($concept['ek_per_person_cache']) : null),
        $kachel('Arbeitszeit', ($concept['work_time_min_cache'] ?? null) !== null ? $concept['work_time_min_cache'] . ' min' : null),
        $kachel('Servierform', $concept['serving_form'] ?? null),
    ]));
@endphp
{{-- $eingebettet: Concept steht unter einer fremden Überschrift (Format-Edition, Foodbook-Kapitel,
     Speisekarte-Rubrik), die den Namen schon trägt — dann keine h2-Ebenen darunter. --}}
@php $eingebettet = (bool) ($eingebettet ?? false); @endphp
<section>
    @unless($eingebettet)<h2>Concept-Übersicht</h2>@endunless
    @if($kacheln !== '')<div class="grid meta keep">{!! $kacheln !!}</div>@endif
    @if($concept['description'] ?? null)<p class="intro">{{ $concept['description'] }}</p>@endif
    @if(count($concept['moments'] ?? []) || count($concept['seasons'] ?? []))
        <p class="muted">Einsatzmomente: {{ implode(', ', $concept['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $concept['seasons'] ?? []) ?: '—' }}</p>
    @endif
</section>

@if(($opt['simulation'] ?? false) && ($concept['order_simulation'] ?? null))
    @include('foodalchemist::dokumente.partials.report-order-simulation', [
        'simulation' => $concept['order_simulation'],
    ])
@endif

<section>
    @if($eingebettet)<h4>Positionen</h4>@else<h2>Positionen</h2>@endif
    @forelse($concept['slots'] as $slot)
        {{-- Positions-Kopf im Stil des Rezept-Knotens (Adress-Badge + Chips über dem Titel).
             Bewusst KEIN umschließender Kasten mehr: der hatte page-break-inside: avoid und
             schob jede Position mit ihren Gerichten als Block auf die nächste Seite — im
             Concept-Report stand die Übersicht allein auf Seite 1. --}}
        <div class="node-head">
            <div class="node-kicker">
                <span class="addr">Pos. {{ $loop->iteration }}</span>
                {{-- „Gericht" trägt das Gericht selbst als Chip — hier nur Paket bzw. leer. --}}
                @if($slot['type'] !== 'gericht')<span class="chip {{ $slot['type'] === 'paket' ? 'chip-base' : '' }}">{{ $typLabel[$slot['type']] ?? $slot['type'] }}</span>@endif
            </div>
            @php $posName = $slot['role'] ?: ($slot['title'] ?: ($slot['package']['name'] ?? null)); @endphp
            @if($posName)<h3 class="node-title">{{ $posName }}@if($slot['role'] && $slot['title'])<span class="muted"> · {{ $slot['title'] }}</span>@endif</h3>@endif
            @if($slot['package'])
                <div class="kennzahlen">Paket {{ $slot['package']['name'] }} · Preis {{ $money($slot['package']['price_per_person']) }} p. P. · Wareneinsatz {{ $money($slot['package']['ek_per_person'], 2) }} p. P.</div>
            @endif
        </div>
        @forelse($slot['gerichte'] as $g)
            @if($g['paket'] ?? null)<p class="from-line">Aus Paket <strong>{{ $g['paket'] }}</strong>{{ $g['menge'] ? ' · Menge ' . $g['menge'] : '' }}</p>@endif
            @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $g['recipe'], 'optionen' => $opt, 'simulationRequirements' => $simulationRequirements])
        @empty
            <p class="muted">Noch kein Gericht zugeordnet.</p>
        @endforelse
    @empty
        <p class="muted">Keine Positionen.</p>
    @endforelse
</section>
