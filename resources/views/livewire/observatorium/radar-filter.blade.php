@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
<section class="fa-obs-radar-filters" aria-label="Radar filtern" data-observatorium-radar-filter>
    <h2>Radar filtern</h2>
    <div class="fa-obs-radar-suche">
        <label for="obs-radar-suche">Name oder Definition</label>
        <input id="obs-radar-suche" type="search" wire:model.live.debounce.300ms="radarSuche" placeholder="Trend suchen" maxlength="200">
    </div>
    <x-fa::choice name="kategorien" label="Kategorie" idPrefix="obs-radar" :multiple="true" :options="V::KATEGORIEN" />
    <x-fa::choice name="sparten" label="Sparte" idPrefix="obs-radar" :multiple="true" :options="V::SPARTEN" />
    <x-fa::choice name="typen" label="Trend oder Hype" idPrefix="obs-radar" :multiple="true" :options="V::TYPEN" />
    <x-fa::choice name="ebenen" label="Trendhierarchie" idPrefix="obs-radar" :multiple="true" :options="V::EBENEN" />
    <label class="fa-obs-radar-check"><input type="checkbox" wire:model.live="nurBefragung"> Nur von der Befragung bestätigt</label>
    @if($radarFilterAktiv)<button type="button" class="fa-obs-filter-reset" wire:click="radarFilterZuruecksetzen">Radarfilter zurücksetzen</button>@endif
</section>
