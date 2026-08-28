{{-- Kapitel-Schleife — Magazin-Rhythmus in einem BREITEN Raster: Kopf (+ optional Bild
     nebeneinander), danach die Menü-Zeilen ein- oder zweispaltig. Bricht die zentrierte
     Ein-Spalten-Optik auf. --}}
@php $sections = $content['sections'] ?? []; @endphp
@foreach($sections as $s)
    @php
        $depth = (int) ($s['depth'] ?? 0);
        $secId = ! empty($s['anker']) ? $s['anker'] : 'pt-sec-' . $loop->index;
        $bandImgs = array_values(array_filter($s['images'] ?? [], fn ($gi) => ! empty($gi['url'])));
        if ($bandImgs === [] && ! empty($s['image']['url'])) { $bandImgs = [$s['image']]; }
        $cols = min(max((int) ($style['dish_columns'] ?? 1), 1), 2);
        // Split-Kopf: genau ein Bild + Hauptkapitel → Kopf & Bild nebeneinander (asymmetrisch).
        $split = $depth === 0 && count($bandImgs) === 1;
        // Kicker form-abhängig: Speisekarte = à la carte, kein „Kapitel"-Label. Speiseplan hat keine Sektionen.
        $ptType = $snap['type'] ?? 'foodbook';
        $showKicker = ($style['show_kicker'] ?? true) && $ptType !== 'speisekarte';
        $kicker = $depth > 0 ? 'Abschnitt' : 'Kapitel';
    @endphp
    <section id="{{ $secId }}" class="pt-section pt-depth-{{ $depth }} pt-reveal" data-pt-section data-pt-title="{{ $s['title'] ?? '' }}">
        <div class="pt-wide">
            <div class="pt-section-grid {{ $split ? 'pt-section-grid--split' : '' }}">
                <div class="pt-section-head">
                    @if($showKicker)<div class="pt-kicker">{{ $kicker }}</div>@endif
                    <h2 class="pt-section-title">{{ $s['title'] ?? '' }}</h2>
                    @if(!empty($s['text']))<p class="pt-section-text">{{ $s['text'] }}</p>@endif
                </div>
                @if($split)
                    <figure class="pt-section-aside"><img class="pt-section-img pt-zoomable" src="{{ $bandImgs[0]['url'] }}" alt=""></figure>
                @endif
            </div>

            @if(!$split && count($bandImgs) > 1)
                <div class="pt-section-gallery">
                    @foreach(array_slice($bandImgs, 0, 3) as $gi)
                        <img class="pt-section-img pt-section-img--multi pt-zoomable" src="{{ $gi['url'] }}" alt="">
                    @endforeach
                </div>
            @elseif(!$split && count($bandImgs) === 1)
                <img class="pt-section-img pt-zoomable" src="{{ $bandImgs[0]['url'] }}" alt="">
            @endif

            <div class="pt-blocks pt-cols-{{ $cols }}">
                @foreach(($s['blocks'] ?? []) as $b)
                    @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
                @endforeach
            </div>
        </div>
    </section>
@endforeach
