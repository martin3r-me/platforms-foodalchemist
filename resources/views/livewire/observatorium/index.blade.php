@php
    use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O;
    use Platform\FoodAlchemist\Support\TrendVokabular as V;
    $titel = match ($ansicht) {
        'wettbewerb' => 'Wer bewegt den Markt?', 'news' => 'Was die Gastronomie bewegt.', 'ideen' => 'Aus einer Idee wird dein nächstes Angebot.',
        'markt' => 'Bewegung im Markt. Chancen für euch.', 'merkliste' => 'Gute Gedanken bleiben.',
        'radar' => 'Entwicklungen mit Substanz.', 'lagebild' => 'Die Woche auf einen Blick.', 'zahlen' => 'Der Markt in Zahlen.', 'pinnwand' => 'Was euch inspiriert.', 'briefing' => 'Weniger suchen. Mehr mitnehmen.',
        default => 'Heute entdecken. Morgen gestalten.',
    };
    $untertitel = match ($ansicht) {
        'wettbewerb' => 'Bestätigte Unternehmensmeldungen – mit Quellen und eigener Einordnung.',
        'news' => 'Nach Branchen und Themen eingeordnet – mit dem Blick fürs Wesentliche.',
        'ideen' => 'Gerichte, Zutaten und Konzepte zum Weiterdenken.',
        'markt' => 'Betreiber, Veränderungen und belegte Hinweise zur weiteren Prüfung.',
        'merkliste' => 'Deine persönliche Sammlung und Trend-Prüfliste.',
        'radar' => 'Bewertete Trends und Hypes – bewusst freigegeben.',
        'lagebild' => 'Was diese Woche wichtig war – jede Aussage mit Quelle.',
        'zahlen' => 'Amtliche Preise und Umsätze für die Gastronomie – Destatis, monatlich.',
        'pinnwand' => 'Gemeinsam fürs Team: Meldungen und eigene Ideen.',
        'briefing' => 'Eine kompakte Auswahl aus deiner aktuellen Recherche und deinen Filtern.',
        default => 'Dein Blick auf Gastronomie, Food-Ideen und neue Möglichkeiten.',
    };
    $hatFilter = $suche !== '' || $branche !== '' || $region !== '' || $tage !== '30' || $markt !== '' || $pruefen || $wettbewerber !== 0 || $bewegung !== '';
    $hero = $ansicht === 'entdecken' && ! $gewaehlt && $eintraege->currentPage() === 1
        ? $eintraege->getCollection()->first(fn ($e) => in_array('idee', $e->observatorium['verwendungen'] ?? [], true)) : null;
@endphp

