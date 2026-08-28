{{-- Flache Gericht-Liste (custom-Design-Baustein): alle Blöcke aller Sections ohne
     Kapitel-Überschriften. Nutzt denselben Block-Body wie chapter_loop. --}}
@foreach(($content['sections'] ?? []) as $s)
    @foreach(($s['blocks'] ?? []) as $b)
        @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
    @endforeach
@endforeach
