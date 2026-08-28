{{-- Gericht-Liste (Struktur-Builder, freistehend) — alle Zeilen flach, ohne Kapitel-Köpfe. --}}
@php $sections = $content['sections'] ?? []; @endphp
@if(!empty($sections))
    <section class="pt-section pt-reveal">
        <div class="pt-measure">
            @foreach($sections as $s)
                @foreach(($s['blocks'] ?? []) as $b)
                    @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
                @endforeach
            @endforeach
        </div>
    </section>
@endif
