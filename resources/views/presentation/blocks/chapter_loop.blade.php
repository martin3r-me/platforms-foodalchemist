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
            @if(!empty($s['image']['url']))<img class="pt-section-img" src="{{ $s['image']['url'] }}" alt="">@endif
            @foreach(($s['blocks'] ?? []) as $b)
                @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
            @endforeach
        </div>
    </section>
@endforeach
