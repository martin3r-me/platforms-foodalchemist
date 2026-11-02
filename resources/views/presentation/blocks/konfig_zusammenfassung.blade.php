{{-- Spec 94 · Konfigurator-Schritt 4: Gäste, Paket, Preis pro Person und gesamt. Das Skript liest nur aus der Preistabelle. --}}
@php
    $pakete = $content['pakete'] ?? [];
    $min = $pakete[0]['von'] ?? 1;
    $max = $pakete !== [] ? end($pakete)['bis'] : 1;
    $start = max($min, min($max, (int) ($content['pax_vorgabe'] ?? $min)));
@endphp
<section class="pt-measure pt-konfig-schritt pt-konfig-summe" id="konfig-summe" data-konfig-schritt="summe">
    <p class="pt-kicker">Schritt 4</p>
    <h2>{{ $style['titel'] ?? 'Ihr Preis' }}</h2>
    @if($pakete === [])
        <p class="pt-section-text">Für diesen Konfigurator sind noch keine Preise hinterlegt.</p>
    @else
        <label class="pt-konfig-pax">
            <span>Gäste</span>
            <input type="range" min="{{ $min }}" max="{{ $max }}" value="{{ $start }}" data-konfig-pax-regler>
            <input type="number" min="{{ $min }}" max="{{ $max }}" value="{{ $start }}" data-konfig-pax>
        </label>
        <p class="pt-konfig-paket" data-konfig-paket></p>
        <p class="pt-konfig-preis"><span data-konfig-pp>–</span> <small>pro Person, netto</small></p>
        @if($style['show_gesamt'] ?? true)<p class="pt-konfig-gesamt">Gesamt <span data-konfig-gesamt>–</span> netto</p>@endif
        <p class="pt-section-text" data-konfig-indikativ hidden>Der Preis ist indikativ und wird im Angebot bestätigt.</p>
        <p class="pt-section-text" data-konfig-hinweis>Bitte ein Konzept wählen.</p>
        <div class="pt-konfig-wahl">
            <button type="button" class="pt-konfig-chip" data-konfig-teilen>Konfiguration teilen</button>
            <button type="button" class="pt-konfig-chip" onclick="window.print()">Drucken / PDF</button>
        </div>
        @if(! empty($meta['gueltig_bis']))<p class="pt-hero-meta">Preise gültig bis {{ $meta['gueltig_bis'] }}</p>@endif
    @endif
</section>
