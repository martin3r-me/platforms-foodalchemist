@php
    use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O;
    use Platform\FoodAlchemist\Support\TrendVokabular as V;
    $titel = match ($ansicht) {
        'news' => 'Was die Gastronomie bewegt.', 'ideen' => 'Aus einer Idee wird dein nächstes Angebot.',
        'markt' => 'Bewegung im Markt. Chancen für euch.', 'merkliste' => 'Gute Gedanken bleiben.',
        'radar' => 'Entwicklungen mit Substanz.', 'briefing' => 'Weniger suchen. Mehr mitnehmen.',
        default => 'Heute entdecken. Morgen gestalten.',
    };
    $untertitel = match ($ansicht) {
        'news' => 'Nach Branchen und Themen eingeordnet – mit dem Blick fürs Wesentliche.',
        'ideen' => 'Gerichte, Zutaten und Konzepte zum Weiterdenken.',
        'markt' => 'Betreiber, Veränderungen und belegte Hinweise zur weiteren Prüfung.',
        'merkliste' => 'Deine persönliche Sammlung und Trend-Prüfliste.',
        'radar' => 'Bewertete Trends und Hypes – bewusst freigegeben.',
        'briefing' => 'Eine kompakte Auswahl aus deiner aktuellen Recherche und deinen Filtern.',
        default => 'Dein Blick auf Gastronomie, Food-Ideen und neue Möglichkeiten.',
    };
    $hatFilter = $suche !== '' || $branche !== '' || $region !== '' || $tage !== '30' || $markt !== '' || $pruefen;
    $hero = $ansicht === 'entdecken' && ! $gewaehlt && $eintraege->currentPage() === 1
        ? $eintraege->getCollection()->first(fn ($e) => in_array('idee', $e->observatorium['verwendungen'] ?? [], true)) : null;
@endphp

