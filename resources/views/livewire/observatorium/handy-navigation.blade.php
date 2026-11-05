@php
    use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O;
    $filterAnzahl = $ansicht === 'radar'
        ? (int) ($radarSuche !== '') + count($kategorien) + count($sparten) + count($typen) + count($ebenen) + (int) $nurBefragung
        : (int) ($suche !== '') + (int) ($branche !== '') + (int) ($region !== '') + (int) ($tage !== '30') + (int) ($markt !== '') + (int) $pruefen + (int) ($wettbewerber !== 0) + (int) ($bewegung !== '');
@endphp
<div class="fa-obs-handy-toolbar" data-observatorium-handy-navigation>
    <label class="fa-obs-field"><span class="sr-only">Observatorium-Bereich</span><select wire:change="ansichtSetzen($event.target.value)" aria-label="Observatorium-Bereich">
        @foreach(O::ANSICHTEN as $key => [$name, $icon])<option value="{{ $key }}" @selected($ansicht === $key)>{{ $name }}{{ $key === 'merkliste' && $merkerAnzahl ? ' · '.$merkerAnzahl : '' }}</option>@endforeach
        @if($ansicht === 'briefing')<option value="briefing" selected>Mein Briefing</option>@endif
    </select></label>
    @unless($gewaehlt)
        <button type="button" class="fa-obs-button" x-on:click="filterOffen = !filterOffen" :aria-expanded="filterOffen.toString()" aria-controls="obs-handy-filter" data-observatorium-handy-filterknopf>
            @svg('heroicon-o-adjustments-horizontal', '', ['width' => 18, 'height' => 18]) Filter @if($filterAnzahl)<span class="fa-obs-pill">{{ $filterAnzahl }}</span>@endif
        </button>
    @endunless
</div>
@if($filterAnzahl && ! $gewaehlt)<div class="fa-obs-handy-filterinfo" role="status">{{ $filterAnzahl }} Filter aktiv · <button type="button" class="fa-obs-filter-reset" wire:click="{{ $ansicht === 'radar' ? 'radarFilterZuruecksetzen' : 'filterZuruecksetzen' }}">Zurücksetzen</button></div>@endif
