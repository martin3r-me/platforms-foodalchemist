{{-- Kapitel-Schleife: iteriert die (sanitisierten) Content-Sections und rendert je
     Kapitel Titel/Hinführung + seine Blöcke über den geteilten Block-Body. --}}
@foreach(($content['sections'] ?? []) as $s)
    <section class="pt-section pt-depth-{{ (int) ($s['depth'] ?? 0) }}" id="{{ $s['anker'] ?? '' }}">
        @if(!empty($s['title']))
            <h2 class="pt-section-title">{{ $s['title'] }}</h2>
        @endif
        @if(!empty($s['text']))
            <p class="pt-section-text">{{ $s['text'] }}</p>
        @endif
        @foreach(($s['blocks'] ?? []) as $b)
            @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
        @endforeach
    </section>
@endforeach
