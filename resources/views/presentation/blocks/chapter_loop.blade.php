{{-- Kapitel-Schleife — Magazin-Rhythmus: Kicker + große Section-Überschrift + Hinführung + Menü-Zeilen. --}}
@php $sections = $content['sections'] ?? []; @endphp
@foreach($sections as $s)
    <section class="pt-section pt-depth-{{ $s['depth'] ?? 0 }} pt-reveal" @if(!empty($s['anker'])) id="{{ $s['anker'] }}" @endif>
        <div class="pt-measure">
            <div class="pt-section-head">
                <div class="pt-kicker">{{ ($s['depth'] ?? 0) > 0 ? 'Abschnitt' : 'Kapitel' }}</div>
                <h2 class="pt-section-title">{{ $s['title'] ?? '' }}</h2>
                @if(!empty($s['text']))<p class="pt-section-text">{{ $s['text'] }}</p>@endif
            </div>
            @php
                $bandImgs = array_values(array_filter($s['images'] ?? [], fn ($gi) => !empty($gi['url'])));
                if ($bandImgs === [] && !empty($s['image']['url'])) { $bandImgs = [$s['image']]; }
            @endphp
            @if(count($bandImgs) > 1)
                <div class="pt-section-gallery">
                    @foreach(array_slice($bandImgs, 0, 3) as $gi)
                        <img class="pt-section-img pt-section-img--multi" src="{{ $gi['url'] }}" alt="">
                    @endforeach
                </div>
            @elseif(count($bandImgs) === 1)
                <img class="pt-section-img" src="{{ $bandImgs[0]['url'] }}" alt="">
            @endif
            @foreach(($s['blocks'] ?? []) as $b)
                @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
            @endforeach
        </div>
    </section>
@endforeach
