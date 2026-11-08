{{-- Spec 94 Stufe 5 · Rahmen des Konfigurator-Links im PRO.KOPF-Aufbau, für jedes Design gleich (Farben nur über Tokens):
     klebende Schritt-Leiste oben (nur vorhandene Schritte) mit Gäste und Preis am Desktop, darunter ein kompakter Regler mit Preisstufe,
     klebende Preisleiste unten am Handy. Preise schreibt nur das Skript aus der eingefrorenen Preistabelle. --}}
@php
    $pakete = $content['pakete'] ?? [];
    $min = $pakete[0]['von'] ?? 1;
    $max = $pakete !== [] ? end($pakete)['bis'] : 1;
    $start = max($min, min($max, (int) ($content['pax_vorgabe'] ?? $min)));
    $zeitZuerst = ($content['reihenfolge'] ?? 'tageszeit_zuerst') === 'tageszeit_zuerst';
    $zeit = ($content['tageszeit_aktiv'] ?? false) ? ['tageszeit' => 'Zeitfenster'] : [];
    // Stufe 5b: Reihenfolge je Konfigurator, Standard Tageszeit zuerst
    $schritte = array_filter(($zeitZuerst ? $zeit + ['konzepte' => 'Konzept'] : ['konzepte' => 'Konzept'] + $zeit) + [
        'addons' => ! empty($content['addons']) ? 'Extras' : null,
        'getraenke' => ! empty($content['getraenke']) ? 'Getränke' : null,   // Spec 94c: nach den Extras (Dominique 08.11.2026, vorher davor)
        'anfrage' => 'Anfrage',
    ]);
    $startPaket = collect($pakete)->first(fn ($p) => $start >= $p['von'] && $start <= $p['bis']);
@endphp
<div class="pt-konfig-kopfleiste" data-konfig-kopfleiste data-konfig-reihenfolge="{{ $zeitZuerst ? 'tageszeit_zuerst' : 'konzept_zuerst' }}">
    <nav class="pt-konfig-schritte" aria-label="Schritte" data-konfig-schritte>
        @foreach($schritte as $schluessel => $label)
            <button type="button" class="pt-konfig-schritt-knopf" data-konfig-zu="{{ $schluessel }}" aria-current="{{ $loop->first ? 'step' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>
    @if($pakete !== [])
        <div class="pt-konfig-preisleiste pt-konfig-preisleiste--oben" data-konfig-preisleiste>
            @include('foodalchemist::presentation.blocks._konfigurator_preisleiste', ['min' => $min, 'max' => $max, 'start' => $start])
        </div>
    @endif
</div>

@if($pakete !== [])
    {{-- Kompakter Regler unter der Leiste; die Gästezahl selbst steht nur in der Preisleiste (oben am Desktop, unten am Handy),
         kein zweites Mal groß (Dominique 2026-11-03). --}}
    <section class="pt-wide pt-konfig-gaeste" id="konfig-gaeste" data-konfig-gaeste>
        <input type="range" min="{{ $min }}" max="{{ $max }}" value="{{ $start }}" class="pt-konfig-regler" aria-label="Gästezahl" data-konfig-pax-regler>
        <p class="pt-konfig-stufe">Preisstufe <span data-konfig-stufe>{{ $startPaket ? $startPaket['von'] . '–' . $startPaket['bis'] : '–' }}</span> Gäste</p>
    </section>

    <div class="pt-konfig-preisleiste pt-konfig-preisleiste--unten" data-konfig-preisleiste>
        @include('foodalchemist::presentation.blocks._konfigurator_preisleiste', ['min' => $min, 'max' => $max, 'start' => $start])
    </div>
@endif
