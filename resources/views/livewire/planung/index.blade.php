{{-- Planung (Leitstelle). Haus-Anordnung: links Planungen (Suche, Filter, Liste), Mitte Erstellen + Board,
     rechts Details; „Öffnen" → Vollbild-Editor im Werkbank-Modus (Erstell-Reiter · Ausgabe-Reiter · Fortschritt).
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> und --fa-*-Tokens umgestellt (hell + Werkbank).
     Laptop-Tauglichkeit: Board-Spalten haben eine Mindestbreite und scrollen waagerecht statt zu
     zerquetschen; die Reiterleiste scrollt statt umzubrechen; die klebenden Erstell-Leisten sind
     eine niedrige Zeile (Plan, Wissens-Vorschau und Hinweise stehen davor im normalen Fluss).
     Funktion, wire:-Bindungen, Event-Namen, Diktat-Ziele und data-Marker unverändert. --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets
@assets
{{-- Spec 53/D: gemeinsamer Voice-Recorder fürs Briefing-Diktat (partials/diktat.blade.php). Hotfix: in
     @assets statt als rohes erstes Tag — sonst bekommt das <script> Livewires wire:id und die Komponente zerfällt. --}}
<script src="/_platform/fa-assets/foodalchemist-voice-recorder.iife.js?v={{ config('platform.fa_voice_recorder_hash', '0') }}"></script>
@endassets
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $statusLabel = ['divergenz' => 'Ideen sammeln', 'konvergenz' => 'Auswahl treffen', 'erledigt' => 'Abgeschlossen'];
    $modeLabel = ['voll_kreativ' => 'Frei kreativ', 'hybrid' => 'Kreativ mit Bestand', 'datenbank' => 'Nur Bestand'];
    // Wirkungs-Hinweise je Modus (die EINE Achse für Wiederverwendung):
    $modeHint = [
        'voll_kreativ' => 'Freie Gerichtsidee. Vorhandene Basisrezepte werden trotzdem wiederverwendet, neu entsteht nur eine echte Lücke.',
        'hybrid' => 'Freie Idee mit Bezug zum Bestand. Vorhandene Gerichte und Basisrezepte werden bevorzugt wiederverwendet.',
        'datenbank' => 'Ausschließlich vorhandene Gerichte und Basisrezepte. Lücken bleiben sichtbar und werden nicht neu erstellt.',
    ];
    // Etiketten über Tokens (hell + Werkbank). $chip liefert fertiges HTML für die Closures unten.
    $tonKlasse = [
        'neutral' => 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]',
        'accent' => 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]',
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
        'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]',
    ];
    $chip = fn ($t, $ton = 'neutral') => '<span class="inline-flex items-center h-[22px] max-w-full px-2 rounded-full text-[length:var(--fa-text-sm)] font-medium whitespace-nowrap truncate ' . ($tonKlasse[$ton] ?? $tonKlasse['neutral']) . '">' . e($t) . '</span>';
    // Board/Liste: Kaskaden-Status je Session aus $kaskaden (jüngster Lauf). Kein Lauf → „Entwurf".
    $kaskaden = $kaskaden ?? [];
    $kaskadeStatusTon = ['entwurf' => 'neutral', 'läuft' => 'info', 'prüfen' => 'warn', 'fertig' => 'ok', 'fehlgeschlagen' => 'crit'];
    $kaskadeBadge = function ($sessionId) use ($kaskaden, $kaskadeStatusTon, $chip) {
        $status = $kaskaden[(int) $sessionId]['status'] ?? 'entwurf';
        return $chip(\Illuminate\Support\Str::ucfirst($status), $kaskadeStatusTon[$status] ?? 'neutral');
    };
    $kaskadeLaeuft = fn ($sessionId) => (bool) ($kaskaden[(int) $sessionId]['running'] ?? false);
    // Stufen-Fortschritt kompakt: „Gerichte 1/1 · Basisrezepte 0/3".
    $kaskadeFortschritt = function ($sessionId) use ($kaskaden) {
        $stufen = $kaskaden[(int) $sessionId]['stufen'] ?? [];
        return collect($stufen)->map(fn ($st) => $st['label'] . ' ' . $st['fertig'] . '/' . $st['total'])->implode(' · ');
    };
    // Anzeige-Titel: Ergebnis-Name (Kaskaden-Artefakt) → Anfang der Analyse → gespeicherter Titel.
    $anzeigeTitel = function ($s) use ($kaskaden) {
        $t = $kaskaden[(int) $s->id]['titel'] ?? null;
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }
        $ana = trim((string) ($s->analysis ?? ''));
        return $ana !== '' ? \Illuminate\Support\Str::limit($ana, 42) : $s->title;
    };
    // Typ-Symbol je Session (aus dem Scope des jüngsten Laufs).
    $typIconMap = ['concept' => 'heroicon-o-squares-2x2', 'gericht' => 'heroicon-o-cake', 'rezept' => 'heroicon-o-beaker', 'vollkaskade' => 'heroicon-o-bolt'];
    $typIcon = fn ($s) => $typIconMap[$kaskaden[(int) $s->id]['scope'] ?? ''] ?? 'heroicon-o-light-bulb';
    // Board: Ausgabe-Ziel (Owner). Kein Owner → „Frei".
    $ausgabeZielLabel = ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'offer' => 'Angebot', 'format' => 'Format', 'concept' => 'Concept'];
    $ausgabeChip = function ($sessionId) use ($kaskaden, $ausgabeZielLabel, $chip) {
        $ot = $kaskaden[(int) $sessionId]['owner_type'] ?? null;
        if ($ot === null) {
            return $chip('Frei');
        }
        $name = trim((string) ($kaskaden[(int) $sessionId]['owner_name'] ?? ''));
        $lbl = ($ausgabeZielLabel[$ot] ?? \Illuminate\Support\Str::ucfirst($ot)) . ($name !== '' ? ' · ' . $name : '');
        return $chip($lbl, 'accent');
    };
    $zustandTon = ['läuft' => 'info', 'prüfen' => 'warn', 'geplant' => 'neutral', 'erledigt' => 'ok'];
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menueGruppe = 'px-3 pt-2 pb-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]';
    $verwerfenFrage = 'Diese Planung verwerfen? Laufende Erstellungen dieser Planung werden ebenfalls gestoppt. Sie wird archiviert, nicht endgültig gelöscht.';
    // Editor-Reiter: Erstellen · Ausgaben · Fortschritt. Optik wie x-foodalchemist::editor-tabs; der Baustein selbst
    // passt hier nicht (der Reiter-Zustand `tab` lebt am Modal, tab-init + modal.open-Detail, die Panels liegen im Body).
    $reiterGruppen = [
        ['basisrezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Concept', 'format' => 'Format', 'composer' => 'Composer', 'import' => 'Import'],
        ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'angebot' => 'Angebot'],
        ['worker' => 'Fortschritt'],
    ];
    $reiterKnopf = 'inline-flex items-center gap-1.5 h-10 px-3.5 shrink-0 text-[length:var(--fa-text-base)] font-medium border-b-2 -mb-px rounded-t-[var(--fa-radius-control)] transition-colors whitespace-nowrap focus-visible:-outline-offset-2';
    $reiterAn = 'border-[var(--fa-accent)] text-[var(--fa-accent)] font-semibold bg-[var(--fa-accent-soft)]';
    $reiterAus = 'border-transparent text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    // Ausgabe-Reiter: Auswahl links schmaler, Brief rechts breiter (ab xl nebeneinander).
    $ausgabeRaster = 'grid grid-cols-1 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 items-start';
    $hinweisText = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Planung" icon="heroicon-o-light-bulb" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Planung'],
        ]" />
    </x-slot>

    {{-- LINKS: Neue Planung, Suche, Filter, Liste nach Kategorie --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Planungen" width="w-80">
            <div class="p-3 flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <x-fa::input wire:model="neuTitel" wire:keydown.enter="neuePlanung" placeholder="Neue Planung benennen" aria-label="Name der neuen Planung" class="flex-1 min-w-0" />
                    <x-fa::button icon="heroicon-m-plus" wire:click="neuePlanung" title="Planung anlegen" aria-label="Planung anlegen" class="px-2.5" />
                </div>

                {{-- Suche + Filter (finale Etappe #17): filtern Liste UND Board. --}}
                <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]">
                    <div class="relative">
                        <label for="planung-suche" class="sr-only">Planungen durchsuchen</label>
                        @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                        <x-fa::input id="planung-suche" type="search" wire:model.live.debounce.300ms="sucheListe" placeholder="Planungen durchsuchen" class="pl-8" data-planung-suche />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <x-fa::select size="sm" wire:model.live="filterStatus" aria-label="Nach Status filtern" data-planung-filter-status>
                            <option value="">Alle Status</option>
                            <option value="entwurf">Entwurf</option>
                            <option value="läuft">Läuft</option>
                            <option value="prüfen">Prüfen</option>
                            <option value="fertig">Fertig</option>
                            <option value="fehlgeschlagen">Fehlgeschlagen</option>
                        </x-fa::select>
                        <x-fa::select size="sm" wire:model.live="filterTyp" aria-label="Nach Typ filtern" data-planung-filter-typ>
                            <option value="">Alle Typen</option>
                            <option value="rezept">Basisrezept</option>
                            <option value="gericht">Gericht</option>
                            <option value="concept">Concept</option>
                            <option value="foodbook">Foodbook</option>
                            <option value="speisekarte">Speisekarte</option>
                            <option value="speiseplan">Speiseplan</option>
                            <option value="offer">Angebot</option>
                            <option value="format">Format</option>
                        </x-fa::select>
                    </div>
                </div>

                <div class="flex flex-col gap-3 max-h-[calc(100vh-17rem)] min-h-40 overflow-y-auto -mx-1 px-1">
                    @forelse($baum as $ast)
                        <div wire:key="cat-{{ $loop->index }}" class="flex flex-col gap-0.5">
                            <p class="px-2 pb-0.5 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]">{{ $ast['category'] }}</p>
                            @foreach($ast['sessions'] as $s)
                                @php
                                    $istGewaehlt = $active && $active->id === $s->id;
                                    $stat = $kaskaden[$s->id]['status'] ?? 'entwurf';
                                @endphp
                                {{-- Zeile = wählbarer Button + beim Überfahren eingeblendeter Papierkorb (#17-Rest);
                                     kein verschachtelter Button (group-flex-div). --}}
                                <div wire:key="sess-{{ $s->id }}"
                                     class="group flex items-center gap-1 rounded-[var(--fa-radius-control)] {{ $istGewaehlt ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                                    <button type="button" wire:click="waehle({{ $s->id }})" x-on:click="$store.ui?.mSet('activity_planung', 'open', true)" @if($istGewaehlt) aria-current="true" @endif
                                            class="flex-1 min-w-0 flex items-center justify-between gap-2 text-left pl-2 pr-1 py-1.5 text-[length:var(--fa-text-md)] {{ $istGewaehlt ? 'text-[var(--fa-accent)] font-medium' : 'text-[var(--fa-ink)]' }}">
                                        <span class="flex items-center gap-2 min-w-0">
                                            @svg($typIcon($s), 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                            <span class="truncate" title="{{ $anzeigeTitel($s) }}">{{ $anzeigeTitel($s) }}</span>
                                        </span>
                                        <x-fa::badge :tone="$kaskadeStatusTon[$stat] ?? 'neutral'" class="shrink-0" data-planung-status="{{ $stat }}">
                                            @if($kaskadeLaeuft($s->id))<span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse" data-planung-puls></span>@endif
                                            {{ \Illuminate\Support\Str::ucfirst($stat) }}
                                        </x-fa::badge>
                                    </button>
                                    <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Planung verwerfen"
                                        wire:click="planungVerwerfen({{ $s->id }})" wire:confirm="{{ $verwerfenFrage }}"
                                        class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity" data-planung-listen-verwerfen="{{ $s->id }}" />
                                </div>
                            @endforeach
                        </div>
                    @empty
                        @if($sucheListe !== '' || $filterStatus !== '')
                            <x-fa::empty compact icon="heroicon-o-funnel" title="Keine Planung passt">Suche oder Filter lockern.</x-fa::empty>
                        @else
                            <x-fa::empty compact icon="heroicon-o-light-bulb" title="Noch keine Planungen">Oben eine Planung benennen, „Neu erstellen" wählen oder im Trendradar „In Planung öffnen".</x-fa::empty>
                        @endif
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE: Erstellen + Board --}}
    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        {{-- Leitstelle: freie Erstellung mit einem Klick, die eine KI-Erstell-Fläche. Legt eine leichte
             „Freie Erstellung"-Session (cockpit_frei) an und öffnet den Editor auf dem passenden Reiter.
             Trend bleibt EIN Input, nicht der Rahmen. --}}
        <div class="flex flex-col gap-4" x-data="{ fbOpen: @js($fbPanelAuf), skOpen: false, spOpen: false, offOpen: @js($offerPanelAuf), fmtOpen: false }">
            <x-fa::page-header title="Planung" subtitle="Basisrezepte, Gerichte und Concepts mit KI entwerfen, ganze Foodbooks, Karten und Angebote aus einem Brief planen.">
                <x-slot:actions>
                    {{-- Ein „Neu erstellen"-Knopf (Dominique 2026-08-23) statt vieler Knöpfe: Menü in drei Gruppen. --}}
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::button variant="primary" icon="heroicon-m-plus" icon-right="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-frei-neu>Neu erstellen</x-fa::button>
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1" data-frei-menu>
                            <p class="{{ $menueGruppe }}">Einzeln mit KI erstellen</p>
                            <button type="button" role="menuitem" wire:click="schnellErstellen('rezept')" x-on:click="offen = false" class="{{ $menuePunkt }}" data-frei-rezept>@svg('heroicon-o-beaker', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Basisrezept</button>
                            <button type="button" role="menuitem" wire:click="schnellErstellen('gericht')" x-on:click="offen = false" class="{{ $menuePunkt }}" data-frei-gericht>@svg('heroicon-o-cake', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Gericht</button>
                            <button type="button" role="menuitem" wire:click="schnellErstellen('concept')" x-on:click="offen = false" class="{{ $menuePunkt }}" data-frei-concept>@svg('heroicon-o-squares-2x2', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Concept</button>
                            <div class="my-1 border-t border-[var(--fa-line)]" role="separator"></div>
                            <p class="{{ $menueGruppe }}">Übernehmen und kombinieren</p>
                            <button type="button" role="menuitem" wire:click="schnellImport" x-on:click="offen = false" class="{{ $menuePunkt }}" data-frei-import>@svg('heroicon-o-document-arrow-down', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Rezept importieren</button>
                            <button type="button" role="menuitem" wire:click="schnellComposer" x-on:click="offen = false" class="{{ $menuePunkt }}" data-frei-composer>@svg('heroicon-o-sparkles', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Aus Zutaten kombinieren (Composer)</button>
                            <div class="my-1 border-t border-[var(--fa-line)]" role="separator"></div>
                            <p class="{{ $menueGruppe }}">Ganze Ausgabe aus einem Brief</p>
                            <button type="button" role="menuitem" x-on:click="fbOpen = true; skOpen = false; spOpen = false; offOpen = false; fmtOpen = false; offen = false" class="{{ $menuePunkt }}" data-frei-foodbook>@svg('heroicon-o-book-open', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Foodbook</button>
                            <button type="button" role="menuitem" x-on:click="skOpen = true; fbOpen = false; spOpen = false; offOpen = false; fmtOpen = false; offen = false" class="{{ $menuePunkt }}" data-frei-speisekarte>@svg('heroicon-o-clipboard-document-list', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Speisekarte</button>
                            <button type="button" role="menuitem" x-on:click="spOpen = true; fbOpen = false; skOpen = false; offOpen = false; fmtOpen = false; offen = false" class="{{ $menuePunkt }}" data-frei-speiseplan>@svg('heroicon-o-calendar-days', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Speiseplan</button>
                            <button type="button" role="menuitem" x-on:click="offOpen = true; fbOpen = false; skOpen = false; spOpen = false; fmtOpen = false; offen = false" class="{{ $menuePunkt }}" data-frei-angebot>@svg('heroicon-o-document-text', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Angebot</button>
                            <button type="button" role="menuitem" x-on:click="fmtOpen = true; fbOpen = false; skOpen = false; spOpen = false; offOpen = false; offen = false" class="{{ $menuePunkt }}" data-frei-format>@svg('heroicon-o-swatch', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Format</button>
                        </div>
                    </div>
                </x-slot:actions>
            </x-fa::page-header>

            {{-- Spec 42 F1: ein ganzes Foodbook aus einem Brief planen. Rahmen (Gerüst/Struktur) + Inhalte
                 entstehen HIER in der Leitstelle; das Foodbook ist reine Ausgabe. --}}
            <x-fa::section x-show="fbOpen" x-cloak icon="heroicon-o-book-open" title="Foodbook aus einem Brief planen"
                description="Struktur und Inhalte entstehen hier in der Leitstelle und landen automatisch im Foodbook." data-foodbook-brief-panel>
                <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="fbOpen = false" /></x-slot:actions>
                <div class="flex flex-col gap-3 max-w-3xl">
                    @if($fbOwnerId)
                        <x-fa::notice tone="info" data-fb-owner-hinweis>Planung für ein bestehendes Foodbook. Brief eingeben, Struktur und Inhalte entstehen hier und landen dort.</x-fa::notice>
                    @else
                        <x-fa::field label="Name des Foodbooks" optional>
                            <x-fa::input wire:model="fbTitel" placeholder="zum Beispiel Sommerfest Adler" data-fb-titel />
                        </x-fa::field>
                    @endif
                    <x-fa::field label="Brief">
                        <x-fa::textarea wire:model="fbBrief" rows="3" placeholder="Anlass, Gäste, Saison, Niveau, Budget …" data-fb-brief />
                    </x-fa::field>
                    @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fbBrief'])
                    @if($fbMeldung)<x-fa::signal tone="crit" data-fb-meldung>{{ $fbMeldung }}</x-fa::signal>@endif
                    <div>
                        <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief" data-fb-erzeugen>
                            <span wire:loading.remove wire:target="foodbookAusBrief">Foodbook erstellen</span>
                            <span wire:loading wire:target="foodbookAusBrief">Wird erstellt …</span>
                        </x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            {{-- Speisekarte aus Brief (gleiche Bauart wie Foodbook) --}}
            <x-fa::section x-show="skOpen" x-cloak icon="heroicon-o-clipboard-document-list" title="Speisekarte aus einem Brief planen"
                description="Je Gang oder Kategorie entsteht eine Rubrik, die Inhalte landen automatisch in der Karte." data-speisekarte-brief-panel>
                <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="skOpen = false" /></x-slot:actions>
                <div class="flex flex-col gap-3 max-w-3xl">
                    <x-fa::field label="Name der Speisekarte" optional>
                        <x-fa::input wire:model="skTitel" placeholder="zum Beispiel Herbstkarte" data-landing-sk-titel />
                    </x-fa::field>
                    <x-fa::field label="Brief">
                        <x-fa::textarea wire:model="skBrief" rows="3" placeholder="Anlass, Küchenstil, Saison, Niveau, Preisrahmen …" data-landing-sk-brief />
                    </x-fa::field>
                    @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'skBrief'])
                    @if($skMeldung)<x-fa::signal tone="crit" data-landing-sk-meldung>{{ $skMeldung }}</x-fa::signal>@endif
                    <div>
                        <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" data-landing-sk-erzeugen>
                            <span wire:loading.remove wire:target="speisekarteAusBrief">Speisekarte erstellen</span>
                            <span wire:loading wire:target="speisekarteAusBrief">Wird erstellt …</span>
                        </x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            {{-- Speiseplan aus Brief (gleiche Bauart wie Foodbook) --}}
            <x-fa::section x-show="spOpen" x-cloak icon="heroicon-o-calendar-days" title="Speiseplan aus einem Brief planen"
                description="Menülinien und Zyklus entstehen als Standard für die Gemeinschaftsverpflegung, die Zellen werden nach dem Brief gefüllt." data-speiseplan-brief-panel>
                <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="spOpen = false" /></x-slot:actions>
                <div class="flex flex-col gap-3 max-w-3xl">
                    <x-fa::field label="Name des Speiseplans" optional>
                        <x-fa::input wire:model="spTitel" placeholder="zum Beispiel Kantine Herbst" data-landing-sp-titel />
                    </x-fa::field>
                    <x-fa::field label="Brief">
                        <x-fa::textarea wire:model="spBrief" rows="3" placeholder="Anlass, Saison, Küchenstil, Zyklus (zum Beispiel 4 Wochen), Ernährungsschwerpunkt …" data-landing-sp-brief />
                    </x-fa::field>
                    @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'spBrief'])
                    @if($spMeldung)<x-fa::signal tone="crit" data-landing-sp-meldung>{{ $spMeldung }}</x-fa::signal>@endif
                    <div>
                        <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" data-landing-sp-erzeugen>
                            <span wire:loading.remove wire:target="speiseplanAusBrief">Speiseplan erstellen</span>
                            <span wire:loading wire:target="speiseplanAusBrief">Wird erstellt …</span>
                        </x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            {{-- #5 (2026-08-28): Angebot aus Brief (gleiche Bauart wie Speisekarte) --}}
            <x-fa::section x-show="offOpen" x-cloak icon="heroicon-o-document-text" title="Angebot aus einem Brief planen"
                description="Je Position entsteht ein Concept, das automatisch im Angebot landet. Preise folgen im Angebots-Editor." data-angebot-brief-panel>
                <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="offOpen = false" /></x-slot:actions>
                <div class="flex flex-col gap-3 max-w-3xl">
                    @if($offerOwnerId)
                        <x-fa::notice tone="info" data-offer-owner-hinweis>Planung für ein bestehendes Angebot. Brief eingeben, die Concepts entstehen hier und landen dort.</x-fa::notice>
                    @else
                        <x-fa::field label="Name des Angebots" optional>
                            <x-fa::input wire:model="offerTitel" placeholder="zum Beispiel Sommerfest Firma Adler" data-landing-offer-titel />
                        </x-fa::field>
                    @endif
                    <x-fa::field label="Brief">
                        <x-fa::textarea wire:model="offerBrief" rows="3" placeholder="Anlass, Personen, Saison, Niveau, Budget, Servierform …" data-landing-offer-brief />
                    </x-fa::field>
                    @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'offerBrief'])
                    @if($offerMeldung)<x-fa::signal tone="crit" data-landing-offer-meldung>{{ $offerMeldung }}</x-fa::signal>@endif
                    <div>
                        <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="angebotAusBrief" wire:loading.attr="disabled" wire:target="angebotAusBrief" data-landing-offer-erzeugen>
                            <span wire:loading.remove wire:target="angebotAusBrief">Angebot erstellen</span>
                            <span wire:loading wire:target="angebotAusBrief">Wird erstellt …</span>
                        </x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            {{-- Format aus Brief (gleiche Bauart wie Angebot): gebrandetes Foodkonzept. --}}
            <x-fa::section x-show="fmtOpen" x-cloak icon="heroicon-o-swatch" title="Format aus einem Brief planen"
                description="Marken-Identität und je Position ein Concept entstehen hier und landen automatisch im Format." data-format-brief-panel>
                <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="fmtOpen = false" /></x-slot:actions>
                <div class="flex flex-col gap-3 max-w-3xl">
                    @if($fmtOwnerId)
                        <x-fa::notice tone="info" data-fmt-owner-hinweis>Planung für ein bestehendes Format. Brief eingeben, die Concepts entstehen hier und landen dort.</x-fa::notice>
                    @else
                        <x-fa::field label="Name des Formats" optional>
                            <x-fa::input wire:model="fmtTitel" placeholder="zum Beispiel Streetfood Markt" data-landing-fmt-titel />
                        </x-fa::field>
                    @endif
                    <x-fa::field label="Brief">
                        <x-fa::textarea wire:model="fmtBrief" rows="3" placeholder="Marke, Anlass, Ausrichtung, Zielgruppe, Niveau, Stationen oder Gänge …" data-landing-fmt-brief />
                    </x-fa::field>
                    @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fmtBrief'])
                    @if($fmtMeldung)<x-fa::signal tone="crit" data-landing-fmt-meldung>{{ $fmtMeldung }}</x-fa::signal>@endif
                    <div>
                        <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="formatAusBrief" wire:loading.attr="disabled" wire:target="formatAusBrief" data-landing-fmt-erzeugen>
                            <span wire:loading.remove wire:target="formatAusBrief">Format erstellen</span>
                            <span wire:loading wire:target="formatAusBrief">Wird erstellt …</span>
                        </x-fa::button>
                    </div>
                </div>
            </x-fa::section>
        </div>

        {{-- Board (Leitstelle-Übersicht). Immer sichtbar, auch bei gewählter Session (Karte-Klick füllt NUR
             die rechte Detail-Spalte + markiert die Karte). Der linke Filter grenzt ein: dieselbe gefilterte
             $sessions-Menge landet in den Status-Spalten. „Öffnen" = Editor. Poll nur, wenn tatsächlich
             etwas läuft (kein Dauer-Poll).
             Laptop: jede Spalte hat eine Mindestbreite; reicht die Breite nicht für fünf, scrollt das Board
             waagerecht, statt die Karten unlesbar schmal zu drücken. --}}
        <div class="flex flex-col gap-3" data-planung-board {{ $irgendeinLaeuft ? 'wire:poll.3s' : '' }}>
            @include('foodalchemist::livewire.planung.partials.board-worker-kopf')

            @php
                $spalten = ['entwurf' => 'Entwurf', 'läuft' => 'Läuft', 'prüfen' => 'Zu prüfen', 'fertig' => 'Fertig', 'fehlgeschlagen' => 'Fehlgeschlagen'];
                $nachStatus = $sessions->groupBy(fn ($s) => $kaskaden[(int) $s->id]['status'] ?? 'entwurf');
                $spaltenPunkt = ['entwurf' => 'bg-[var(--fa-ink-3)]', 'läuft' => 'bg-[var(--fa-info)]', 'prüfen' => 'bg-[var(--fa-warn)]', 'fertig' => 'bg-[var(--fa-ok)]', 'fehlgeschlagen' => 'bg-[var(--fa-crit)]'];
            @endphp

            @if($sessions->count() === 0)
                <div class="fa-surface" data-planung-board-leer>
                    <x-fa::empty icon="heroicon-o-view-columns" title="Noch keine Planungen">Oben „Neu erstellen" wählen. Jede Planung erscheint hier in der Spalte ihres Stands.</x-fa::empty>
                </div>
            @else
                <div class="overflow-x-auto -mx-1 px-1 pb-1">
                    <div class="grid grid-cols-1 gap-3 items-start md:grid-cols-none md:grid-flow-col md:auto-cols-[minmax(14rem,1fr)]" data-planung-board-spalten>
                        @foreach($spalten as $key => $label)
                            @php $spaltenSessions = ($nachStatus[$key] ?? collect())->values(); @endphp
                            <section class="min-w-0 flex flex-col gap-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-neutral-soft)] p-2" data-planung-spalte="{{ $key }}" aria-label="{{ $label }}">
                                <header class="flex items-center gap-2 px-1 pt-0.5">
                                    <span class="w-2 h-2 rounded-full {{ $spaltenPunkt[$key] }}"></span>
                                    <h3 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $label }}</h3>
                                    <span class="ml-auto text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] tabular-nums" data-planung-spalte-count>{{ $spaltenSessions->count() }}</span>
                                </header>
                                <div class="flex flex-col gap-2">
                                    @forelse($spaltenSessions as $s)
                                        @include('foodalchemist::livewire.planung.partials.board-karte', ['s' => $s])
                                    @empty
                                        <p class="px-1 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Planung</p>
                                    @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>{{-- /board --}}
    </x-ui-page-container>

    {{-- RECHTS: Details --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" :maxWidth="560" scope="activity_planung" side="right">
            {{-- Anatomie Detail-Panels (DESIGN.md): Kopf (Ergebnis-Name, Stand, „Im Editor öffnen", Verwerfen im Menü)
                 · offene Punkte · Fortschritt je Stufe. --}}
            <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                @if($active)
                    @php
                        $aktStufen = $kaskaden[$active->id]['stufen'] ?? [];
                        $aktKaskade = $kaskaden[(int) $active->id]['status'] ?? 'entwurf';
                        $aktPruefen = collect($aktStufen)->filter(fn ($st) => ($st['zustand'] ?? null) === 'prüfen');
                    @endphp
                    <x-fa::detail-kopf :title="$anzeigeTitel($active)"
                        :subtitle="$active->source_knowledge_document_id ? 'Herkunft: Trendradar, Eintrag ' . $active->source_knowledge_document_id : 'Herkunft: Freier Brief'">
                        @if(filled($active->title) && trim((string) $active->title) !== $anzeigeTitel($active))
                            <p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">Planung: {{ $active->title }}</p>
                        @endif
                        <x-slot:badges>
                            <x-fa::badge :tone="$kaskadeStatusTon[$aktKaskade] ?? 'neutral'">{{ \Illuminate\Support\Str::ucfirst($aktKaskade) }}</x-fa::badge>
                            <x-fa::badge tone="accent">{{ $statusLabel[$active->status] ?? $active->status }}</x-fa::badge>
                            <x-fa::badge title="Kreativ-Modus">{{ $modeLabel[$active->creative_mode] ?? $active->creative_mode }}</x-fa::badge>
                        </x-slot:badges>
                        <x-slot:aktion>
                            <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square" wire:click="oeffne({{ $active->id }})">Im Editor öffnen</x-fa::button>
                        </x-slot:aktion>
                        <x-slot:menue>
                            <x-fa::menu-item danger icon="heroicon-m-trash"
                                wire:click="planungVerwerfen({{ $active->id }})" wire:confirm="{{ $verwerfenFrage }}" data-planung-details-verwerfen>Planung verwerfen</x-fa::menu-item>
                        </x-slot:menue>
                    </x-fa::detail-kopf>

                    @if($aktKaskade === 'fehlgeschlagen' || $aktPruefen->isNotEmpty())
                        <div class="flex flex-col gap-1">
                            @if($aktKaskade === 'fehlgeschlagen')
                                <x-fa::signal tone="crit">Die letzte Erstellung ist fehlgeschlagen.</x-fa::signal>
                            @endif
                            @foreach($aktPruefen as $st)
                                <x-fa::signal tone="warn">{{ $st['label'] }}: Ergebnisse prüfen.</x-fa::signal>
                            @endforeach
                        </div>
                    @endif

                    {{-- Stand je Stufe, ohne den Editor zu öffnen (finale Etappe). --}}
                    <div class="flex flex-col">
                        <x-fa::section variant="plain" title="Fortschritt je Stufe" icon="heroicon-o-queue-list" data-planung-kaskadenstand>
                            @if($aktStufen === [])
                                <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch nichts erstellt">Im Editor einen Brief formulieren und die Erstellung starten.</x-fa::empty>
                            @else
                                <ul class="flex flex-col">
                                    @foreach($aktStufen as $st)
                                        <li class="flex items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)] last:border-0 text-[length:var(--fa-text-md)]">
                                            <span class="text-[var(--fa-ink)]">{{ $st['label'] }}</span>
                                            <span class="flex items-center gap-2">
                                                <span class="text-[var(--fa-ink-3)] tabular-nums">{{ $st['fertig'] }}/{{ $st['total'] }}</span>
                                                <x-fa::badge :tone="$zustandTon[$st['zustand']] ?? 'neutral'">{{ \Illuminate\Support\Str::ucfirst($st['zustand']) }}</x-fa::badge>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-fa::section>
                    </div>
                @else
                    <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Keine Planung gewählt">Eine Karte anklicken, um Herkunft, Stand und Fortschritt zu sehen.</x-fa::empty>
                @endif
            </div>
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- VOLLBILD-EDITOR (Werkbank-Modus) --}}
    <x-foodalchemist::modal name="planung-editor" fullscreen dark-canvas title="Planung"
                            :title-name="$active ? $anzeigeTitel($active) : null" tab-init="gericht">
        <x-slot:actions>
            {{-- Speichern sichert Titel und Eingaben der Planung. Sekundär: die Hauptaktion jedes Reiters
                 ist das Erstellen in der Leiste unten. --}}
            <x-fa::button icon="heroicon-m-check" wire:click="speichern">Planung speichern</x-fa::button>
            @if($meldung !== null)
                <x-fa::signal tone="ok">{{ $meldung }}</x-fa::signal>
            @endif
            @if($fehler !== null)
                <x-fa::signal tone="crit">{{ $fehler }}</x-fa::signal>
            @endif
            @if($margenWarnung !== null)
                <x-fa::signal tone="warn" data-margen-warnung>{{ $margenWarnung }}</x-fa::signal>
            @endif
            {{-- Was der Lauf NICHT erzeugt hat: eine Warnung, kein Fehler und kein Erfolg. MUSS innerhalb von
                 x-slot:actions stehen; dahinter beginnt die Reiterleiste, dort landete der Hinweis in der
                 falschen Modal-Zone. --}}
            @if($deckelHinweis !== null)
                <x-fa::signal tone="warn" data-deckel-hinweis>{{ $deckelHinweis }}</x-fa::signal>
            @endif
            {{-- Spec 53 / Paket C: globaler KI-Status, sichtbar auf JEDEM Reiter (x-slot:actions
                 überlebt den Reiterwechsel; die Reiterleiste beginnt erst danach). --}}
            <div class="ml-auto">
                @include('foodalchemist::livewire.planung.partials.ki-status-leiste', ['klickbar' => true])
            </div>
        </x-slot:actions>

        <x-slot:tabs>
            {{-- Analyse + Skizzen (Spec-40-E0-Ideations-Einstieg) zurückgezogen (Dominique 2026-08-23):
                 abgelöst durch Composer (geerdet) + Brief-Kaskaden. DishIdea/IdeenService bleibt intern.
                 Gruppen: Erstellen · Ausgabe-Formen (Spec-42-Vollzug) · Fortschritt bewusst am Ende
                 (Dominique 2026-08-24: erst erstellen, dann beobachten).
                 Laptop: die Leiste scrollt waagerecht, statt in zwei Zeilen umzubrechen. --}}
            <div class="flex items-center gap-1 overflow-x-auto -mb-px" role="tablist" aria-label="Bereiche der Planung">
                @foreach($reiterGruppen as $gruppe)
                    @if(! $loop->first)<span class="mx-1.5 h-5 w-px shrink-0 bg-[var(--fa-line-strong)]" aria-hidden="true"></span>@endif
                    @foreach($gruppe as $reiterKey => $reiterLabel)
                        <button type="button" role="tab" x-on:click="tab='{{ $reiterKey }}'"
                                x-bind:aria-selected="tab==='{{ $reiterKey }}'"
                                x-bind:class="tab==='{{ $reiterKey }}' ? '{{ $reiterAn }}' : '{{ $reiterAus }}'"
                                class="{{ $reiterKnopf }}" data-planung-reiter="{{ $reiterKey }}">{{ $reiterLabel }}@if($reiterKey === 'worker' && $laeuft)<span class="w-2 h-2 rounded-full bg-[var(--fa-warn)] animate-pulse" title="läuft gerade"></span>@endif</button>
                    @endforeach
                @endforeach
            </div>
        </x-slot:tabs>

        @if($active)
            @if(($ownerKontext ?? null))
                {{-- E1b (Spec 40): Owner-Kontext: WOFÜR hier geplant wird + Rückweg ins Ausgabe-Modul. --}}
                <x-fa::notice tone="info" class="mb-3">
                    Planung für {{ $ownerKontext['typ_label'] }} „{{ $ownerKontext['name'] }}". Was hier entsteht, landet automatisch dort.
                    <x-slot:actions>
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-left" :href="route($ownerKontext['route'], $ownerKontext['route_param'])">Zurück zum {{ $ownerKontext['typ_label'] }}</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif
            {{-- AUSGABE-FORMEN (Spec-42-Vollzug): aus einem Brief plant die Leitstelle die ganze Ausgabeform
                 (owner-getaggte Voll-Kaskade), die Inhalte landen automatisch dort. Gleiche Bauart überall:
                 Auswahl links (schmaler), Brief rechts, ab xl nebeneinander. Jede Karte steckt in einem eigenen
                 Wrapper, damit modal-section ohne Abstand-Überschreibung oben bündig sitzt. --}}
            <div wire:key="planung-tab-foodbook" x-show="tab==='foodbook'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
                <div class="{{ $ausgabeRaster }}">
                    {{-- Stage 2 (Dominique): ein BESTEHENDES Foodbook wählen → Gerüst planen → Kapitel einzeln
                         erstellen. Oder leer lassen = neues Foodbook aus Brief. --}}
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-book-open" title="Foodbook wählen">
                            <x-slot:actions>
                                <x-fa::badge :tone="$fbOwnerId ? 'accent' : 'neutral'">{{ $fbOwnerId ? 'Bestehendes Foodbook' : 'Neu aus Brief' }}</x-fa::badge>
                            </x-slot:actions>
                            <x-fa::field label="Bestehendes Foodbook" hint="Gewählt: Gerüst planen und die Kapitel unten einzeln füllen. Leer: ein neues Foodbook aus dem Brief.">
                                <x-fa::select wire:model.live="fbOwnerId" data-tab-fb-auswahl>
                                    <option value="">Neues Foodbook aus Brief</option>
                                    @foreach($fbAuswahl as $fbo)<option value="{{ $fbo->id }}">{{ $fbo->label }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </x-foodalchemist::modal-section>
                    </div>
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" :title="$fbOwnerId ? 'Gerüst aus dem Brief planen' : 'Neues Foodbook aus dem Brief'">
                            @if(trim((string) ($fbTitel ?? '')) !== '')
                                <x-slot:actions>
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($fbTitel), 32) }}</x-fa::badge>
                                </x-slot:actions>
                            @endif
                            <div class="flex flex-col gap-3">
                                <p class="{{ $hinweisText }}">Struktur (Kapitel) und Inhalte entstehen hier in der Leitstelle und landen automatisch im Foodbook.</p>
                                @unless($fbOwnerId)
                                    <x-fa::field label="Name des Foodbooks" optional>
                                        <x-fa::input wire:model="fbTitel" placeholder="zum Beispiel Sommerfest Adler" data-tab-fb-titel />
                                    </x-fa::field>
                                @endunless
                                <x-fa::field label="Brief">
                                    <x-fa::textarea wire:model="fbBrief" rows="4" placeholder="Anlass, Gäste, Saison, Niveau, Budget …" data-tab-fb-brief />
                                </x-fa::field>
                                @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fbBrief'])
                                {{-- Leerer Anker für Spec 55 Nachtrag 2 (Entscheid 03): heute bleibt das schlichte Diktat
                                     darüber die EINZIGE Diktierfunktion dieses Reiters. Der Anker steht schon, damit der
                                     Umzug des Agent-Panels später nur ein Einhängen ist. --}}
                                <div data-planung-agent-slot="foodbook"></div>
                                @if($fbMeldung)<x-fa::signal tone="crit" data-tab-fb-meldung>{{ $fbMeldung }}</x-fa::signal>@endif
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief" data-tab-fb-erzeugen>
                                        <span wire:loading.remove wire:target="foodbookAusBrief">{{ $fbOwnerId ? 'Gerüst planen und füllen' : 'Foodbook erstellen' }}</span>
                                        <span wire:loading wire:target="foodbookAusBrief">Wird erstellt …</span>
                                    </x-fa::button>
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </div>

                {{-- Buch-Ebene + Kapitel (aus dem Foodbook-Modul verschobene Planung): sobald ein Foodbook
                     GEWÄHLT ist (fbOwnerId) ODER die aktive Session ein Foodbook ist. Die Rails brauchen nur
                     die foodbook-id; „Kapitel erstellen" verlangt ein Gerüst (sonst Hinweis in der Rail). --}}
                @php
                    $fbAktiv = $fbOwnerId;
                    if ($fbAktiv === null && ($ownerKontext['owner_type'] ?? null) === 'foodbook') {
                        $fbAktiv = (int) $ownerKontext['owner_id'];
                    }
                @endphp
                {{-- Die beiden Rail-Karten bleiben volle Breite: sie hosten eigene Livewire-Komponenten. --}}
                @if($fbAktiv !== null)
                    <x-foodalchemist::modal-section icon="heroicon-o-adjustments-horizontal" title="Vorgaben für das ganze Foodbook">
                        <livewire:foodalchemist.planung.foodbook-kontext-rail
                            :foodbook-id="$fbAktiv"
                            :key="'fbkontext-'.$fbAktiv" />
                    </x-foodalchemist::modal-section>
                    <x-foodalchemist::modal-section icon="heroicon-o-list-bullet" title="Kapitel">
                        <livewire:foodalchemist.planung.kapitel-rail
                            :foodbook-id="$fbAktiv"
                            :session-id="$sessionId"
                            :key="'kaprail-'.$fbAktiv" />
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            <div wire:key="planung-tab-speisekarte" x-show="tab==='speisekarte'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
                <div class="{{ $ausgabeRaster }}">
                    {{-- Stage 2 (Parität Speisekarte/Speiseplan): bestehende Speisekarte wählen ODER neu aus Brief. --}}
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-clipboard-document-list" title="Speisekarte wählen">
                            <x-slot:actions>
                                <x-fa::badge :tone="$skOwnerId ? 'accent' : 'neutral'">{{ $skOwnerId ? 'Bestehende Karte' : 'Neu aus Brief' }}</x-fa::badge>
                            </x-slot:actions>
                            <x-fa::field label="Bestehende Speisekarte" hint="Gewählt: Rubriken und Inhalte werden für diese Karte geplant. Leer: eine neue Speisekarte.">
                                <x-fa::select wire:model.live="skOwnerId" data-tab-sk-auswahl>
                                    <option value="">Neue Speisekarte aus Brief</option>
                                    @foreach($skAuswahl as $sko)<option value="{{ $sko->id }}">{{ $sko->name }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </x-foodalchemist::modal-section>
                    </div>
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" :title="$skOwnerId ? 'Gewählte Speisekarte aus dem Brief füllen' : 'Neue Speisekarte aus dem Brief'">
                            @if(trim((string) ($skTitel ?? '')) !== '')
                                <x-slot:actions>
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($skTitel), 32) }}</x-fa::badge>
                                </x-slot:actions>
                            @endif
                            <div class="flex flex-col gap-3">
                                <p class="{{ $hinweisText }}">Je Gang oder Kategorie entsteht eine Rubrik, die Inhalte landen automatisch als Positionen in der Karte.</p>
                                @unless($skOwnerId)
                                    <x-fa::field label="Name der Speisekarte" optional>
                                        <x-fa::input wire:model="skTitel" placeholder="zum Beispiel Herbstkarte" data-tab-sk-titel />
                                    </x-fa::field>
                                @endunless
                                <x-fa::field label="Brief">
                                    <x-fa::textarea wire:model="skBrief" rows="4" placeholder="Anlass, Küchenstil, Saison, Niveau, Preisrahmen …" data-tab-sk-brief />
                                </x-fa::field>
                                @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'skBrief'])
                                <div data-planung-agent-slot="speisekarte"></div>
                                @if($skMeldung)<x-fa::signal tone="crit" data-tab-sk-meldung>{{ $skMeldung }}</x-fa::signal>@endif
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" data-tab-sk-erzeugen>
                                        <span wire:loading.remove wire:target="speisekarteAusBrief">{{ $skOwnerId ? 'Speisekarte planen und füllen' : 'Speisekarte erstellen' }}</span>
                                        <span wire:loading wire:target="speisekarteAusBrief">Wird erstellt …</span>
                                    </x-fa::button>
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </div>
            </div>

            <div wire:key="planung-tab-speiseplan" x-show="tab==='speiseplan'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
                <div class="{{ $ausgabeRaster }}">
                    {{-- Stage 2 (Parität Speisekarte/Speiseplan): bestehenden Speiseplan wählen ODER neu aus Brief. --}}
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-calendar-days" title="Speiseplan wählen">
                            <x-slot:actions>
                                <x-fa::badge :tone="$spOwnerId ? 'accent' : 'neutral'">{{ $spOwnerId ? 'Bestehender Plan' : 'Neu aus Brief' }}</x-fa::badge>
                            </x-slot:actions>
                            <x-fa::field label="Bestehender Speiseplan" hint="Gewählt: die Zellen (Tag, Mahlzeit, Linie) werden für diesen Plan gefüllt. Leer: ein neuer Speiseplan.">
                                <x-fa::select wire:model.live="spOwnerId" data-tab-sp-auswahl>
                                    <option value="">Neuer Speiseplan aus Brief</option>
                                    @foreach($spAuswahl as $spo)<option value="{{ $spo->id }}">{{ $spo->name }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </x-foodalchemist::modal-section>
                    </div>
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" :title="$spOwnerId ? 'Gewählten Speiseplan aus dem Brief füllen' : 'Neuer Speiseplan aus dem Brief'">
                            @if(trim((string) ($spTitel ?? '')) !== '')
                                <x-slot:actions>
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($spTitel), 32) }}</x-fa::badge>
                                </x-slot:actions>
                            @endif
                            <div class="flex flex-col gap-3">
                                <p class="{{ $hinweisText }}">
                                    @unless($spOwnerId)Menülinien (Menü 1, Vegetarisch, Dessert) und Zyklus entstehen als Standard für die Gemeinschaftsverpflegung und bleiben im Speiseplan-Editor frei änderbar. @endunless
                                    Jede Zelle (Tag, Mahlzeit, Linie) wird nach dem Brief gefüllt.
                                </p>
                                @unless($spOwnerId)
                                    <x-fa::field label="Name des Speiseplans" optional>
                                        <x-fa::input wire:model="spTitel" placeholder="zum Beispiel Kantine Herbst" data-tab-sp-titel />
                                    </x-fa::field>
                                @endunless
                                <x-fa::field label="Brief">
                                    <x-fa::textarea wire:model="spBrief" rows="4" placeholder="Anlass, Saison, Küchenstil, Zyklus (zum Beispiel 4 Wochen), Ernährungsschwerpunkt …" data-tab-sp-brief />
                                </x-fa::field>
                                @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'spBrief'])
                                <div data-planung-agent-slot="speiseplan"></div>
                                @if($spMeldung)<x-fa::signal tone="crit" data-tab-sp-meldung>{{ $spMeldung }}</x-fa::signal>@endif
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" data-tab-sp-erzeugen>
                                        <span wire:loading.remove wire:target="speiseplanAusBrief">{{ $spOwnerId ? 'Speiseplan füllen' : 'Speiseplan erstellen' }}</span>
                                        <span wire:loading wire:target="speiseplanAusBrief">Wird erstellt …</span>
                                    </x-fa::button>
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </div>
            </div>

            {{-- Angebot als Ausgabe-Reiter: 1 Concept je Position → landen im Angebot (owner_type=offer). --}}
            <div wire:key="planung-tab-angebot" x-show="tab==='angebot'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
                <div class="{{ $ausgabeRaster }}">
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-document-currency-euro" title="Angebot wählen">
                            <x-slot:actions>
                                <x-fa::badge :tone="$offerOwnerId ? 'accent' : 'neutral'">{{ $offerOwnerId ? 'Bestehendes Angebot' : 'Neu aus Brief' }}</x-fa::badge>
                            </x-slot:actions>
                            <x-fa::field label="Bestehendes Angebot" hint="Gewählt: die Positionen entstehen für dieses Angebot. Leer: ein neues Angebot.">
                                <x-fa::select wire:model.live="offerOwnerId" data-tab-offer-auswahl>
                                    <option value="">Neues Angebot aus Brief</option>
                                    @foreach($offerAuswahl as $ao)<option value="{{ $ao->id }}">{{ $ao->name }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </x-foodalchemist::modal-section>
                    </div>
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" :title="$offerOwnerId ? 'Gewähltes Angebot aus dem Brief füllen' : 'Neues Angebot aus dem Brief'">
                            @if(trim((string) ($offerTitel ?? '')) !== '')
                                <x-slot:actions>
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($offerTitel), 32) }}</x-fa::badge>
                                </x-slot:actions>
                            @endif
                            <div class="flex flex-col gap-3">
                                <p class="{{ $hinweisText }}">Je Position ein Concept, die Concepts landen automatisch im Angebot.</p>
                                @unless($offerOwnerId)
                                    <x-fa::field label="Name des Angebots" optional>
                                        <x-fa::input wire:model="offerTitel" placeholder="zum Beispiel Sommerfest Firma Adler" data-tab-offer-titel />
                                    </x-fa::field>
                                @endunless
                                <x-fa::field label="Brief">
                                    <x-fa::textarea wire:model="offerBrief" rows="4" placeholder="Anlass, Personen, Saison, Niveau, Budget, Servierform …" data-tab-offer-brief />
                                </x-fa::field>
                                @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'offerBrief'])
                                <div data-planung-agent-slot="angebot"></div>
                                @if($offerMeldung)<x-fa::signal tone="crit" data-tab-offer-meldung>{{ $offerMeldung }}</x-fa::signal>@endif
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="angebotAusBrief" wire:loading.attr="disabled" wire:target="angebotAusBrief" data-tab-offer-erzeugen>
                                        <span wire:loading.remove wire:target="angebotAusBrief">{{ $offerOwnerId ? 'Angebot füllen' : 'Angebot erstellen' }}</span>
                                        <span wire:loading wire:target="angebotAusBrief">Wird erstellt …</span>
                                    </x-fa::button>
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </div>
            </div>

            {{-- Format als Ausgabe-Reiter: gebrandetes Foodkonzept, 1 Concept je Position → landen im Format (owner_type=format). --}}
            <div wire:key="planung-tab-format" x-show="tab==='format'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
                <div class="{{ $ausgabeRaster }}">
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-rectangle-stack" title="Format wählen">
                            <x-slot:actions>
                                <x-fa::badge :tone="$fmtOwnerId ? 'accent' : 'neutral'">{{ $fmtOwnerId ? 'Bestehendes Format' : 'Neu aus Brief' }}</x-fa::badge>
                            </x-slot:actions>
                            <x-fa::field label="Bestehendes Format" hint="Gewählt: die Concepts entstehen für dieses Format. Leer: ein neues, gebrandetes Format mit Name, Claim und Geschichte aus dem Brief.">
                                <x-fa::select wire:model.live="fmtOwnerId" data-tab-fmt-auswahl>
                                    <option value="">Neues Format aus Brief</option>
                                    @foreach($fmtAuswahl as $fo)<option value="{{ $fo->id }}">{{ $fo->name }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </x-foodalchemist::modal-section>
                    </div>
                    <div>
                        <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" :title="$fmtOwnerId ? 'Gewähltes Format aus dem Brief füllen' : 'Neues Format aus dem Brief'">
                            @if(trim((string) ($fmtTitel ?? '')) !== '')
                                <x-slot:actions>
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($fmtTitel), 32) }}</x-fa::badge>
                                </x-slot:actions>
                            @endif
                            <div class="flex flex-col gap-3">
                                <p class="{{ $hinweisText }}">Marken-Identität und eigenständige Concepts je Position entstehen hier und landen automatisch im Format.</p>
                                @unless($fmtOwnerId)
                                    <x-fa::field label="Name des Formats" optional>
                                        <x-fa::input wire:model="fmtTitel" placeholder="zum Beispiel Streetfood Markt" data-tab-fmt-titel />
                                    </x-fa::field>
                                @endunless
                                <x-fa::field label="Brief">
                                    <x-fa::textarea wire:model="fmtBrief" rows="4" placeholder="Marke, Anlass, Ausrichtung, Zielgruppe, Niveau, Stationen oder Gänge …" data-tab-fmt-brief />
                                </x-fa::field>
                                @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fmtBrief'])
                                <div data-planung-agent-slot="format"></div>
                                @if($fmtMeldung)<x-fa::signal tone="crit" data-tab-fmt-meldung>{{ $fmtMeldung }}</x-fa::signal>@endif
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="formatAusBrief" wire:loading.attr="disabled" wire:target="formatAusBrief" data-tab-fmt-erzeugen>
                                        <span wire:loading.remove wire:target="formatAusBrief">{{ $fmtOwnerId ? 'Format füllen' : 'Format erstellen' }}</span>
                                        <span wire:loading wire:target="formatAusBrief">Wird erstellt …</span>
                                    </x-fa::button>
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </div>
            </div>

            {{-- IMPORT: bestehende Rezeptur (Text, Web-Kopie, Text-PDF) TREU übernehmen und mit Grundprodukten
                 verknüpft anlegen. Getrennt vom Generator (der veredelt): hier 1:1 übernehmen. Foto/Bild NICHT hier
                 (Vision noch nicht in der Plattform-LLM). Schritt 2 hat eine klebende Anlegen-Leiste (eine Zeile). --}}
            <div wire:key="planung-tab-import" x-show="tab==='import'" class="flex flex-col gap-4 max-w-7xl mx-auto">
                @if($importStep === 'eingabe')
                    <x-foodalchemist::modal-section icon="heroicon-o-arrow-down-tray" title="Rezept importieren">
                        <x-slot:actions>
                            <x-fa::badge>Schritt 1 von 3</x-fa::badge>
                        </x-slot:actions>
                        <div class="flex flex-col gap-4">
                            <p class="{{ $hinweisText }} max-w-2xl">
                                Bestehendes Rezept einfügen oder als Text-PDF hochladen. Es wird wörtlich übernommen, nichts
                                wird erfunden, und die Zutaten werden mit den Grundprodukten verknüpft. Verschachtelte Rezepte
                                (Gericht mit Sauce oder Püree) werden als verknüpfte Unterrezepte angelegt.
                            </p>
                            <x-fa::field label="Anlegen als" hint="Nach dem Lesen wird ein Vorschlag gesetzt, deine Wahl hat Vorrang.">
                                <x-fa::choice name="importTyp" :live="false" id-prefix="import-eingabe" :options="['basisrezept' => 'Basisrezept', 'gericht' => 'Gericht für den Verkauf']" />
                            </x-fa::field>
                            <x-fa::field label="Rezepttext">
                                <x-fa::textarea wire:model="importText" rows="10"
                                    placeholder="Rezepttext hier einfügen, mit Zutaten und Zubereitung. Abschnitte wie »Für die Sauce: …« werden als Komponenten erkannt." />
                            </x-fa::field>
                            <x-fa::field label="Oder Text-PDF hochladen" error="importPdf">
                                <div class="flex flex-wrap items-center gap-3">
                                    <input type="file" wire:model="importPdf" accept="application/pdf"
                                           class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] file:mr-3 file:h-8 file:px-3 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:font-medium" />
                                    <span wire:loading wire:target="importPdf" class="{{ $hinweisText }}">wird geladen …</span>
                                </div>
                            </x-fa::field>
                            @if($importMeldung)<x-fa::signal tone="crit">{{ $importMeldung }}</x-fa::signal>@endif
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" icon="heroicon-o-document-magnifying-glass" wire:click="importExtrahieren" wire:loading.attr="disabled" wire:target="importExtrahieren,importPdf">
                                    <span wire:loading.remove wire:target="importExtrahieren">Lesen und gliedern</span>
                                    <span wire:loading wire:target="importExtrahieren">Wird gelesen, dauert etwa 15 Sekunden …</span>
                                </x-fa::button>
                            </div>
                        </div>
                    </x-foodalchemist::modal-section>
                @elseif($importStep === 'vorschau')
                    {{-- Prüf-Karte + klebende Anlegen-Leiste: die Zutatenliste wächst mit der Quelle, die Knöpfe
                         standen bisher dahinter und rutschten unter den Fold. Felder, Reihenfolge, Bindings und die
                         data-import-*-Anker bleiben unverändert. --}}
                    <x-foodalchemist::modal-section icon="heroicon-o-eye" title="Vorschau prüfen">
                        <x-slot:actions>
                            <x-fa::badge>Schritt 2 von 3</x-fa::badge>
                            @if(count($importVorschau['zutaten'] ?? []) > 0)
                                <x-fa::badge tone="accent">{{ count($importVorschau['zutaten']) }} Zutaten</x-fa::badge>
                            @endif
                            @if(!empty($importVorschau['komponenten']))
                                <x-fa::badge tone="accent">{{ count($importVorschau['komponenten']) }} Komponenten</x-fa::badge>
                            @endif
                        </x-slot:actions>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <x-fa::field label="Name" class="flex-1 min-w-[16rem]" hint="Pflicht, wenn die Quelle keinen Titel hat.">
                                    <x-fa::input wire:model="importVorschau.name" placeholder="Name des Rezepts" data-import-name />
                                </x-fa::field>
                                <x-fa::field label="Anlegen als" class="w-44">
                                    <x-fa::select wire:model="importTyp" data-import-typ>
                                        <option value="basisrezept">Basisrezept</option>
                                        <option value="gericht">Gericht</option>
                                    </x-fa::select>
                                </x-fa::field>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                                    <span class="w-20 shrink-0">Menge</span><span class="w-24 shrink-0">Einheit</span><span>Zutat</span>
                                </div>
                                @foreach(($importVorschau['zutaten'] ?? []) as $zi => $z)
                                    {{-- #6 (Dominique 2026-08-28): Menge und Einheit fest schmal, Bezeichnung breit. --}}
                                    <div class="flex items-center gap-2" wire:key="izut-{{ $zi }}">
                                        <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.quantity" numeric class="w-20 shrink-0" placeholder="Menge" aria-label="Menge" data-import-zutat-menge />
                                        <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.unit" class="w-24 shrink-0" placeholder="Einheit" aria-label="Einheit" data-import-zutat-einheit />
                                        <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.text" class="flex-1 min-w-0" placeholder="Zutat" aria-label="Zutat" data-import-zutat-text />
                                    </div>
                                @endforeach
                            </div>
                            @if(!empty($importVorschau['komponenten']))
                                <div class="flex flex-col gap-1.5">
                                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Erkannte Komponenten <span class="font-normal text-[var(--fa-ink-3)]">· werden als Unterrezepte angelegt</span></p>
                                    <ul class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                        @foreach($importVorschau['komponenten'] as $ki => $k)
                                            <li class="flex items-center justify-between gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)]" wire:key="ikomp-{{ $ki }}">
                                                <span class="font-medium text-[var(--fa-ink)]">{{ ($k['name'] ?? '') !== '' ? $k['name'] : 'Komponente ohne Namen' }}</span>
                                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ count($k['zutaten'] ?? []) }} Zutaten</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <x-fa::field label="Zubereitung">
                                <x-fa::textarea wire:model="importVorschau.preparation" rows="6" />
                            </x-fa::field>
                        </div>
                    </x-foodalchemist::modal-section>

                    <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 py-3 shadow-lg shadow-black/20">
                        <div class="flex-1 min-w-[16rem] flex flex-col gap-1">
                            <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                                @svg('heroicon-o-check-circle', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Rezept anlegen
                                @if(trim((string) ($importVorschau['name'] ?? '')) !== '')
                                    <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($importVorschau['name']), 32) }}</x-fa::badge>
                                @endif
                            </p>
                            <p class="{{ $hinweisText }}">Die Zutaten werden mit den Grundprodukten verknüpft. Beschreibung und Pairings folgen im Hintergrund.</p>
                            @if($importMeldung)<x-fa::signal tone="crit">{{ $importMeldung }}</x-fa::signal>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::button variant="ghost" wire:click="importReset">Import verwerfen</x-fa::button>
                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="importAnlegen" wire:loading.attr="disabled" wire:target="importAnlegen">
                                <span wire:loading.remove wire:target="importAnlegen">Rezept anlegen</span>
                                <span wire:loading wire:target="importAnlegen">Wird verknüpft …</span>
                            </x-fa::button>
                        </div>
                    </div>
                @elseif($importStep === 'fertig' && $importErgebnis)
                    <x-foodalchemist::modal-section icon="heroicon-o-check-badge" title="Importiert">
                        <x-slot:actions>
                            <x-fa::badge>Schritt 3 von 3</x-fa::badge>
                        </x-slot:actions>
                        <div class="flex flex-col gap-3">
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                „{{ $importErgebnis['name'] }}" ist als Entwurf angelegt
                                @if(!empty($importErgebnis['sub_recipes']))
                                    , dazu {{ count($importErgebnis['sub_recipes']) }} {{ count($importErgebnis['sub_recipes']) === 1 ? 'Unterrezept' : 'Unterrezepte' }}
                                @endif
                            </p>
                            {{-- #6/Import: Verknüpfung mit Grundprodukten + Anreicherung laufen im Hintergrund (nicht mehr synchron per Knopf). --}}
                            <x-fa::signal tone="info">Grundprodukte, Beschreibung und Pairings werden im Hintergrund ergänzt.</x-fa::signal>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::button variant="primary" icon="heroicon-o-queue-list" x-on:click="tab='worker'" data-import-zum-worker>Fortschritt ansehen</x-fa::button>
                                <x-fa::button variant="ghost" icon="heroicon-o-arrow-down-tray" wire:click="importReset">Weiteres Rezept importieren</x-fa::button>
                            </div>
                        </div>
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            {{-- BASISREZEPT: eigener Reiter mit seinen Leitplanken --}}
            <div wire:key="planung-tab-basisrezept" x-show="tab==='basisrezept'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'rezept', 'vk' => false, 'goLabel' => 'Basisrezept', 'goIcon' => 'heroicon-o-beaker'])
            </div>

            {{-- GERICHT: Leitplanken inkl. Verkaufs-Achsen --}}
            <div wire:key="planung-tab-gericht" x-show="tab==='gericht'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'gericht', 'vk' => true, 'goLabel' => 'Gericht', 'goIcon' => 'heroicon-o-cake'])
            </div>

            {{-- CONCEPT (= das „Menü"): Briefing → KI füllt Leitidee und co. → Zusammenstellung (Pakete/Buffet)
                 nach den Leitplanken; braucht Gerichte → kaskadiert nach unten. Läuft NICHT über
                 erstellen-tab.blade.php (eigene Plan-Karte), darum hier dieselbe Bauart von Hand.
                 Genau EINE Hauptaktion: ohne Plan ist „Plan ausarbeiten lassen" primär, mit Plan das Erstellen. --}}
            <div wire:key="planung-tab-concept" x-show="tab==='concept'" class="flex flex-col gap-4 max-w-7xl mx-auto">
                @php $conceptTitel = trim((string) ($eingabe['concept']['titel'] ?? '')); @endphp
                <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" title="Was für ein Concept soll entstehen?">
                    @if($conceptTitel !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($conceptTitel, 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-4">
                        @include('foodalchemist::livewire.planung.partials.schnellstart-chips', ['scope' => 'concept'])
                        <x-fa::field label="Titel" optional>
                            <x-fa::input wire:model="eingabe.concept.titel" placeholder="zum Beispiel CHEFS.CORNER Sommermenü" data-planung-titel />
                        </x-fa::field>
                        <div class="flex flex-col gap-1.5">
                            <x-fa::field label="Briefing" hint="Geht wörtlich in die Erstellung.">
                                <x-fa::textarea wire:model="eingabe.concept.brief" rows="4" placeholder="Anlass, Zielgruppe, Richtung, Pakete oder Buffet, Gänge …" />
                            </x-fa::field>
                            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'eingabe.concept.brief', 'mitLeitplanken' => 'concept', 'mitRecorder' => false])
                        </div>
                        {{-- Agent-am-Brief: Concept nutzt nicht die geteilte erstellen-tab-Partial, darum das
                             Panel-Mount hier direkt. Kurskorrektur (2026-09-19): Recorder hier aus, das Panel ist
                             die einzige Diktierfunktion (siehe Kommentar in erstellen-tab.blade.php). --}}
                        @if($agentPanelSichtbar)
                            @livewire('foodalchemist.voice-modal', [
                                'planungsSessionId' => $sessionId,
                                'planungScope' => 'concept',
                                'formularRegler' => array_intersect_key($regler['concept'] ?? [], array_flip(\Platform\FoodAlchemist\Livewire\Planung\Index::AGENT_SCHREIBBARE_REGLER)),
                                'formularBrief' => (string) ($eingabe['concept']['brief'] ?? ''),
                            ], key('voice-panel-concept-' . ($sessionId ?? 'keine')))
                        @endif
                        <div class="flex flex-col gap-1.5">
                            <x-fa::choice name="eingabe.concept.creative_mode" :options="$modeLabel" label="Kreativ-Modus" id-prefix="planung-concept" />
                            <p class="{{ $hinweisText }} max-w-2xl">{{ $modeHint[$eingabe['concept']['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- KI-Plan (Etappe 2b, geplanter Pfad): arbeitet den Plan vorab aus (Leitidee, Vorteil,
                     Inszenierung, Geschmackswelten + Gänge-Gerüst) und zeigt ihn zur Prüfung, NOCH ohne
                     Gerichte. DF-2 (Spec 41, Entscheid 2026-08-21): der EMPFOHLENE Weg fürs Concepting. --}}
                <x-foodalchemist::modal-section icon="heroicon-o-light-bulb" title="Plan ausarbeiten lassen">
                    <x-slot:actions>
                        <x-fa::badge tone="accent">empfohlen</x-fa::badge>
                    </x-slot:actions>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                        <p class="flex-1 min-w-[16rem] {{ $hinweisText }} max-w-2xl">Die KI arbeitet aus dem Briefing einen vollständigen Entwurf aus: Leitidee, Vorteil, Inszenierung, Geschmackswelten und den Aufbau der Gänge. Du prüfst und korrigierst ihn, <span class="font-semibold text-[var(--fa-ink-2)]">bevor</span> Gerichte entstehen.</p>
                        <x-fa::button :variant="$planConceptId ? 'secondary' : 'primary'" icon="heroicon-o-sparkles" wire:click="kiKopf" :disabled="$laeuft"
                            wire:loading.attr="disabled" wire:target="kiKopf" data-planung-kikopf>
                            <span wire:loading.remove wire:target="kiKopf">{{ $planConceptId ? 'Plan neu ausarbeiten' : 'Plan ausarbeiten lassen' }}</span>
                            <span wire:loading wire:target="kiKopf">Plan wird ausgearbeitet …</span>
                        </x-fa::button>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- A0/A1: der ausgearbeitete Plan bleibt SICHTBAR + bearbeitbar (Semantik + Menü-Aufbau), kein
                     Wegsprung in den Conceptor. Steht im normalen Fluss vor der Erstell-Leiste (vorher IN der
                     klebenden Leiste und überdeckte so auf dem Laptop fast den ganzen Bildschirm). --}}
                @if($planConceptId)
                    @include('foodalchemist::livewire.planung.partials.concept-plan')
                @endif

                @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => 'concept'])

                @include('foodalchemist::livewire.planung.partials.schnellstart-speichern', ['scope' => 'concept'])

                {{-- Erstell-Leiste: klebt unten (Befund „Knopf unter dem Fold"), als EINE niedrige Zeile. --}}
                <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 py-3 shadow-lg shadow-black/20" data-planung-erstellen-leiste>
                    <div class="flex-1 min-w-[16rem] flex flex-col gap-1">
                        <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                            @svg('heroicon-o-squares-2x2', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Concept erstellen
                            @if($conceptTitel !== '')<x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($conceptTitel, 32) }}</x-fa::badge>@endif
                        </p>
                        @if($planConceptId)
                            {{-- Geplanter Pfad (Etappe 2b): der Plan ist vorbereitet, das Erstellen verwendet ihn statt neu
                                 zu erzeugen. „Plan verwerfen" wechselt zurück auf den direkten Weg. --}}
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1" data-planung-plan-bereit>
                                <x-fa::signal tone="ok" icon="heroicon-m-check-badge">Geprüfter Plan liegt vor, die Erstellung verwendet ihn.</x-fa::signal>
                                <button type="button" wire:click="planVerwerfen" @disabled($laeuft)
                                        class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] underline underline-offset-2 hover:text-[var(--fa-ink)] disabled:opacity-50">Plan verwerfen</button>
                            </div>
                        @else
                            {{-- DF-2: direkter Weg (sekundär), ohne vorab ausgearbeiteten Plan. --}}
                            <p class="{{ $hinweisText }}">Direkter Weg ohne Plan: die KI stellt Pakete oder Buffet sofort nach den Leitplanken zusammen, die Gerichte folgen nach der Freigabe. Den Stand siehst du im Reiter „Fortschritt".</p>
                        @endif
                        @include('foodalchemist::livewire.planung.partials.worker-praesenz')
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if($planConceptId)
                            <x-foodalchemist::ki-action action="goKaskade('concept')" variant="primary" icon="heroicon-o-squares-2x2"
                                label="Concept aus Plan erstellen" busy="Wird gestartet …" flash="Gestartet"
                                :disabled="$laeuft" before="tab='worker'" />
                        @else
                            <x-foodalchemist::ki-action action="goKaskade('concept')" variant="ghost" icon="heroicon-o-squares-2x2"
                                label="Ohne Plan direkt erstellen" busy="Wird gestartet …" flash="Gestartet"
                                :disabled="$laeuft" before="tab='worker'" />
                        @endif
                    </div>
                </div>
            </div>

            {{-- FORTSCHRITT (Alpine-Tab `worker`): alle Läufe zusammen, Status + Baum + Freigabe.
                 Anzeige, kein Erstell-Knopf, darum keine klebende Leiste. --}}
            <div wire:key="planung-tab-worker" x-show="tab==='worker'" class="flex flex-col gap-4 max-w-7xl mx-auto">
                {{-- Spec 53 / Paket C: $pollAktiv wird JEDES Render frisch aus DB-Wahrheit abgeleitet
                     (Lauf-Status + Step-Phasen), kein gespeichertes Flag. --}}
                @if($pollAktiv)
                    <div wire:poll.1500ms="pruefeLauf" class="flex items-center gap-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-info-soft)] px-3.5 py-2.5 text-[length:var(--fa-text-md)] text-[var(--fa-info)]">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 shrink-0 animate-spin')
                        <span>
                            @if($laeuft)
                                Läuft. Die Hintergrund-Erstellung arbeitet die Schritte ab …
                            @elseif($anreicherungLaeuft)
                                Freigegeben. Beschreibung, Kalkulation und Allergene werden ergänzt …
                            @else
                                Ein Schritt im Hintergrund läuft noch …
                            @endif
                        </span>
                    </div>
                    @if($hinweis !== null)
                        <x-fa::notice tone="warn" data-planung-watchdog>
                            {{ $hinweis }}
                            <x-slot:actions>
                                {{-- Wiederaufnahme: verwaiste Schritte freiräumen → Lauf wieder handlungsfähig --}}
                                <x-fa::button size="sm" icon="heroicon-o-arrow-path" wire:click="laufFortsetzen" wire:loading.attr="disabled" data-planung-fortsetzen>Abgebrochene Schritte freiräumen</x-fa::button>
                            </x-slot:actions>
                        </x-fa::notice>
                    @endif
                @endif

                {{-- Ergebnis: Status + Baum + Freigabe (Gate 2) --}}
                @if($lauf)
                    @include('foodalchemist::livewire.planung.partials.ergebnis')
                @else
                    <x-foodalchemist::modal-section icon="heroicon-o-queue-list" title="Fortschritt">
                        <x-fa::empty icon="heroicon-o-queue-list" title="Noch nichts gestartet">Starte in „Basisrezept", „Gericht" oder „Concept" eine Erstellung. Der Fortschritt erscheint dann hier.</x-fa::empty>
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            {{-- COMPOSER: Foodpairing-Fläche. Zutaten zusammenstellen, das Netz zeigt live, was passt.
                 Gezielte Kreation: aus den gewählten Zutaten ein Basisrezept oder Gericht vorbereiten
                 (Zutaten = verbindliche Leit-Aromen). Klick auf einen Kandidaten nimmt ihn auf.
                 Spaltenaufteilung bleibt 5fr/7fr (Auswahl links, Netz rechts). fa-pass: nur die linke Spalte
                 auf Tokens/Bausteine umgestellt; das Netz samt Legende rechts ist unverändert (eigene Welle). --}}
            <div wire:key="planung-tab-composer" x-show="tab==='composer'" class="max-w-7xl mx-auto">
                <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] gap-4 items-start">
                {{-- LINKE SPALTE: Auswahl + Zusammenhalt --}}
                <div class="space-y-4 min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-squares-plus" title="Zutaten zusammenstellen">
                    @if(!empty($composerAnker))
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ count($composerAnker) }} gewählt</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }} max-w-2xl">Zutaten wählen, das Netz rechts zeigt sofort, was harmoniert (★★★), was einen offenen Bedarf als Kontrast deckt und was sich stört. Unten filtern und suchen oder einen Kandidaten im Netz anklicken.</p>

                        <div class="flex flex-wrap gap-2">
                            <x-fa::select wire:model.live="composerCategory" aria-label="Kategorie" class="sm:w-56">
                                <option value="">Alle Kategorien</option>
                                @foreach($composerBrowse['kategorien'] as $kat)
                                    <option value="{{ $kat }}">{{ $kat }}</option>
                                @endforeach
                            </x-fa::select>
                            <x-fa::input type="search" wire:model.live.debounce.300ms="composerTerm"
                                placeholder="Zutat suchen" aria-label="Zutat suchen" class="flex-1 min-w-[12rem]" />
                        </div>

                        @if(!empty($composerAnker))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($composerAnker as $a)
                                    <span wire:key="canker-{{ $a['id'] }}"
                                          class="inline-flex items-center gap-1 h-7 pl-2.5 pr-1 rounded-full border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]">
                                        {{ $a['label'] }}
                                        <button type="button" wire:click="composerRemove({{ $a['id'] }})" aria-label="{{ $a['label'] }} entfernen" title="Entfernen"
                                                class="inline-flex items-center justify-center w-5 h-5 rounded-full hover:bg-[var(--fa-accent-soft-hover)]">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-1.5">
                            <p class="{{ $hinweisText }}">
                                @if($composerFocus !== null && $composerFokusLabel)
                                    Punkt zeigt, was zu <span class="font-medium text-[var(--fa-accent)]">{{ $composerFokusLabel }}</span> passt. Klick nimmt die Zutat auf.
                                @else
                                    {{ number_format($composerBrowse['total'], 0, ',', '.') }} Zutaten. Punkt zeigt, wie gut sie zur Auswahl passt. Klick nimmt die Zutat auf.
                                @endif
                            </p>
                            <div class="max-h-64 overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-y divide-[var(--fa-line)]">
                                @forelse($composerBrowse['items'] as $it)
                                    <button type="button" wire:key="cbrowse-{{ $it['id'] }}" wire:click="composerAdd({{ $it['id'] }})"
                                            class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                        @php
                                            // Spec 60: ★★★ harmoniert · Kontrast deckt einen offenen Bedarf · Konflikt stört sich
                                            $punkt = ['stern3' => ['#fcd34d', '★★★ harmoniert'], 'kontrast' => ['#22d3ee', 'Kontrast: deckt einen offenen Bedarf'],
                                                'konflikt' => ['#f43f5e', 'Konflikt: stört sich mit der Auswahl']][$it['typ'] ?? ''] ?? null;
                                        @endphp
                                        <span class="w-2 h-2 rounded-full shrink-0" data-picker-typ="{{ $it['typ'] ?? '' }}"
                                              style="background: {{ $punkt[0] ?? 'transparent' }}; {{ $punkt ? '' : 'border:1px solid rgba(148,163,184,.35);' }}"
                                              title="{{ $punkt[1] ?? 'kein Bezug zur Auswahl' }}"></span>
                                        <span class="flex-1 truncate">{{ $it['label'] }}</span>
                                        @if($it['category'])
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] shrink-0">{{ $it['category'] }}</span>
                                        @endif
                                        @svg('heroicon-m-plus', 'w-4 h-4 shrink-0 text-[var(--fa-accent)]')
                                    </button>
                                @empty
                                    <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Zutat gefunden">Filter oder Suche anpassen.</x-fa::empty>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- Aus den gewählten Zutaten in einen Erstell-Reiter springen. Der Brief wird vorbefüllt, die
                     Zutaten reisen als verbindliche Leit-Aromen (seed_anker) mit; dort die Leitplanken setzen
                     und starten. --}}
                <x-foodalchemist::modal-section icon="heroicon-o-arrow-right-circle" title="Daraus weiterbauen">
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Übernimmt die Zutaten als <span class="font-semibold text-[var(--fa-ink-2)]">verbindliche Leit-Aromen</span> und springt in den Erstell-Reiter. Der Brief ist vorbefüllt, dort die Leitplanken setzen und erstellen.</p>
                        <div class="flex flex-wrap gap-2">
                            <x-fa::button variant="primary" icon-right="heroicon-m-arrow-right" wire:click="composerUebernehmen('rezept')" x-on:click="tab='basisrezept'"
                                :disabled="empty($composerAnker)" data-composer-go-rezept>Als Basisrezept vorbereiten</x-fa::button>
                            <x-fa::button icon-right="heroicon-m-arrow-right" wire:click="composerUebernehmen('gericht')" x-on:click="tab='gericht'"
                                :disabled="empty($composerAnker)" data-composer-go-gericht>Als Gericht vorbereiten</x-fa::button>
                        </div>
                        @if(empty($composerAnker))
                            <p class="{{ $hinweisText }}">Erst mindestens eine Zutat wählen.</p>
                        @endif
                    </div>
                </x-foodalchemist::modal-section>

                {{-- „Passt das zusammen?" — Spec 60: dieselbe Kombinationslogik wie im Gericht-Panel
                     (Harmonie nur ★★★, Spannung, offener Bedarf, Konflikt, Klassiker — je mit Grundlage). --}}
                @if($composerKombination !== null)
                    <x-foodalchemist::modal-section icon="heroicon-o-link" title="Passt das zusammen?">
                        <x-foodalchemist::kombination :daten="$composerKombination" />
                    </x-foodalchemist::modal-section>
                @endif
                </div>{{-- /linke Spalte --}}

                {{-- RECHTE SPALTE: Netz --}}
                <div class="min-w-0">
                {{-- Netz + Filter-Chips in EINER Alpine-Instanz (wie im Detail-Modal) --}}
                <x-foodalchemist::modal-section class="!mt-0" icon="heroicon-o-share" title="Netz">
                    @if($composerFocus !== null && $composerFokusLabel)
                        <x-slot:actions>
                            <span class="{{ $pill }} {{ $variantPill['primary'] }}">Fokus: {{ $composerFokusLabel }}</span>
                        </x-slot:actions>
                    @endif
                    @if(empty($composerAnker))
                        <p class="text-[13px] text-gray-500 max-w-2xl">
                            Noch keine Zutat gewählt — oben eine hinzufügen. Dann zeigt das Netz die ★★★-Partner,
                            welche Zutat einen offenen Bedarf als Kontrast deckt und was sich stört.
                        </p>
                    @else
                        @if($composerFocus !== null && $composerFokusLabel)
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-[11px]">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full {{ $variantPill['primary'] }} border border-violet-500/30">
                                    Fokus: {{ $composerFokusLabel }}
                                    <button type="button" wire:click="composerFocus({{ $composerFocus }})" class="text-violet-500 hover:text-violet-700 leading-none" title="Fokus aufheben">&times;</button>
                                </span>
                                <span class="text-gray-500">nur seine Verbindungen · Klick aufs Zentrum oder × hebt auf</span>
                            </div>
                        @else
                            <p class="mb-2 text-[10px] text-gray-500">Tipp: Klick auf einen Anker fokussiert ihn — nur seine Verbindungen + Stärke bleiben sichtbar.</p>
                        @endif
                        <div wire:ignore
                             wire:key="composer-netz-{{ $composerNetz['meta']['sig'] ?? '0' }}-f{{ $composerFocus ?? 0 }}"
                             x-data="pairingNetzGraph({
                                 nodes: @js($composerNetz['nodes']),
                                 edges: @js($composerNetz['edges']),
                                 mode: 'modal',
                                 canvasW: {{ (float) ($composerNetz['meta']['canvas_w'] ?? 1000) }},
                                 canvasH: {{ (float) ($composerNetz['meta']['canvas_h'] ?? 760) }},
                                 typDefault: @js($composerNetz['meta']['typ_default'] ?? ['stern3' => true, 'kontrast' => true]),
                                 focusId: {{ $composerFocus ?? 'null' }},
                                 onKandidatClick: (id) => $wire.composerAdd(id),
                                 onAnkerClick: (id) => $wire.composerFocus(id),
                             })">
                            {{-- Filter-Chips und Legende liegen AUSSERHALB des SVG, also auf der weissen Karte:
                                 Schrift auf Grau-Tokens, Ring-Offset auf Weiss (vorher slate-900 → dunkler
                                 Spalt um den aktiven Chip). Die Stern-Hex bleiben, sie spiegeln die Punktfarben
                                 im Netz. --}}
                            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]">
                                <span class="text-gray-500 mr-1">Zeigen:</span>
                                <button type="button" @click="toggleTyp('stern3')"
                                        :class="typAktiv['stern3'] ? 'ring-2 ring-offset-1 ring-offset-white' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-gray-700"
                                        style="border-color:#fcd34d; --tw-ring-color:#fcd34d;">
                                    <span class="w-2 h-2 rounded-full" style="background:#fcd34d"></span> ★★★ harmoniert
                                </button>
                                <button type="button" @click="toggleTyp('kontrast')"
                                        :class="typAktiv['kontrast'] ? 'ring-2 ring-offset-1 ring-offset-white' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-gray-700"
                                        style="border-color:#22d3ee; --tw-ring-color:#22d3ee;">
                                    <span class="w-2 h-2 rounded-full" style="background:#22d3ee"></span> Kontrast
                                </button>
                            </div>
                            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet"
                                 class="w-full h-[calc(100dvh-20rem)] min-h-[320px] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]" data-fa-netz-mount></svg>
                            {{-- Legende (Spec 60): Linien zwischen den gewählten Ankern --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-gray-700">
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:3px solid #fcd34d"></span> harmoniert (★★★, gemessen)</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:2px dashed #f43f5e"></span> Konflikt (Anker-Wissen)</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:2px dotted #22d3ee"></span> Kontrast: deckt einen offenen Bedarf</span>
                                <span class="text-[10px] text-gray-500">· Hover zeigt, welchen Bedarf ein Kontrast deckt</span>
                            </div>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
                </div>{{-- /rechte Spalte --}}
                </div>{{-- /grid --}}
            </div>
        @endif
    </x-foodalchemist::modal>
    {{-- Leitstelle-In-Context: die erzeugten Entwürfe (Basisrezept/Gericht) im Cockpit ansehen,
         statt auf die Listen-Seite zu springen. DOM NACH dem Editor-Modal → z-Stacking (öffnet darüber). --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.recipes.pairing-netz-modal />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    {{-- Vollen Conceptor-Editor inline: ein erzeugtes Concept öffnet mit allen Tabs/KPIs/Score/Kalkulation/
         Geschirr direkt hier (öffnet via concepter-editor.oeffnen aus der step-zeile). Gleiches Muster wie Angebote. --}}
    <livewire:foodalchemist.concepter.editor />
    {{-- Agent-am-Brief (Nachtrag, Dominique-Abnahme): das Panel lebt NICHT mehr hier auf der
         Board-Ebene — es sass falsch (oben rechts neben den Kanban-Spalten, nicht am Brief/
         den Leitplanken). Je EIN Panel pro Scope-Tab, direkt im Erstellen-Bereich
         (`erstellen-tab.blade.php` für rezept/gericht, oben im Concept-Block für concept). --}}
</x-ui-page>
