@php
    use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O;
    use Platform\FoodAlchemist\Support\TrendVokabular as V;
    $info = $gewaehlt->observatorium ?? [];
    $m = $merker->get($gewaehlt->id);
    $original = O::herausgeberUrl($gewaehlt->link());
@endphp
<button class="fa-obs-link" type="button" wire:click="schliessen">@svg('heroicon-o-arrow-left')Zurück zur Übersicht</button>
<article class="fa-obs-detail" data-observatorium-detail="{{ $gewaehlt->id }}">
    <div class="fa-obs-meta"><span>{{ $gewaehlt->herausgeber ?: ($gewaehlt->quelle?->name ?: 'Eigene Recherche') }}</span><span>{{ $gewaehlt->veroeffentlicht_am?->format('d.m.Y') ?? 'Veröffentlichungsdatum offen' }}</span></div>
    <h2>{{ $gewaehlt->titel }}</h2>
    <p class="lead">{{ $gewaehlt->zusammenfassung ?: $gewaehlt->teaser }}</p>
    @include('foodalchemist::livewire.observatorium.vertiefung', ['e' => $gewaehlt])
    <div class="fa-obs-tags">@foreach($info['verwendungen'] ?? [] as $v)<span class="fa-obs-pill {{ $v === 'idee' ? 'idea' : '' }}">{{ O::VERWENDUNGEN[$v] ?? $v }}</span>@endforeach @foreach($info['branchen'] ?? [] as $b)<span class="fa-obs-pill">{{ O::BRANCHEN[$b] ?? $b }}</span>@endforeach</div>
    @if(! empty($info['einordnung']))<div class="fa-obs-kicker">Warum das interessant sein könnte</div><p>{{ $info['einordnung'] }}</p>@endif
    @if(! empty($info['impuls']))<div class="fa-obs-kicker">Ein Impuls für die Praxis · KI-Vorschlag</div><p>{{ $info['impuls'] }}</p>@endif
    @foreach($gewaehlt->wettbewerbFunde as $fund)<div class="fa-obs-kicker">Firmenbezug bestätigt · {{ $fund->wettbewerber->name }}</div><p>{{ \Platform\FoodAlchemist\Services\WettbewerbService::BEWEGUNGEN[$fund->bewegung] ?? 'Unternehmensmeldung' }}</p>@if($fund->einordnung)<p><strong>Eigene Bewertung:</strong> {{ $fund->einordnung }}</p>@endif @endforeach
    <div class="fa-obs-kicker">Trendbewertung</div><p>{{ $gewaehlt->begruendung ?: 'Noch nicht als Trendbeleg bewertet.' }}</p>
    @if($gewaehlt->thema)<p class="fa-obs-subtitle">Thema: {{ $gewaehlt->thema }} · {{ V::EINGANG_STATUS[$gewaehlt->status] ?? $gewaehlt->status }}</p>@endif
    <div class="fa-obs-actions">
        <button class="fa-obs-button primary" type="button" wire:click="merken({{ $gewaehlt->id }})" wire:loading.attr="disabled">@svg($m?->gemerkt ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'){{ $m?->gemerkt ? 'Gemerkt' : 'Beitrag merken' }}</button>
        <button class="fa-obs-button" type="button" wire:click="trendPruefen({{ $gewaehlt->id }})" wire:loading.attr="disabled">@svg('heroicon-o-signal'){{ $m?->trend_pruefen ? 'Aus Prüfliste entfernen' : 'Für Trendprüfung merken' }}</button>
        @if($darfKuratieren && (int) $gewaehlt->team_id === (int) $teamId)
            @if($gewaehlt->vertiefung_status !== 'laeuft')<button class="fa-obs-button" type="button" wire:click="vertiefen({{ $gewaehlt->id }})" wire:loading.attr="disabled" data-observatorium-vertiefen {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('observatorium.vertiefen') }}>@svg('heroicon-o-document-magnifying-glass'){{ $gewaehlt->vertiefung_status === null ? 'Vertiefen' : 'Neu vertiefen' }}</button>@endif
            @if($gewaehlt->inspiration_id)<a class="fa-obs-button" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'inspiration']) }}" wire:navigate>Auf der Pinnwand ansehen</a>
            @else<button class="fa-obs-button" type="button" wire:click="aufPinnwand({{ $gewaehlt->id }})" wire:loading.attr="disabled" {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('observatorium.pinnwand') }}>@svg('heroicon-o-light-bulb')Auf die Pinnwand</button>@endif
        @endif
        @if($original)<a class="fa-obs-link" href="{{ $original }}" target="_blank" rel="noopener noreferrer">Original öffnen @svg('heroicon-o-arrow-up-right')</a>@endif
    </div>
    @if($m?->trend_pruefen)<p class="fa-obs-subtitle">Für deine persönliche Trendprüfung vorgemerkt. Dadurch entsteht noch kein Trend oder Beleg.</p>@endif
    @if($weitereQuellen->isNotEmpty())<div class="fa-obs-kicker">Weitere gleichbetitelte Meldungen</div>@foreach($weitereQuellen as $weiter)@php $link = O::herausgeberUrl($weiter->link()); @endphp @if($link)<p><a class="fa-obs-link" href="{{ $link }}" target="_blank" rel="noopener noreferrer">{{ $weiter->herausgeber ?: ($weiter->quelle?->name ?: parse_url($link, PHP_URL_HOST)) }} @svg('heroicon-o-arrow-up-right')</a></p>@endif @endforeach<p class="fa-obs-subtitle">Mehrere Veröffentlichungen können dieselbe Ursprungsmeldung übernehmen.</p>@endif
    <div class="fa-obs-footnote"><span>{{ ($info['herkunft'] ?? '') === 'ki' ? 'KI-Einordnung auf Basis von Titel und Kurzbeschreibung' : 'Nach Stichworten vorsortiert · keine zusätzliche KI-Auswertung' }}</span><span>{{ isset($info['region']) ? 'Region: '.$info['region'] : 'Region nicht sicher zugeordnet' }}</span></div>
</article>
