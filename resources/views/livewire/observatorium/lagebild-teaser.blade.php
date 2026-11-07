{{-- Entdecken: das neueste Lagebild als Einstieg (Kurzfazit + erste Aussagen), Rest in der Ansicht „Lagebild“. --}}
<article class="fa-obs-briefing" data-observatorium-lagebild-teaser>
    <span class="fa-obs-pill">Lagebild · {{ \Illuminate\Support\Carbon::parse($lagebild['zeitraum_bis'])->format('d.m.Y') }}</span>
    <h2>{{ $lagebild['titel'] }}</h2>
    @if($lagebild['kurzfazit'])<p>{{ $lagebild['kurzfazit'] }}</p>@endif
    @foreach(array_slice($lagebild['aussagen'], 0, 3) as $a)<p class="fa-obs-brief-item">{{ $a['text'] }}</p>@endforeach
    <button type="button" class="fa-obs-link" wire:click="ansichtSetzen('lagebild')">Ganzes Lagebild mit Quellen @svg('heroicon-o-arrow-right', 'fa-obs-inline-icon', ['width' => 16, 'height' => 16])</button>
</article>