<x-ui-page>
    <x-slot:navbar><x-foodalchemist::shell.page-navbar title="Gastronomie-Observatorium" icon="heroicon-o-globe-alt" /></x-slot:navbar>
    <x-slot:sidebar>
        <x-foodalchemist::page-sidebar title="Observatorium" width="w-64" :minWidth="220" :maxWidth="340" data-observatorium-navigation>
            <nav class="fa-obs-nav" aria-label="Observatorium-Bereiche">
                <div class="fa-obs-nav-label">Observatorium</div>
                @foreach(O::ANSICHTEN as $key => [$name, $icon])
                    <button type="button" wire:click="ansichtSetzen('{{ $key }}')" x-on:click="if (handy) setOpen(false)" @if($ansicht === $key) aria-current="page" @endif>
                        @svg($icon)<span>{{ $name }}</span>@if($key === 'merkliste' && $merkerAnzahl)<span style="margin-left:auto">{{ $merkerAnzahl }}</span>@endif
                    </button>
                @endforeach
                @if($ansicht === 'radar')
                    <section class="fa-obs-radar-filters" aria-label="Radar filtern" data-observatorium-radar-filter>
                        <h2>Radar filtern</h2>
                        <label for="obs-radar-suche">Name oder Definition</label>
                        <input id="obs-radar-suche" type="search" wire:model.live.debounce.300ms="radarSuche" placeholder="Trend suchen" maxlength="200">
                        <x-fa::choice name="kategorien" label="Kategorie" idPrefix="obs-radar" :multiple="true" :options="V::KATEGORIEN" />
                        <x-fa::choice name="sparten" label="Sparte" idPrefix="obs-radar" :multiple="true" :options="V::SPARTEN" />
                        <x-fa::choice name="typen" label="Trend oder Hype" idPrefix="obs-radar" :multiple="true" :options="V::TYPEN" />
                        <x-fa::choice name="ebenen" label="Trendhierarchie" idPrefix="obs-radar" :multiple="true" :options="V::EBENEN" />
                        <label class="fa-obs-radar-check"><input type="checkbox" wire:model.live="nurBefragung"> Nur von der Befragung bestätigt</label>
                        @if($radarFilterAktiv)<button type="button" class="fa-obs-filter-reset" wire:click="radarFilterZuruecksetzen">Radarfilter zurücksetzen</button>@endif
                    </section>
                @endif
                <div class="fa-obs-nav-foot">
                    <div class="fa-obs-nav-label">Die Recherche dahinter</div>
                    <a href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'beobachten']) }}" wire:navigate>@svg('heroicon-o-magnifying-glass-circle')<span>Trend Research</span>@svg('heroicon-o-arrow-up-right')</a>
                    <a href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'inspiration']) }}" wire:navigate>@svg('heroicon-o-light-bulb')<span>Recherche-Pinnwand</span></a>
                    <p class="fa-obs-subtitle" style="padding:0 10px">{{ $quellenAnzahl }} Quellen · {{ $themenAnzahl }} Suchthemen</p>
                </div>
            </nav>
        </x-foodalchemist::page-sidebar>
    </x-slot:sidebar>

    <main class="fa-obs fa-obs-main" data-observatorium-ansicht="{{ $ansicht }}">
        @include('foodalchemist::livewire.observatorium.styles')
        <header class="fa-obs-heading">
            <div><div class="fa-obs-eyebrow">Gastronomie-Observatorium</div><h1>{{ $titel }}</h1><p class="fa-obs-subtitle">{{ $untertitel }}</p></div>
            @if($ansicht !== 'radar')<button class="fa-obs-button" type="button" wire:click="ansichtSetzen('{{ $ansicht === 'briefing' ? 'entdecken' : 'briefing' }}')">@svg('heroicon-o-sparkles'){{ $ansicht === 'briefing' ? 'Zur Übersicht' : 'Mein Briefing' }}</button>@endif
        </header>
        @if($meldung)<div class="fa-obs-status" role="status">{{ $meldung }}</div>@endif

        @if($gewaehlt)
            @include('foodalchemist::livewire.observatorium.detail')
        @elseif($ansicht === 'radar')
            @include('foodalchemist::livewire.observatorium.radar')
        @else
            <div class="fa-obs-filters" role="search" aria-label="Recherche filtern">
                <label class="fa-obs-field search">@svg('heroicon-o-magnifying-glass')<input type="search" wire:model.live.debounce.300ms="suche" aria-label="Thema oder Idee suchen" placeholder="Thema oder Idee suchen" maxlength="200"></label>
                <label class="fa-obs-field branch"><select wire:model.live="branche" aria-label="Branche filtern"><option value="">Alle Branchen</option>@foreach(O::BRANCHEN as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                <label class="fa-obs-field small"><select wire:model.live="region" aria-label="Region filtern"><option value="">Alle Regionen</option><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="CH">Schweiz</option><option value="offen">Region offen</option></select></label>
                <label class="fa-obs-field small"><select wire:model.live="tage" aria-label="Zeitraum filtern"><option value="7" @selected($tage === '7')>Letzte 7 Tage</option><option value="30" @selected($tage === '30')>Letzte 30 Tage</option><option value="90" @selected($tage === '90')>Letzte 90 Tage</option><option value="alle" @selected($tage === 'alle')>Archiv</option></select></label>
                @if($hatFilter)<button class="fa-obs-filter-reset" type="button" wire:click="filterZuruecksetzen">Filter zurücksetzen</button>@endif
            </div>
            @if($ansicht === 'markt')<div class="fa-obs-filters"><label class="fa-obs-field small"><select wire:model.live="markt" aria-label="Marktansicht"><option value="">Alle Marktbewegungen</option><option value="chance">Chancen prüfen</option></select></label><span class="fa-obs-subtitle">Hinweise aus Quellen – noch keine bestätigten Geschäftschancen.</span></div>@endif
            @if($ansicht === 'merkliste')<label class="fa-obs-subtitle" style="display:flex;gap:9px;align-items:center;margin-bottom:20px"><input type="checkbox" wire:model.live="pruefen">Nur für die Trendprüfung vorgemerkte Beiträge</label>@endif

            @if($hero)
                <article class="fa-obs-hero" data-observatorium-fokus>
                    <div class="fa-obs-hero-copy"><span class="fa-obs-pill idea">Im Fokus · Food-Idee</span><h2>{{ $hero->titel }}</h2><p>{{ \Illuminate\Support\Str::limit($hero->zusammenfassung ?: $hero->teaser, 210) }}</p><button type="button" class="fa-obs-button primary" wire:click="oeffnen({{ $hero->id }})">Idee entdecken @svg('heroicon-o-arrow-up-right')</button></div>
                    <figure class="fa-obs-art" aria-label="Stilisierte Tellerillustration"><figcaption>Ideenwelt / Konzeptillustration</figcaption></figure>
                </article>
            @endif

            <div class="fa-obs-sectionhead"><h2>{{ $ansicht === 'briefing' ? 'Das nimmst du mit' : ($ansicht === 'ideen' ? 'Ideen zum Weiterdenken' : ($ansicht === 'merkliste' ? 'Für dich gesammelt' : 'Frische Perspektiven für deine Branche')) }}</h2><span class="fa-obs-subtitle">{{ $eintraege->total() }} {{ $eintraege->total() === 1 ? 'Meldung' : 'Meldungen' }}</span></div>

            @if($eintraege->isEmpty())
                <div class="fa-obs-empty"><h2>{{ $ansicht === 'merkliste' ? 'Deine Merkliste ist noch leer.' : 'Noch keine passenden Meldungen.' }}</h2><p>{{ $ansicht === 'merkliste' ? 'Merke interessante Beiträge über das Lesezeichen. Deine Auswahl bleibt persönlich.' : 'Neue Beiträge erscheinen nach dem Quellenabruf. Ändere die Filter oder schau in die Recherche.' }}</p><button class="fa-obs-button" type="button" wire:click="filterZuruecksetzen">Filter zurücksetzen</button></div>
            @elseif($ansicht === 'briefing')
                <div class="fa-obs-briefing"><span class="fa-obs-pill">Aus deiner Recherche · {{ now()->format('d.m.Y') }}</span>@foreach($eintraege->take(5) as $e)<article class="fa-obs-brief-item"><div class="fa-obs-meta"><span>{{ $e->herausgeber ?: $e->quelle?->name }}</span><span>{{ $e->veroeffentlicht_am?->format('d.m.Y') ?? 'Veröffentlichungsdatum offen' }}</span></div><h3>{{ $e->titel }}</h3><p class="fa-obs-subtitle">{{ $e->zusammenfassung ?: $e->teaser }}</p><button class="fa-obs-link" style="margin-top:14px" type="button" wire:click="oeffnen({{ $e->id }})">Beitrag entdecken @svg('heroicon-o-arrow-up-right')</button></article>@endforeach</div>
            @else
                <div class="{{ in_array($ansicht, ['entdecken', 'ideen'], true) ? 'fa-obs-grid' : 'fa-obs-list' }}" wire:loading.class="fa-obs-loading" wire:target="branche,region,tage,suche,markt,pruefen">
                    @foreach($eintraege as $e)@if(! $hero || $hero->id !== $e->id)@include('foodalchemist::livewire.observatorium.karte', ['mitIllustration' => in_array($ansicht, ['entdecken', 'ideen'], true)])@endif @endforeach
                </div>
                @if($eintraege->hasPages())
                    <nav class="fa-obs-actions" aria-label="Meldungsseiten">
                        <button type="button" class="fa-obs-button" wire:click="previousPage" @disabled($eintraege->onFirstPage())>Zurück</button>
                        <span class="fa-obs-subtitle">Seite {{ $eintraege->currentPage() }} von {{ $eintraege->lastPage() }}</span>
                        <button type="button" class="fa-obs-button" wire:click="nextPage" @disabled(! $eintraege->hasMorePages())>Weiter</button>
                    </nav>
                @endif
            @endif
        @endif
        <footer class="fa-obs-footnote"><span>Gastronomie-Observatorium</span><span>Titel & Kurzbeschreibungen · Originalquellen prüfen · keine automatische Radar-Freigabe</span></footer>
    </main>
</x-ui-page>
