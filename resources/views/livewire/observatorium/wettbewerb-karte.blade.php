@php use Platform\FoodAlchemist\Services\WettbewerbService as W; @endphp
<article class="fa-obs-story" wire:key="obs-wettbewerb-{{ $e->id }}" data-wettbewerb-meldung="{{ $e->id }}"><div class="fa-obs-story-body">
    <div class="fa-obs-meta"><span>Firmenbezug bestätigt</span><span>{{ $e->veroeffentlicht_am?->format('d.m.Y') ?? 'Veröffentlichungsdatum offen' }}</span></div>
    <h3><button type="button" wire:click="oeffnen({{ $e->id }})">{{ $e->titel }}</button></h3>
    <p>{{ $e->zusammenfassung ?: $e->teaser }}</p><div class="fa-obs-source">{{ $e->herausgeber ?: $e->quelle?->name }}</div>
    @foreach($e->wettbewerbFunde as $fund)<div class="fa-obs-tags"><span class="fa-obs-pill market">{{ $fund->wettbewerber->name }}</span><span class="fa-obs-pill">{{ W::BEWEGUNGEN[$fund->bewegung] ?? 'Unternehmensmeldung' }}</span></div>@if($fund->einordnung)<p><strong>Eigene Bewertung · {{ $fund->wettbewerber->name }}:</strong> {{ $fund->einordnung }}</p>@endif @endforeach
    <div class="fa-obs-story-foot"><button type="button" class="fa-obs-link" wire:click="oeffnen({{ $e->id }})">Quelle & Details @svg('heroicon-o-arrow-up-right')</button><button type="button" class="fa-obs-save" wire:click="merken({{ $e->id }})" aria-label="Beitrag merken: {{ $e->titel }}" aria-pressed="{{ $merker->get($e->id)?->gemerkt ? 'true' : 'false' }}">@svg('heroicon-o-bookmark')</button></div>
</div></article>
