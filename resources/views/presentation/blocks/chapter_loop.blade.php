{{-- Kapitel-Schleife — Magazin-Rhythmus in einem BREITEN Raster: Kopf (+ optional Bild
     nebeneinander), danach die Menü-Zeilen ein- oder zweispaltig. Bricht die zentrierte
     Ein-Spalten-Optik auf. --}}
@php
    $sections = $content['sections'] ?? [];
    // Kapitelbild-Darstellung (2026-11-13): fotoband = Bildband 16:7 beschnitten (bisher), ganz = ganzes Bild in
    // begrenzter Höhe an der linken Textkante, auto (Default) = ganz bei transparenten Bildern (Logos), sonst Fotoband.
    $bildArt = in_array($style['chapter_image_fit'] ?? 'auto', ['fotoband', 'ganz'], true) ? $style['chapter_image_fit'] : 'auto';
    $ganz = fn (array $bild) => $bildArt === 'ganz' || ($bildArt === 'auto' && ! empty($bild['transparent']));
    $zeigeLogo = (bool) ($style['show_chapter_logo'] ?? true);
    $logoOben = ($style['chapter_logo_position'] ?? 'unten') === 'oben';
@endphp
@foreach($sections as $s)
    @php
        $depth = (int) ($s['depth'] ?? 0);
        $secId = ! empty($s['anker']) ? $s['anker'] : 'pt-sec-' . $loop->index;
        $bandImgs = array_values(array_filter($s['images'] ?? [], fn ($gi) => ! empty($gi['url'])));
        if ($bandImgs === [] && ! empty($s['image']['url'])) { $bandImgs = [$s['image']]; }
        // Kapitel-Band-Bilder abschaltbar (volle Kontrolle im Builder) — Default an.
        if (! ($style['show_chapter_image'] ?? true)) { $bandImgs = []; }
        $cols = min(max((int) ($style['dish_columns'] ?? 1), 1), 2);
        // Split-Kopf: genau ein Bild + Hauptkapitel → Kopf & Bild nebeneinander (asymmetrisch).
        $split = $depth === 0 && count($bandImgs) === 1;
        // Kicker form-abhängig: Speisekarte = à la carte, kein Label. Speiseplan hat keine Sektionen.
        $ptType = $snap['type'] ?? 'foodbook';
        $showKicker = ($style['show_kicker'] ?? true) && ! in_array($ptType, ['speisekarte', 'speiseplan'], true);
        // Kicker-Wort = Freitext-Override aus dem Design (leer = Default „Kapitel" / „Abschnitt").
        // Für Angebote tippt man z. B. „Leistung" — keine typ-abhängige Verdrahtung mehr.
        $kickerHaupt = trim((string) ($style['kicker_haupt'] ?? '')) ?: 'Kapitel';
        $kickerUnter = trim((string) ($style['kicker_unter'] ?? '')) ?: 'Abschnitt';
        $kicker = $depth > 0 ? $kickerUnter : $kickerHaupt;
        $logoUrl = $zeigeLogo ? ($s['logo']['url'] ?? null) : null;
    @endphp
    <section id="{{ $secId }}" class="pt-section pt-depth-{{ $depth }} pt-reveal" data-pt-section data-pt-title="{{ $s['title'] ?? '' }}">
        <div class="pt-wide">
            <div class="pt-section-grid {{ $split ? 'pt-section-grid--split' : '' }}">
                <div class="pt-section-head">
                    @if($logoUrl && $logoOben)<img class="pt-section-logo pt-section-logo--oben" src="{{ $logoUrl }}" alt="">@endif
                    @if($showKicker)<div class="pt-kicker">{{ $kicker }}</div>@endif
                    <h2 class="pt-section-title">{{ $s['title'] ?? '' }}</h2>
                    @if(!empty($s['text']))<p class="pt-section-text">{{ $s['text'] }}</p>@endif
                </div>
                @if($split)
                    <figure class="pt-section-aside"><img class="pt-section-img pt-zoomable {{ $ganz($bandImgs[0]) ? 'pt-section-img--ganz' : '' }}" src="{{ $bandImgs[0]['url'] }}" alt=""></figure>
                @endif
            </div>

            @php $bandStyle = ($tokens['band_style'] ?? 'grid'); @endphp
            @if(!$split && count($bandImgs) > 1 && $bandStyle === 'rondell')
                <div class="pt-rondell" data-pt-rondell>
                    <div class="pt-rondell-track">
                        @foreach(array_slice($bandImgs, 0, 6) as $gi)
                            <img class="pt-rondell-img pt-zoomable {{ $ganz($gi) ? 'pt-rondell-img--ganz' : '' }}" src="{{ $gi['url'] }}" alt="">
                        @endforeach
                    </div>
                    <button type="button" class="pt-rondell-btn pt-rondell-prev" data-pt-rondell-prev aria-label="zurück">‹</button>
                    <button type="button" class="pt-rondell-btn pt-rondell-next" data-pt-rondell-next aria-label="weiter">›</button>
                </div>
            @elseif(!$split && count($bandImgs) > 1)
                <div class="pt-section-gallery" data-count="{{ min(count($bandImgs), 6) }}">
                    @foreach(array_slice($bandImgs, 0, 6) as $gi)
                        <img class="pt-section-img pt-section-img--multi pt-zoomable {{ $ganz($gi) ? 'pt-section-img--ganz' : '' }}" src="{{ $gi['url'] }}" alt="">
                    @endforeach
                </div>
            @elseif(!$split && count($bandImgs) === 1)
                <img class="pt-section-img pt-zoomable {{ $ganz($bandImgs[0]) ? 'pt-section-img--ganz' : '' }}" src="{{ $bandImgs[0]['url'] }}" alt="">
            @endif

            <div class="pt-blocks pt-cols-{{ $cols }}">
                @foreach(($s['blocks'] ?? []) as $b)
                    @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
                @endforeach
            </div>
            @if($logoUrl && ! $logoOben)<img class="pt-section-logo pt-section-logo--unten" src="{{ $logoUrl }}" alt="">@endif
        </div>
    </section>
@endforeach
