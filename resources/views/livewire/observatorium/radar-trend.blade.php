{{-- Lese-Detail eines freigegebenen Radar-Trends (Dominique 08.11.2026): für alle Teams, auch Mandanten — Inhalt vom Inhalts-Team (Master),
     geladen über ObservatoriumService::radarTrend. Nur Lesefelder: keine Kurator-Notizen, Kosten oder Automatik-Interna. --}}
@php
    use Platform\FoodAlchemist\Support\TrendVokabular as V;
    $ab = is_array($t->ableitung) ? $t->ableitung : [];
    $einordnung = trim(implode(' · ', array_filter([V::KATEGORIEN[$t->kategorie] ?? null, $t->kategorie === 'food' ? (V::FOOD_CLUSTER[$t->food_cluster] ?? null) : null])));
@endphp
<button class="fa-obs-link" type="button" wire:click="trendSchliessen" data-observatorium-trend-zurueck>@svg('heroicon-o-arrow-left')Zurück zum Radar</button>
<article class="fa-obs-detail" data-observatorium-trend-detail="{{ $t->id }}">
    <div class="fa-obs-tags">
        <span class="fa-obs-pill {{ $t->typ === 'trend' ? 'idea' : 'market' }}">{{ V::TYPEN[$t->typ] ?? 'Typ offen' }} · {{ V::EBENEN[$t->ebene] ?? 'Ebene offen' }}</span>
        <span class="fa-obs-pill">{{ V::STATUS[$t->status] ?? $t->status }}</span>
        <span class="fa-obs-pill">Konfidenz: {{ V::KONFIDENZ[$t->wirksameKonfidenz()] ?? $t->wirksameKonfidenz() }}</span>
        @if($t->befragung_bestaetigt)<span class="fa-obs-pill idea">Befragung bestätigt{{ $t->befragung_anteil !== null ? ' · '.number_format((float) $t->befragung_anteil, 0, ',', '.').' %' : '' }}</span>@endif
    </div>
    <h2>{{ $t->name }}</h2>
    <div class="fa-obs-meta">
        <span>{{ $einordnung !== '' ? $einordnung : 'Kategorie offen' }}</span>
        <span>{{ ($t->sparten ?? []) !== [] ? collect($t->sparten)->map(fn ($s) => V::SPARTEN[$s] ?? $s)->implode(', ') : 'Für alle Sparten' }}</span>
    </div>
    @if($t->definition)<p class="lead">{{ $t->definition }}</p>@endif

    @if(! empty($ab['kurzfazit']) || ! empty($ab['bedeutung']) || ! empty($ab['impulse']))
        <div class="fa-obs-kicker">Was heißt das für dich</div>
        @if(! empty($ab['kurzfazit']))<p>{{ $ab['kurzfazit'] }}</p>@endif
        @if(! empty($ab['bedeutung']))
            <ul class="fa-obs-kern">@foreach($ab['bedeutung'] as $sparte => $text)<li><strong>{{ V::SPARTEN[$sparte] ?? $sparte }}:</strong> {{ $text }}</li>@endforeach</ul>
        @endif
        @if(! empty($ab['impulse']))
            <ul class="fa-obs-kern">@foreach($ab['impulse'] as $i)<li><strong>{{ ($i['art'] ?? '') === 'konzept' ? 'Konzept' : 'Gericht' }}: {{ $i['titel'] ?? '' }}</strong>@if(! empty($i['beschreibung']))<span class="fa-obs-subtitle">{{ $i['beschreibung'] }}</span>@endif</li>@endforeach</ul>
        @endif
    @endif

    @if($t->historische_einordnung || $t->gartner_phase || $t->einordnung_begruendung)
        <div class="fa-obs-kicker">Einordnung</div>
        @if($t->historische_einordnung)<p>{{ $t->historische_einordnung }}</p>@endif
        @if($t->gartner_phase)<p>Gartner Hype Cycle: {{ V::GARTNER_PHASEN[$t->gartner_phase] ?? $t->gartner_phase }}</p>@endif
        @if($t->einordnung_begruendung)<p>{{ $t->einordnung_begruendung }}</p>@endif
    @endif

    @if($t->signale->isNotEmpty())
        <div class="fa-obs-kicker">Suchinteresse (Google Trends)</div>
        <ul class="fa-obs-kern">@foreach($t->signale as $m)<li>„{{ $m->suchbegriff }}“ · {{ ucfirst((string) ($m->richtung ?: 'keine Daten')) }}<span class="fa-obs-subtitle">Ø {{ $m->durchschnitt ?? '–' }} · Spitze {{ $m->spitze ?? '–' }} · gemessen {{ $m->created_at?->format('d.m.Y') }}</span></li>@endforeach</ul>
    @endif

    <div class="fa-obs-kicker">Belege · {{ $t->belege->count() }}</div>
    @forelse($t->belege as $b)
        <div class="fa-obs-trend-beleg" data-observatorium-trend-beleg="{{ $b->id }}">
            <div class="fa-obs-meta"><span>{{ V::QUELLEN[$b->quelle] ?? $b->quelle }}{{ $b->fundort ? ' · '.$b->fundort : '' }}</span><span>{{ $b->beobachtet_am?->format('d.m.Y') ?? 'Datum offen' }}</span></div>
            <p class="fa-obs-trend-beleg-titel">@if($b->url)<a href="{{ $b->url }}" target="_blank" rel="noopener">{{ $b->titel ?: $b->url }}</a>@else{{ $b->titel ?: 'Ohne Titel' }}@endif</p>
        </div>
    @empty
        <p>Noch keine Belege.</p>
    @endforelse

    @if($kuratiertHier)
        <div class="fa-obs-actions"><a class="fa-obs-button" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste', 'trend' => $t->id]) }}" wire:navigate data-observatorium-trend-research>@svg('heroicon-o-arrow-up-right')In Trend Research öffnen</a></div>
    @endif
</article>
