{{-- Spec 94 · Konfigurator-Schritt Anfrage, Teil 1: Zusammenfassung der Auswahl mit Paket und Preis. Gästezahl steuert der Kopf
     (große Zahl, Regler, Preisleisten); das Skript liest nur aus der Preistabelle. --}}
<section class="pt-wide pt-konfig-schritt pt-konfig-summe" id="konfig-summe" data-konfig-schritt="summe" data-konfig-gruppe="anfrage">
    <p class="pt-kicker pt-konfig-nr"></p>
    <h2>{{ $style['titel'] ?? 'Eure Zusammenstellung' }}</h2>
    @if(($content['pakete'] ?? []) === [])
        <p class="pt-section-text">Für diesen Konfigurator sind noch keine Preise hinterlegt.</p>
    @else
        <ul class="pt-konfig-auswahl" data-konfig-auswahl></ul>
        <p class="pt-konfig-paket" data-konfig-paket></p>
        <p class="pt-konfig-preis"><strong data-konfig-pp>—</strong> <small>pro Person, all-in netto</small></p>
        {{-- Spec 94b §5: Aufteilung des Preises pro Person nach Gruppen (Speisen Tag/Abend, Kategorien, Extras), ohne EK --}}
        <ul class="pt-konfig-aufteilung" data-konfig-aufteilung hidden aria-label="Aufteilung pro Person"></ul>
        @if($style['show_gesamt'] ?? true)<p class="pt-konfig-gesamt">Gesamt <strong data-konfig-gesamt>—</strong> netto</p>@endif
        {{-- Spec 94b §4: Leistungen nach Aufwand — klar als Schätzung, nicht im Preis pro Person und nicht im Gesamtpreis --}}
        <div class="pt-konfig-zzgl" data-konfig-zzgl hidden>
            <p class="pt-kicker" data-konfig-zzgl-titel>zzgl. nach Aufwand</p>
            <ul data-konfig-zzgl-liste></ul>
            <p class="pt-konfig-legende">Geschätzt für diese Gästezahl, abgerechnet wird nach tatsächlichem Aufwand bzw. Verbrauch.</p>
        </div>
        <p class="pt-section-text" data-konfig-indikativ hidden>Der Preis ist indikativ und wird im Angebot bestätigt.</p>
        <p class="pt-section-text" data-konfig-hinweis>Bitte ein Konzept wählen.</p>
        <div class="pt-konfig-wahl">
            <button type="button" class="pt-konfig-chip" data-konfig-teilen>Konfiguration teilen</button>
            <button type="button" class="pt-konfig-chip" onclick="window.print()">Drucken / PDF</button>
        </div>
        @if(! empty($meta['gueltig_bis']))<p class="pt-hero-meta">Preise gültig bis {{ $meta['gueltig_bis'] }}</p>@endif
    @endif
</section>
