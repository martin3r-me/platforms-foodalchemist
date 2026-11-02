{{-- Spec 94 Stufe 5 · Rahmen des Konfigurator-Links im PRO.KOPF-Aufbau, für jedes Design gleich (Farben nur über Tokens):
     klebende Schritt-Leiste oben (nur vorhandene Schritte) mit Gäste und Preis am Desktop, große Gästezahl mit Preisstufe,
     klebende Preisleiste unten am Handy. Preise schreibt nur das Skript aus der eingefrorenen Preistabelle. --}}
@php
    $pakete = $content['pakete'] ?? [];
    $min = $pakete[0]['von'] ?? 1;
    $max = $pakete !== [] ? end($pakete)['bis'] : 1;
    $start = max($min, min($max, (int) ($content['pax_vorgabe'] ?? $min)));
    $schritte = array_filter([
        'konzepte' => 'Konzept',
        'tageszeit' => ($content['tageszeit_aktiv'] ?? false) ? 'Zeitfenster' : null,
        'addons' => ! empty($content['addons']) ? 'Extras' : null,
        'anfrage' => 'Anfrage',
    ]);
    $startPaket = collect($pakete)->first(fn ($p) => $start >= $p['von'] && $start <= $p['bis']);
@endphp
<div class="pt-konfig-kopfleiste" data-konfig-kopfleiste>
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
    <section class="pt-wide pt-konfig-gaeste" id="konfig-gaeste" data-konfig-gaeste>
        <p class="pt-kicker">Für</p>
        <p class="pt-konfig-gaeste-zahl"><span data-konfig-pax-anzeige>{{ $start }}</span> <span class="pt-konfig-gaeste-einheit">Gäste</span></p>
        <input type="range" min="{{ $min }}" max="{{ $max }}" value="{{ $start }}" class="pt-konfig-regler" aria-label="Gästezahl" data-konfig-pax-regler>
        <p class="pt-kicker pt-konfig-stufe">Preisstufe · <span data-konfig-stufe>{{ $startPaket ? $startPaket['von'] . '–' . $startPaket['bis'] : '–' }}</span></p>
    </section>

    <div class="pt-konfig-preisleiste pt-konfig-preisleiste--unten" data-konfig-preisleiste>
        @include('foodalchemist::presentation.blocks._konfigurator_preisleiste', ['min' => $min, 'max' => $max, 'start' => $start])
    </div>
@endif