<x-ui-page>
    <x-slot:navbar><x-foodalchemist::shell.page-navbar title="Gastronomie-Observatorium" icon="heroicon-o-globe-alt" /></x-slot:navbar>
    @unless($istHandy)
    <x-slot:sidebar>
        <x-foodalchemist::page-sidebar title="Observatorium" width="w-64" :minWidth="220" :maxWidth="340" data-observatorium-navigation>
            <nav class="fa-obs-nav" aria-label="Observatorium-Bereiche">
                <div class="fa-obs-nav-label">Observatorium</div>
                @foreach(O::ANSICHTEN as $key => [$name, $icon])
                    <button type="button" wire:click="ansichtSetzen('{{ $key }}')" x-on:click="if (handy) setOpen(false)" @if($ansicht === $key) aria-current="page" @endif>
                        @svg($icon)<span>{{ $name }}</span>@if($key === 'merkliste' && $merkerAnzahl)<span class="fa-obs-nav-count">{{ $merkerAnzahl }}</span>@endif
                    </button>
                @endforeach
                @if($kuratiertHier)
                <div class="fa-obs-nav-foot">
                    <div class="fa-obs-nav-label">Die Recherche dahinter</div>
                    <a href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'beobachten']) }}" wire:navigate>@svg('heroicon-o-magnifying-glass-circle')<span>Trend Research</span>@svg('heroicon-o-arrow-up-right')</a>
                    <a href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'inspiration']) }}" wire:navigate>@svg('heroicon-o-light-bulb')<span>Recherche-Pinnwand</span></a>
                    <p class="fa-obs-subtitle fa-obs-nav-meta">{{ $quellenAnzahl }} Quellen · {{ $themenAnzahl }} Suchthemen</p>
                </div>
                @else
                <div class="fa-obs-nav-foot"><p class="fa-obs-subtitle fa-obs-nav-meta" data-observatorium-kuratiert>Kuratiert von BHG.DIGITAL · {{ $quellenAnzahl }} Quellen</p></div>
                @endif
            </nav>
        </x-foodalchemist::page-sidebar>
    </x-slot:sidebar>
    @endunless

    <main class="fa-obs fa-obs-main {{ $istHandy ? 'fa-obs-is-handy' : '' }}" x-data="{ filterOffen: false }" data-observatorium-ansicht="{{ $ansicht }}" @if($istHandy) data-observatorium-handy @endif>
        @include('foodalchemist::livewire.observatorium.styles')
        <header class="fa-obs-heading">
            <div><div class="fa-obs-eyebrow">Gastronomie-Observatorium</div><h1>{{ $istHandy ? (O::ANSICHTEN[$ansicht][0] ?? 'Mein Briefing') : $titel }}</h1>@unless($istHandy)<p class="fa-obs-subtitle">{{ $untertitel }}</p>@endunless</div>
            @if($ansicht !== 'radar')<button class="fa-obs-button" type="button" wire:click="ansichtSetzen('{{ $ansicht === 'briefing' ? 'entdecken' : 'briefing' }}')">@svg('heroicon-o-sparkles'){{ $ansicht === 'briefing' ? 'Zur Übersicht' : 'Mein Briefing' }}</button>@endif
        </header>
        @if($istHandy)
            @include('foodalchemist::livewire.observatorium.handy-navigation')
        @endif
        @if($meldung)<div class="fa-obs-status" role="status">{{ $meldung }}</div>@endif

        @if($gewaehlt)
            @include('foodalchemist::livewire.observatorium.detail')
        @elseif($ansicht === 'pinnwand')
            @include('foodalchemist::livewire.observatorium.pinnwand')
        @elseif($ansicht === 'lagebild')
            @include('foodalchemist::livewire.observatorium.lagebild')
        @elseif($ansicht === 'zahlen')
            @include('foodalchemist::livewire.observatorium.marktdaten')
        @elseif($ansicht === 'radar' && $trendDetail)
            @include('foodalchemist::livewire.observatorium.radar-trend', ['t' => $trendDetail])
        @elseif($ansicht === 'radar')
            @unless($istHandy)
                <div class="fa-obs-filters"><button type="button" class="fa-obs-button" x-on:click="filterOffen = !filterOffen" :aria-expanded="filterOffen.toString()" aria-controls="obs-handy-filter">@svg('heroicon-o-adjustments-horizontal') Radar filtern @if($radarFilterAktiv)<span class="fa-obs-pill">Aktiv</span>@endif</button></div>
            @endunless
            <div id="obs-handy-filter" x-show="filterOffen" x-cloak class="fa-obs-radar-filterpanel {{ $istHandy ? 'fa-obs-handy-filterpanel' : '' }}" @keydown.escape.window="filterOffen = false">
                @include('foodalchemist::livewire.observatorium.radar-filter')
                <button type="button" class="fa-obs-button primary" x-on:click="filterOffen = false">Ergebnisse ansehen</button>
            </div>
            @include('foodalchemist::livewire.observatorium.radar')
        @else
            @if($ansicht === 'briefing')@include('foodalchemist::livewire.observatorium.briefing-profil')@endif
            @unless($ansicht === 'briefing' && $briefingProfil)
            <div @if($istHandy) id="obs-handy-filter" x-show="filterOffen" x-cloak class="fa-obs-handy-filterpanel" @keydown.escape.window="filterOffen = false" @endif>
            <div class="fa-obs-filters" role="search" aria-label="Recherche filtern">
                <label class="fa-obs-field search">@svg('heroicon-o-magnifying-glass')<input type="search" wire:model.live.debounce.300ms="suche" aria-label="Thema oder Idee suchen" placeholder="Thema oder Idee suchen" maxlength="200"></label>
                <label class="fa-obs-field branch"><select wire:model.live="branche" aria-label="Branche filtern"><option value="">Alle Branchen</option>@foreach(O::BRANCHEN as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                <label class="fa-obs-field small"><select wire:model.live="region" aria-label="Region filtern"><option value="">Alle Regionen</option><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="CH">Schweiz</option><option value="offen">Region offen</option></select></label>
                <label class="fa-obs-field small"><select wire:model.live="tage" aria-label="Zeitraum filtern"><option value="7" @selected($tage === '7')>Letzte 7 Tage</option><option value="30" @selected($tage === '30')>Letzte 30 Tage</option><option value="90" @selected($tage === '90')>Letzte 90 Tage</option><option value="alle" @selected($tage === 'alle')>Archiv</option></select></label>
                @if($ansicht === 'wettbewerb')
                    <label class="fa-obs-field branch"><select wire:model.live="wettbewerber" aria-label="Wettbewerber filtern"><option value="0">Alle Wettbewerber</option>@foreach($wettbewerberProfile as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                    <label class="fa-obs-field branch"><select wire:model.live="bewegung" aria-label="Marktbewegung filtern"><option value="">Alle Entwicklungen</option>@foreach(\Platform\FoodAlchemist\Services\WettbewerbService::BEWEGUNGEN as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                @endif
                @if($hatFilter)<button class="fa-obs-filter-reset" type="button" wire:click="filterZuruecksetzen">Filter zurücksetzen</button>@endif
            </div>
            @if($ansicht === 'markt')<div class="fa-obs-filters"><label class="fa-obs-field small"><select wire:model.live="markt" aria-label="Marktansicht"><option value="">Alle Marktbewegungen</option><option value="chance">Chancen prüfen</option></select></label><span class="fa-obs-subtitle">Hinweise aus Quellen – noch keine bestätigten Geschäftschancen.</span></div>@endif
            @if($ansicht === 'merkliste')<label class="fa-obs-subtitle fa-obs-pruefen-filter"><input type="checkbox" wire:model.live="pruefen">Nur für die Trendprüfung vorgemerkte Beiträge</label>@endif

            @if($istHandy)<button type="button" class="fa-obs-button primary" x-on:click="filterOffen = false">Ergebnisse ansehen</button>@endif
            </div>
            @endunless

            @if($ansicht === 'entdecken' && $lagebild && $eintraege->currentPage() === 1)@include('foodalchemist::livewire.observatorium.lagebild-teaser')@endif

            @if($ansicht === 'wettbewerb')
                <div class="fa-obs-briefing" data-observatorium-wettbewerb>
                    <h2>{{ $wettbewerberProfile->count() }} beobachtete Unternehmen</h2>
                    <p class="fa-obs-subtitle fa-obs-abstand">{{ $istHandy ? 'Bestätigte Firmenmeldungen mit Quellen und eigener Bewertung.' : 'Hier erscheinen ausschließlich redaktionell bestätigte Firmenzuordnungen. Branchen- und Regionsfilter beziehen sich auf die Unternehmen. Aussagen aus Quellen und eigene Bewertungen bleiben getrennt.' }}</p>
                    @if($istHandy)<details class="fa-obs-wett-vergleich"><summary>Unternehmen &amp; Vergleich ansehen</summary>@endif
                    <div class="fa-obs-wett-profile">@foreach($wettbewerberProfile as $p)<article><button type="button" class="fa-obs-link" wire:click="$set('wettbewerber', {{ $p->id }})"><strong>{{ $p->name }}</strong></button><p class="fa-obs-subtitle">{{ $p->region ?: 'Region offen' }}{{ $p->gebiet ? ' · '.$p->gebiet : '' }}{{ ! $p->aktiv ? ' · Profil pausiert' : '' }}</p><p class="fa-obs-subtitle">{{ array_sum($wettbewerbVergleich[$p->id] ?? []) }} {{ array_sum($wettbewerbVergleich[$p->id] ?? []) === 1 ? 'bestätigte Fundstelle' : 'bestätigte Fundstellen' }} insgesamt</p><div class="fa-obs-tags">@foreach($wettbewerbVergleich[$p->id] ?? [] as $art => $anzahl)<span class="fa-obs-pill">{{ \Platform\FoodAlchemist\Services\WettbewerbService::BEWEGUNGEN[$art] ?? $art }} · {{ $anzahl }}</span>@endforeach</div></article>@endforeach</div>
                    @if($istHandy)</details>@endif
                    <a class="fa-obs-link" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'wettbewerber']) }}" wire:navigate>Profile und Treffer in Research verwalten @svg('heroicon-o-arrow-up-right')</a>
                </div>
            @endif

            @if($hero)
                <article class="fa-obs-hero" data-observatorium-fokus>
                    <div class="fa-obs-hero-copy"><span class="fa-obs-pill idea">Im Fokus · Food-Idee</span><h2>{{ $hero->titel }}</h2><p>{{ \Illuminate\Support\Str::limit($hero->zusammenfassung ?: $hero->teaser, 210) }}</p><button type="button" class="fa-obs-button primary" wire:click="oeffnen({{ $hero->id }})">Idee entdecken @svg('heroicon-o-arrow-up-right')</button></div>
                    <figure class="fa-obs-art" aria-label="Stilisierte Tellerillustration"><figcaption>Ideenwelt / Konzeptillustration</figcaption></figure>
                </article>
            @endif

            <div class="fa-obs-sectionhead"><h2>{{ $ansicht === 'briefing' ? 'Das nimmst du mit' : ($ansicht === 'ideen' ? 'Ideen zum Weiterdenken' : ($ansicht === 'merkliste' ? 'Für dich gesammelt' : ($ansicht === 'wettbewerb' ? 'Bestätigte Wettbewerbsmeldungen' : 'Frische Perspektiven für deine Branche'))) }}</h2><span class="fa-obs-subtitle">{{ $eintraege->total() }} {{ $eintraege->total() === 1 ? 'Meldung' : 'Meldungen' }}</span></div>

            @if($eintraege->isEmpty() && $ansicht === 'briefing' && $briefingProfil)
                <div class="fa-obs-empty" data-briefing-leer><h2>Für deine Einstellung gibt es gerade nichts Neues.</h2><p>Nimm mehr Branchen oder Themen dazu oder wähle einen längeren Zeitraum.</p><button class="fa-obs-button" type="button" wire:click="profilBearbeiten">Einstellung ändern</button></div>
            @elseif($eintraege->isEmpty())
                <div class="fa-obs-empty"><h2>{{ $ansicht === 'merkliste' ? 'Deine Merkliste ist noch leer.' : 'Noch keine passenden Meldungen.' }}</h2><p>{{ $ansicht === 'merkliste' ? 'Merke interessante Beiträge über das Lesezeichen. Deine Auswahl bleibt persönlich.' : 'Neue Beiträge erscheinen nach dem Quellenabruf. Ändere die Filter oder schau in die Recherche.' }}</p><button class="fa-obs-button" type="button" wire:click="filterZuruecksetzen">Filter zurücksetzen</button></div>
            @elseif($ansicht === 'briefing')
                <div class="fa-obs-briefing"><span class="fa-obs-pill">{{ $briefingProfil ? 'Deine Auswahl' : 'Aus deiner Recherche' }} · {{ now()->format('d.m.Y') }}</span>@foreach($eintraege->take(5) as $e)<article class="fa-obs-brief-item"><div class="fa-obs-meta"><span>{{ $e->herausgeber ?: $e->quelle?->name }}</span><span>{{ $e->veroeffentlicht_am?->format('d.m.Y') ?? 'Veröffentlichungsdatum offen' }}</span></div><h3>{{ $e->titel }}</h3><p class="fa-obs-subtitle">{{ $e->zusammenfassung ?: $e->teaser }}</p><button class="fa-obs-link" type="button" wire:click="oeffnen({{ $e->id }})">Beitrag entdecken @svg('heroicon-o-arrow-up-right')</button></article>@endforeach</div>
            @else
                <div class="{{ in_array($ansicht, ['entdecken', 'ideen'], true) ? 'fa-obs-grid' : 'fa-obs-list' }}" wire:loading.class="fa-obs-loading" wire:target="branche,region,tage,suche,markt,pruefen">
                    @foreach($eintraege as $e)@if(! $hero || $hero->id !== $e->id)@if($ansicht === 'wettbewerb')@include('foodalchemist::livewire.observatorium.wettbewerb-karte')@else @include('foodalchemist::livewire.observatorium.karte', ['mitIllustration' => in_array($ansicht, ['entdecken', 'ideen'], true)]) @endif@endif @endforeach
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
        @if($istHandy && $kuratiertHier)<div class="fa-obs-actions"><a class="fa-obs-link" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste']) }}" wire:navigate>Trend Research @svg('heroicon-o-arrow-up-right')</a><a class="fa-obs-link" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'inspiration']) }}" wire:navigate>Recherche-Pinnwand @svg('heroicon-o-arrow-up-right')</a></div>@endif
        <footer class="fa-obs-footnote"><span>Gastronomie-Observatorium</span><span>Titel & Kurzbeschreibungen · Originalquellen prüfen · keine automatische Radar-Freigabe</span></footer>
    </main>
</x-ui-page>
