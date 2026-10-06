{{-- Angebote-Editor (Vollbild, pro Angebot) — 1:1-Fork des Foodbook-Editors (Doc 15 §9.3 /
     resources/views/livewire/foodbooks/index.blade.php) auf das ANGEBOT. Methoden/Properties bleiben
     wo möglich IDENTISCH zum Foodbook (B1 spiegelt die Namen); Models/Service sind ersetzt
     (FoodAlchemistAngebot + OfferCompositionService/AngebotService), plus Angebot-Spezifika:
     Anfrage-Kopf, Zuschlagskalkulation (B3-Partial), Status-Workflow, → Produktion.
     Der Rahmen ist das bestehende Angebot-Modal (name="angebot-editor", fullscreen, dark-canvas).

     fa-pass (2026-10-05): Werkbank-Modus — nur --fa-*-Tokens und x-fa-Bausteine, damit hell UND
     dunkel stimmen. Anatomie: Kopf = Titel + Name · Status ändern (Menü) · In der Leitstelle planen
     (die eine KI-Aktion im Kopf) · Weitere Aktionen (Drucken, Dokument, Präsentation, Produktion,
     Löschen ganz unten) · Speichern (die eine Hauptaktion). Hauptzahl = Angebotssumme.
     Häufigste Arbeit = Kapitel füllen und Preis prüfen → Reiter Übersicht · Aufbau · Kalkulation
     zuerst; ein Angebot ohne Kapitel öffnet auf «Anfrage». --}}
@php
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $mini = 'inline-flex items-center justify-center w-6 h-6 shrink-0 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] transition-colors duration-150';
    $miniKrit = 'inline-flex items-center justify-center w-6 h-6 shrink-0 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)] transition-colors duration-150';
    $navAn = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    $navAus = 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $dateiFeld = 'block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border-0 file:bg-[var(--fa-accent-soft)] file:text-[var(--fa-accent)] file:font-medium';
    $knopfLeise = 'inline-flex items-center gap-1.5 h-7 px-2.5 whitespace-nowrap rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)] transition-colors duration-150';
    $haken = 'rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] accent-[var(--fa-accent)] focus:ring-[var(--fa-accent)]';
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
    $prozent = fn ($wert) => number_format((float) $wert, 1, ',', '.') . ' %';
    // Rohwerte lesbar machen (Status-Badge-Variante, Fortschritt, Preis-Modus, Wareneinsatz-Ampel).
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'warning' => 'warn', 'primary' => 'accent', 'success' => 'ok', 'danger' => 'crit'];
    $fortTon = ['offen' => 'neutral', 'in_arbeit' => 'warn', 'fertig' => 'ok'];
    $fortLabel = ['offen' => 'Offen', 'in_arbeit' => 'In Arbeit', 'fertig' => 'Fertig'];
    $weTon = ['gruen' => 'ok', 'gelb' => 'warn', 'rot' => 'crit'];
    $befundTon = ['erfuellt' => 'ok', 'teilerfuellt' => 'warn', 'verletzt' => 'crit', 'info' => 'info'];
    $preisModusText = fn (?string $m) => ['auto' => 'Preis aus dem Inhalt', 'manuell' => 'Preis von Hand', 'alternativen' => 'Preis je Auswahl', 'fixed' => 'Festpreis'][$m ?? ''] ?? ucfirst((string) $m);
    $hatKapitel = count($kapitelTree ?? []) > 0;
@endphp

<x-foodalchemist::modal name="angebot-editor" fullscreen dark-canvas title="Angebot"
    :title-name="$angebot->name ?? null">
    <x-slot:actions>
        @if($angebot)
            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- Workflow-Übergänge (Status-Maschine) gebündelt in einem Menü --}}
                @if(count($angebot->status->uebergaenge()) > 0)
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="ang-status-{{ $angebot->id }}-{{ $angebot->status->value }}">
                        <x-fa::button icon="heroicon-m-arrow-path-rounded-square" icon-right="heroicon-m-chevron-down"
                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Status ändern</x-fa::button>
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                            <p class="px-3 pt-1 pb-1.5 {{ $hinweis }}">Jetzt: {{ $angebot->status->label() }}</p>
                            @foreach($angebot->status->uebergaenge() as $next)
                                <button type="button" role="menuitem" wire:click="statusSetzen('{{ $next->value }}')" x-on:click="offen = false"
                                        class="{{ $menuePunkt }}" data-angebot-status="{{ $next->value }}">
                                    @svg('heroicon-m-arrow-right', 'w-4 h-4 text-[var(--fa-ink-3)]') Auf {{ $next->label() }} setzen
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Einstieg in die Leitstelle-Planung (spiegelt Foodbook »In der Leitstelle planen«) — die eine KI-Aktion im Kopf. --}}
                <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="vollKaskadeStarten"
                    title="Die KI plant alle Kapitel in der Leitstelle und legt je Kapitel ein Konzept an" data-angebot-in-leitstelle>In der Leitstelle planen</x-fa::button>

                {{-- Weitere Aktionen: Drucken · Dokument · Präsentation · Produktion · Löschen (ganz unten, rot) --}}
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                        <a href="{{ route('foodalchemist.angebote.karte', $angebot->id) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                           class="{{ $menuePunkt }}" title="Gestaltete Angebotskarte für den Kunden, zum Drucken oder als PDF">
                            @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Angebotskarte drucken
                        </a>
                        <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                           class="{{ $menuePunkt }}" title="Schlichtes Angebotsdokument, zum Drucken oder als PDF">
                            @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--fa-ink-3)]') Angebotsdokument öffnen
                        </a>
                        <a href="{{ route('foodalchemist.angebote.praesentation', $angebot->id) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                           class="{{ $menuePunkt }}" title="Kundenpräsentation als Webseite, ohne interne Angaben">
                            @svg('heroicon-o-presentation-chart-bar', 'w-4 h-4 text-[var(--fa-ink-3)]') Kundenpräsentation ansehen
                        </a>
                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                        {{-- Stufe 3 — Angebot → Produktion (concept × Pax → Produktionsauftrag am Event-Tag). --}}
                        <button type="button" role="menuitem" wire:click="anProduktion" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                title="Angebot in die Produktion übergeben, danach im Tagesplan planbar" data-angebot-produktion>
                            @svg('heroicon-o-truck', 'w-4 h-4 text-[var(--fa-ink-3)]') An die Produktion übergeben
                        </button>
                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                        <button type="button" role="menuitem" wire:click="loeschen" wire:confirm="Angebot löschen?" x-on:click="offen = false"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" data-angebot-loeschen>
                            @svg('heroicon-o-trash', 'w-4 h-4') Angebot löschen
                        </button>
                    </div>
                </div>

                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-angebot-speichern>Speichern</x-fa::button>
            </div>
        @endif
    </x-slot:actions>

    @if($angebot)
        {{-- Kennzahlen: Angebotssumme als die eine Hauptzahl, dann Preis je Gast, Gäste, Wareneinsatz,
             Kapitel, Speisen, fertige Kapitel, Status (data-kpi-Marker unverändert). Alle aus render()-Daten, keine neuen Service-Calls. --}}
        <x-slot:kpiHeader>
            @php
                $k = $kalkulation;
                $voll = $k && ! ($k['leer'] ?? true);
                $angBoard = collect($kapitelBoard ?? []);
                $angKapitelN = count($kapitelTree ?? []);
                $angSpeisenN = (int) $angBoard->sum('positionen_count');
                $angFertig = $angBoard->where('fortschritt', 'fertig')->count();
                $kpiWeTon = match ($wareneinsatzAmpel ?? 'unbekannt') { 'gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', default => null };
            @endphp
            <x-foodalchemist::kpi-tiles marker="angebot-kpis" :tiles="[
                ['kpi' => 'gesamt', 'label' => 'Angebotssumme', 'tone' => 'accent',
                 'value' => $voll ? $euro($k['gesamt_vk']) : 'Noch kein Preis'],
                ['kpi' => 'vkpp', 'label' => 'Preis je Gast',
                 'value' => $voll ? $euro($k['vk_pro_person']) : '–'],
                ['kpi' => 'pax', 'label' => 'Gäste', 'value' => (string) ($k['pax'] ?? ($angebot->personen ?: '–'))],
                ['kpi' => 'we', 'label' => 'Wareneinsatz', 'tone' => $kpiWeTon,
                 'title' => ($voll && $k['wareneinsatz_pct'] !== null) ? 'Ziel des Teams: ' . $prozent($zielWareneinsatzPct) : null,
                 'value' => ($voll && $k['wareneinsatz_pct'] !== null) ? $prozent($k['wareneinsatz_pct']) : '–'],
                ['kpi' => 'kapitel', 'label' => 'Kapitel', 'value' => (string) $angKapitelN],
                ['kpi' => 'speisen', 'label' => 'Speisen', 'value' => (string) $angSpeisenN],
                ['kpi' => 'fertig', 'label' => 'Fertig',
                 'tone' => ($angKapitelN > 0 && $angFertig >= $angKapitelN) ? 'good' : 'neutral',
                 'value' => $angFertig . ' von ' . $angKapitelN],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $angebot->status->label()],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($angebot === null)
        <x-fa::empty icon="heroicon-o-document-text" title="Kein Angebot geladen">In der Übersicht ein Angebot wählen.</x-fa::empty>
    @else
    @if(session('angebot_produktion'))
        <x-fa::notice tone="info" data-angebot-produktion-hinweis>{{ session('angebot_produktion') }}</x-fa::notice>
    @endif
    {{-- ═══ 2-Spalten-Cockpit IM Modal — links Navigation (Anfrage + Kapitelbaum), Mitte Editor-Reiter.
         `-mx-6` hebt das px-6 des Modal-Bodys auf (Spalten randbündig); die Mitte bekommt px-6 zurück. --}}
    <div class="flex gap-4 -mx-6 items-start">
        {{-- LINKS: Navigation (Anfrage + Kapitelbaum). x-data hält den Kapitel-Drag-Zustand. --}}
        <nav class="w-64 shrink-0 pl-6 flex flex-col gap-1" aria-label="Kapitel des Angebots" data-angebot-nav x-data="{ dragKapId: null }">
            <button type="button" wire:click="kopfAnzeigen" @click="$dispatch('angebot-goto', { tab: 'anfrage' })"
                    class="w-full flex items-center gap-2 h-8 px-2.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] {{ $selectedKapitelId === null ? $navAn : $navAus }}"
                    data-angebot-kopf>@svg('heroicon-o-clipboard-document-list', 'w-4 h-4 shrink-0') Anfrage und Eckdaten</button>

            <p class="mt-3 mb-0.5 px-2.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Kapitel <span class="tabular-nums">{{ count($kapitelTree ?? []) }}</span></p>

            @forelse($kapitelTree ?? [] as $kt)
                <div wire:key="ktm-{{ $kt['id'] }}"
                     @dragover.prevent @drop.prevent="if (dragKapId && dragKapId !== {{ $kt['id'] }}) { $wire.kapitelVerschiebenAuf(dragKapId, {{ $kt['id'] }}); } dragKapId = null"
                     :class="dragKapId === {{ $kt['id'] }} ? 'opacity-40' : (dragKapId ? 'ring-1 ring-[var(--fa-accent-line)]' : '')"
                     class="group flex items-center gap-0.5 rounded-[var(--fa-radius-control)]" style="padding-left: {{ $kt['depth'] * 12 }}px">
                    <span class="inline-flex items-center justify-center w-4 shrink-0 cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] select-none opacity-0 group-hover:opacity-100" draggable="true"
                          @dragstart="dragKapId = {{ $kt['id'] }}; $event.dataTransfer.setData('text/plain', String({{ $kt['id'] }})); $event.dataTransfer.effectAllowed = 'move'"
                          @dragend="dragKapId = null" title="Ziehen zum Sortieren" data-kapitel-drag>@svg('heroicon-m-bars-2', 'w-3.5 h-3.5')</span>
                    <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})" @click="$dispatch('angebot-goto', { tab: 'aufbau' })"
                            class="flex-1 min-w-0 text-left break-words leading-snug text-[length:var(--fa-text-md)] px-2 py-1 rounded-[var(--fa-radius-control)] {{ $selectedKapitelId === $kt['id'] ? $navAn : $navAus }}">{{ $kt['title'] }}</button>
                    <span class="flex items-center opacity-0 group-hover:opacity-100 group-focus-within:opacity-100">
                        <button type="button" wire:click="kapitelHoch({{ $kt['id'] }})" class="{{ $mini }}" title="Nach oben" aria-label="Kapitel nach oben">@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
                        <button type="button" wire:click="kapitelRunter({{ $kt['id'] }})" class="{{ $mini }}" title="Nach unten" aria-label="Kapitel nach unten">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
                        <button type="button" wire:click="kapitelNeu({{ $kt['id'] }})" class="{{ $mini }}" title="Unterkapitel anlegen" aria-label="Unterkapitel anlegen">@svg('heroicon-m-plus', 'w-3.5 h-3.5')</button>
                        <button type="button" wire:click="kapitelLoeschen({{ $kt['id'] }})" wire:confirm="Kapitel löschen?" class="{{ $miniKrit }}" title="Kapitel löschen" aria-label="Kapitel löschen">@svg('heroicon-m-trash', 'w-3.5 h-3.5')</button>
                    </span>
                </div>
            @empty
                <p class="px-2.5 {{ $hinweis }}">Noch keine Kapitel.</p>
            @endforelse

            <div class="flex items-center gap-1 pt-2 mt-1 border-t border-[var(--fa-line)]">
                <label for="ang-kapitel-neu" class="sr-only">Titel des neuen Kapitels</label>
                <x-fa::input id="ang-kapitel-neu" size="sm" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu"
                    x-on:keydown.enter="$dispatch('angebot-goto', { tab: 'aufbau' })" placeholder="Neues Kapitel" />
                <x-fa::icon-button icon="heroicon-m-plus" label="Kapitel anlegen" size="sm" wire:click="kapitelNeu" x-on:click="$dispatch('angebot-goto', { tab: 'aufbau' })" />
            </div>
        </nav>

        {{-- MITTE: Editor-Reiter --}}
        <div class="flex-1 min-w-0 px-6">
            <div wire:key="angcockpit-{{ $angebot->id }}" class="space-y-4">
                <x-foodalchemist::editor-tabs marker="angebot" wire-key="angebot-tabs-{{ $angebot->id }}" :init="$hatKapitel ? 'board' : 'anfrage'"
                    :tabs="[
                        'board' => 'Übersicht',
                        'aufbau' => 'Aufbau',
                        'kalkulation' => 'Kalkulation',
                        'anfrage' => 'Anfrage',
                        'kunde' => 'Kunde und Business-Case',
                        'branding' => 'Branding und Präsentation',
                    ]">

                {{-- headless: Sprung-Bus im editor-tabs-Scope (kein sichtbares Element). $root = editor-tabs-Wurzel
                     (trägt die data-angebot-tab-Buttons + data-angebot-anker-Panels). --}}
                <div @angebot-goto.window="let d=$event.detail; if(d.tab && $root.querySelector(`[data-angebot-tab='${d.tab}']`)) tab=d.tab; $nextTick(()=>{ if(d.anker){ let el=$root.querySelector(`[data-angebot-anker='${d.anker}']`); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); } });"></div>

                {{-- ═══ Reiter: ANFRAGE (Angebot-Kopf-Felder) ═══ --}}
                <div x-show="tab === 'anfrage'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="anfrage">
                    <x-fa::section title="Anfrage und Eckdaten" icon="heroicon-o-clipboard-document-list">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <x-fa::field label="Name des Angebots" for="ang-name" class="col-span-2">
                                <x-fa::input id="ang-name" wire:model="form.name" />
                            </x-fa::field>
                            <x-fa::field label="Gäste" for="ang-pax" hint="Bestimmt die Angebotssumme">
                                <x-fa::input id="ang-pax" type="number" min="0" numeric wire:model="form.personen" wire:change="speichern" />
                            </x-fa::field>
                            <x-fa::field label="Veranstaltungsdatum" for="ang-datum">
                                <x-fa::input id="ang-datum" type="date" wire:model="form.event_date" />
                            </x-fa::field>
                            <x-fa::field label="Anlass" for="ang-anlass" class="col-span-2">
                                <x-fa::input id="ang-anlass" wire:model="form.occasion" placeholder="Hochzeit, Firmenfeier …" />
                            </x-fa::field>
                            <x-fa::field label="Budget in €" for="ang-budget">
                                <x-fa::input id="ang-budget" type="number" step="0.01" numeric wire:model="form.budget" />
                            </x-fa::field>
                            <x-fa::field label="Angebot gültig bis" for="ang-gueltig">
                                <x-fa::input id="ang-gueltig" type="date" wire:model="form.valid_until" />
                            </x-fa::field>
                            <x-fa::field label="Ort" for="ang-ort" class="col-span-2">
                                <x-fa::input id="ang-ort" wire:model="form.location" />
                            </x-fa::field>
                            <x-fa::field label="Ernährung und Allergien" for="ang-diaet" class="col-span-2">
                                <x-fa::input id="ang-diaet" wire:model="form.diet_requirement" placeholder="vegetarisch, ohne Nüsse …" />
                            </x-fa::field>
                        </div>
                        <x-fa::field label="Briefing" for="ang-briefing">
                            <x-fa::textarea id="ang-briefing" rows="4" wire:model="form.brief" />
                        </x-fa::field>
                    </x-fa::section>
                </div>

                {{-- ═══ Reiter: ÜBERSICHT — Kapitel-Baum (Fortschritt + Inhalt + Preis je Kapitel), aufklappbar zu Positionen ═══ --}}
                <div x-show="tab === 'board'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="board" data-angebot-anker="board">
                    @php
                        $board = $kapitelBoard ?? [];
                        $byId = collect($board)->keyBy('kapitel_id');
                        $ahnen = function ($kid) use ($byId) { $ids = []; $cur = data_get($byId->get($kid), 'parent_id'); $g = 0; while ($cur !== null && $g++ < 20) { $ids[] = (int) $cur; $cur = data_get($byId->get($cur), 'parent_id'); } return $ids; };
                        $alleIds = collect($board)->pluck('kapitel_id')->map(fn ($i) => (int) $i)->all();
                    @endphp
                    <div x-data="{ auf: {} }">
                        <x-fa::section title="Kapitel im Überblick" icon="heroicon-o-queue-list" :meta="count($board) > 0 ? (string) count($board) : null"
                            description="Fortschritt, Inhalt und Preis je Kapitel. Klick auf eine Zeile zeigt die Positionen.">
                            @if(count($board) > 0)
                                <x-slot:actions>
                                    <button type="button" @click="auf = Object.fromEntries(@js($alleIds).map(i => [i, true]))" class="{{ $knopfLeise }}" title="Alle Äste aufklappen">@svg('heroicon-m-chevron-double-down', 'w-3.5 h-3.5') Alle aufklappen</button>
                                    <button type="button" @click="auf = {}" class="{{ $knopfLeise }}" title="Auf die Oberkapitel zuklappen">@svg('heroicon-m-chevron-double-up', 'w-3.5 h-3.5') Alle zuklappen</button>
                                </x-slot:actions>
                            @endif
                            <div class="-mx-4 -mb-4 border-t border-[var(--fa-line)]">
                                @forelse($board as $kap)
                                    @php
                                        $we = $kap['wareneinsatz'];
                                        $agg = $kap['aggregat'];
                                        $ahnenIds = $ahnen($kap['kapitel_id']);
                                        $istRollup = count($kap['positionen']) === 0 && (($agg['vk_pro_person'] ?? 0) > 0 || ($agg['pauschal'] ?? 0) > 0);
                                    @endphp
                                    <div wire:key="board-{{ $kap['kapitel_id'] }}" x-show="{{ empty($ahnenIds) ? 'true' : '[' . implode(',', $ahnenIds) . '].filter(a => auf[a]).length === ' . count($ahnenIds) }}" x-cloak
                                         class="border-b border-[var(--fa-line)] last:border-b-0">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 py-2 pr-4 cursor-pointer hover:bg-[var(--fa-hover)]" @click="auf = {...auf, {{ $kap['kapitel_id'] }}: ! auf[{{ $kap['kapitel_id'] }}]}" style="padding-left: {{ 16 + ($kap['depth'] - 1) * 16 }}px">
                                            <span class="inline-flex shrink-0 text-[var(--fa-ink-3)] transition-transform" :class="auf[{{ $kap['kapitel_id'] }}] && 'rotate-90'">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                                            <span class="min-w-0 break-words text-[length:var(--fa-text-md)] font-medium {{ $kap['is_struktur'] ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-ink)]' }}">{{ $kap['titel'] }}</span>
                                            @if($kap['is_struktur'])
                                                <x-fa::badge title="Textkapitel ohne eigenes Essen">Textkapitel</x-fa::badge>
                                            @elseif($kap['pricing_mode'])
                                                <span class="{{ $hinweis }}">{{ $preisModusText($kap['pricing_mode']) }}</span>
                                            @endif
                                            <div class="ml-auto flex flex-wrap items-center justify-end gap-2 tabular-nums" @click.stop>
                                                @unless($kap['is_struktur'])
                                                    <x-fa::badge :tone="$kap['hat_ziele'] ? 'accent' : 'neutral'" :title="$kap['hat_ziele'] ? 'Ziele gesetzt (Anzahl oder Preisanker)' : 'Noch keine Ziele gesetzt'">{{ $kap['hat_ziele'] ? 'Ziele gesetzt' : 'Ohne Ziele' }}</x-fa::badge>
                                                    <x-fa::badge :tone="$kap['positionen_count'] > 0 ? 'info' : 'neutral'">{{ $kap['positionen_count'] }} {{ $kap['positionen_count'] === 1 ? 'Position' : 'Positionen' }}</x-fa::badge>
                                                    @if($kap['bepreist'])
                                                        <x-fa::badge tone="ok">bepreist</x-fa::badge>
                                                    @elseif($kap['hat_inhalt'])
                                                        <x-fa::badge tone="warn">ohne Preis</x-fa::badge>
                                                    @else
                                                        <x-fa::badge>leer</x-fa::badge>
                                                    @endif
                                                @endunless
                                                @if(($agg['ek_per_person'] ?? 0) > 0)
                                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" title="Wareneinsatz je Gast">EK {{ $euro($agg['ek_per_person']) }}</span>
                                                @endif
                                                @if(($agg['vk_pro_person'] ?? 0) > 0)
                                                    <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]" title="{{ $istRollup ? 'Summe der Unterkapitel' : 'Verkaufspreis je Gast' }}">@if($istRollup)<span class="font-normal text-[var(--fa-ink-3)]">Summe&nbsp;</span>@endif{{ $euro($agg['vk_pro_person']) }}<span class="font-normal text-[var(--fa-ink-3)]">/Gast</span></span>
                                                @endif
                                                <x-fa::badge :tone="$weTon[$we['status']] ?? 'neutral'"
                                                    title="Wareneinsatz {{ $we['ist_pct'] !== null ? $prozent($we['ist_pct']) : 'unbekannt' }}, Ziel {{ $prozent($we['ziel_pct']) }}">WE {{ $we['ist_pct'] !== null ? $prozent($we['ist_pct']) : 'offen' }}</x-fa::badge>
                                                {{-- Fortschritt als Chip mit Menü (statt Dropdown je Zeile) --}}
                                                <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="fort-{{ $kap['kapitel_id'] }}-{{ $kap['fortschritt'] }}">
                                                    <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Fortschritt von {{ $kap['titel'] }} ändern" title="Fortschritt setzen">
                                                        <x-fa::badge :tone="$fortTon[$kap['fortschritt']] ?? 'neutral'">{{ $fortLabel[$kap['fortschritt']] ?? 'Offen' }}</x-fa::badge>@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                                    </button>
                                                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-40 fa-surface shadow-lg py-1">
                                                        @foreach($fortLabel as $fortWert => $fortText)
                                                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="kapitelFortschritt({{ $kap['kapitel_id'] }}, '{{ $fortWert }}')"
                                                                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $kap['fortschritt'] === $fortWert ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                                                {{ $fortText }}@if($kap['fortschritt'] === $fortWert)@svg('heroicon-m-check', 'w-4 h-4')@endif
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <x-fa::button variant="ghost" size="sm" icon="heroicon-m-pencil-square" wire:click="kapitelWaehle({{ $kap['kapitel_id'] }})" x-on:click="$dispatch('angebot-goto', { tab: 'aufbau' })" title="Kapitel öffnen und weiterplanen">Bearbeiten</x-fa::button>
                                            </div>
                                        </div>
                                        <div x-show="auf[{{ $kap['kapitel_id'] }}]" x-cloak class="pb-3 pr-4 flex flex-col gap-1" style="padding-left: {{ 40 + ($kap['depth'] - 1) * 16 }}px">
                                            @forelse($kap['positionen'] as $p)
                                                <div class="flex flex-wrap items-center gap-2 py-0.5 text-[length:var(--fa-text-md)]">
                                                    <x-fa::badge :tone="$p['art'] === 'paket' ? 'accent' : 'info'">{{ $p['art'] === 'paket' ? 'Paket' : 'Einzeln' }}</x-fa::badge>
                                                    <span class="min-w-0 break-words text-[var(--fa-ink)]">{{ $p['label'] }}</span>
                                                    <div class="ml-auto flex items-center gap-3 tabular-nums shrink-0">
                                                        @if(($p['ek'] ?? 0) > 0)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">EK {{ $euro($p['ek']) }}</span>@endif
                                                        <x-fa::money :value="($p['vk'] ?? 0) > 0 ? $p['vk'] : null" :per="($p['preis_einheit'] ?? 'gast') === 'gast' ? 'Gast' : 'Position'" class="font-semibold text-[var(--fa-ink)]" />
                                                        @if(($p['we_pct'] ?? null) !== null)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" title="Wareneinsatz dieser Position">{{ $prozent($p['we_pct']) }}</span>@endif
                                                    </div>
                                                </div>
                                            @empty
                                                @if($istRollup)
                                                    <p class="{{ $hinweis }}">Keine eigenen Positionen. Der Preis ist die Summe der Unterkapitel.</p>
                                                @else
                                                    <p class="{{ $hinweis }}">Noch keine bepreisten Positionen. Im Reiter «Aufbau» oder in der Leitstelle anlegen.</p>
                                                @endif
                                            @endforelse
                                            @if(! empty($boardCoverage[$kap['kapitel_id']] ?? []))
                                                <div class="flex flex-wrap gap-x-4 gap-y-1 pt-2 mt-1 border-t border-[var(--fa-line)]">
                                                    @foreach($boardCoverage[$kap['kapitel_id']] as $b)
                                                        <x-fa::signal :tone="$befundTon[$b['ampel']] ?? 'info'">{{ $b['label'] }}: {{ $b['ist'] }}</x-fa::signal>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Kapitel">
                                        Links unter «Neues Kapitel» anlegen oder das Angebot in der Leitstelle planen lassen.
                                    </x-fa::empty>
                                @endforelse
                            </div>
                        </x-fa::section>
                    </div>
                </div>

                {{-- ═══ Reiter: AUFBAU — Kapitel-Editor (Kapitel · Inhalt · Kundentext/KI · Bilder) + Katalog rechts ═══ --}}
                <div x-show="tab === 'aufbau'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="aufbau" data-angebot-anker="aufbau">
                @if($kapitel)
                    <div class="flex gap-4 items-start" data-angebot-aufbau-2col>
                    <div class="flex-1 min-w-0 flex flex-col gap-4" data-angebot-aufbau-links>
                    {{-- Kapitel-Kopf --}}
                    <x-fa::section title="Kapitel" icon="heroicon-o-bookmark" wire:key="kaphdr-{{ $kapitel->id }}">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                            <x-fa::field label="Interner Name" for="ang-kap-titel">
                                <x-fa::input id="ang-kap-titel" wire:model.blur="kapitelForm.title" wire:change="kapitelSpeichern" />
                            </x-fa::field>
                            <x-fa::field label="Titel für den Kunden" for="ang-kap-kundentitel" class="md:col-span-2">
                                <x-fa::input id="ang-kap-kundentitel" wire:model.blur="kapitelForm.consumer_title" wire:change="kapitelSpeichern" placeholder="So steht es im Angebot" />
                            </x-fa::field>
                            <x-fa::field label="Preis" for="ang-kap-preismodus">
                                <x-fa::select id="ang-kap-preismodus" wire:model.live="kapitelForm.price_mode" wire:change="kapitelSpeichern">
                                    <option value="auto">Aus dem Inhalt</option>
                                    <option value="manuell">Von Hand</option>
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Gäste im Kapitel" for="ang-kap-pax" hint="Leer übernimmt die Gäste des Angebots">
                                <x-fa::input id="ang-kap-pax" type="number" min="0" numeric wire:model.blur="kapitelForm.personen" wire:change="kapitelSpeichern"
                                    placeholder="{{ $angebot->personen ?: '–' }}" />
                            </x-fa::field>
                        </div>
                        <label class="flex items-start gap-2 cursor-pointer text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                            <input type="checkbox" wire:model.live="kapitelForm.is_struktur" wire:change="kapitelSpeichern" class="mt-0.5 {{ $haken }}" />
                            <span>Textkapitel <span class="{{ $hinweis }}">ohne eigenes Essen, etwa Einleitung oder Überschrift. Kennzahlen kommen dann nur aus den Unterkapiteln.</span></span>
                        </label>
                    </x-fa::section>

                    {{-- Inhalt (Block-Liste) — die häufigste Arbeit im Aufbau --}}
                    <x-fa::section title="Inhalt" icon="heroicon-o-squares-2x2" :meta="(string) $kapitel->blocks->count()" data-angebot-inhalt>
                        <x-slot:actions>
                            @if(count($markiert ?? []) >= 2)
                                <x-fa::button size="sm" icon="heroicon-m-arrows-right-left" wire:click="wahlGruppeBilden" title="Die markierten Konzepte werden zur Auswahl für den Kunden">Wahlgruppe bilden ({{ count($markiert) }})</x-fa::button>
                            @endif
                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-plus" wire:click="blockBasis('text')">Text</x-fa::button>
                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-plus" wire:click="blockBasis('spacer')">Leerzeile</x-fa::button>
                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                <x-fa::button size="sm" icon="heroicon-m-plus" icon-right="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Überschrift</x-fa::button>
                                <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 max-h-80 overflow-y-auto fa-surface shadow-lg py-1">
                                    <button type="button" role="menuitem" wire:click="blockBasis('header_frei')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                        @svg('heroicon-o-bars-3-bottom-left', 'w-4 h-4 text-[var(--fa-ink-3)]') Freie Überschrift
                                    </button>
                                    <button type="button" role="menuitem" wire:click="blockBasis('header_frei_preis')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                        @svg('heroicon-o-currency-euro', 'w-4 h-4 text-[var(--fa-ink-3)]') Überschrift mit Preis
                                    </button>
                                    @foreach($headerPresets ?? [] as $gruppe => $items)
                                        <p class="px-3 pt-2 pb-0.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">{{ $gruppe }}</p>
                                        @foreach($items as $p)
                                            <button type="button" role="menuitem" x-on:click="offen = false"
                                                    wire:click="presetHinzu(@js($p['type']), @js($p['slug']), @js($p['label']), @js($p['price_basis'] ?? null), {{ ($p['visible'] ?? true) ? 'true' : 'false' }})"
                                                    class="block w-full truncate px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">{{ $p['label'] }}</button>
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        </x-slot:actions>

                        <div class="flex flex-col gap-1" x-data="{ dragBlockId: null }">
                            @forelse($kapitel->blocks as $block)
                                <div wire:key="block-{{ $block->id }}"
                                     @dragover.prevent @drop.prevent="if (dragBlockId && dragBlockId !== {{ $block->id }}) { $wire.blockVerschiebenAuf(dragBlockId, {{ $block->id }}); } dragBlockId = null"
                                     :class="dragBlockId === {{ $block->id }} ? 'opacity-40' : (dragBlockId ? 'ring-1 ring-[var(--fa-accent-line)]' : '')"
                                     class="rounded-[var(--fa-radius-control)] border {{ $block->variant_group_id ? 'border-[var(--fa-warn)]' : 'border-[var(--fa-line)]' }} bg-[var(--fa-surface)] px-2 py-1.5 {{ $block->visible ? '' : 'opacity-60' }}"
                                     style="margin-left: {{ $block->level * 20 }}px">
                                    <div class="flex items-center gap-1.5 text-[length:var(--fa-text-md)]">
                                        <span class="inline-flex items-center justify-center w-4 shrink-0 cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] select-none" draggable="true"
                                              @dragstart="dragBlockId = {{ $block->id }}; $event.dataTransfer.setData('text/plain', String({{ $block->id }})); $event.dataTransfer.effectAllowed = 'move'"
                                              @dragend="dragBlockId = null" title="Ziehen zum Sortieren" data-block-drag>@svg('heroicon-m-bars-2', 'w-3.5 h-3.5')</span>
                                        <span class="flex flex-col shrink-0">
                                            <button type="button" wire:click="blockHoch({{ $block->id }})" class="inline-flex items-center justify-center w-5 h-3.5 text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
                                            <button type="button" wire:click="blockRunter({{ $block->id }})" class="inline-flex items-center justify-center w-5 h-3.5 text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
                                        </span>
                                        @if($block->type === 'concept_ref')
                                            <input type="checkbox" wire:click="markiere({{ $block->id }})" @checked(in_array($block->id, $markiert ?? [])) title="Für eine Wahlgruppe markieren" aria-label="Für eine Wahlgruppe markieren" class="shrink-0 {{ $haken }}" />
                                        @else
                                            <span class="w-3.5 shrink-0"></span>
                                        @endif
                                        <span class="flex-1 min-w-0 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                            @switch($block->type)
                                                @case('concept_ref')
                                                    <x-fa::badge tone="accent">Konzept</x-fa::badge>
                                                    <span class="min-w-0 break-words text-[var(--fa-ink)]">{{ $block->concept?->name ?? '–' }}</span>
                                                    @if($block->concept?->istEinzelpreis())
                                                        <span class="{{ $hinweis }}">Einzelpreise</span>
                                                    @elseif($block->concept !== null)
                                                        <x-fa::money :value="$block->concept->price_per_person_cache" per="Gast" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                                    @endif
                                                    @if(trim((string) $block->wording) !== '')<span class="italic text-[var(--fa-accent)]">„{{ $block->wording }}“</span>@endif
                                                    @break
                                                @case('recipe_ref')
                                                    <x-fa::badge tone="info">Gericht</x-fa::badge>
                                                    <span class="min-w-0 break-words text-[var(--fa-ink)]">{{ $block->dish?->name ?? '–' }}</span>
                                                    @if($block->dish !== null)
                                                        <x-fa::money :value="$block->dish->sales_net" :per="$block->price_basis === 'pauschal' ? null : 'Position'" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                                        @if($block->price_basis === 'pauschal' && $block->dish->sales_net !== null)<span class="{{ $hinweis }}">pauschal</span>@endif
                                                    @endif
                                                    @if(trim((string) $block->wording) !== '')<span class="italic text-[var(--fa-accent)]">„{{ $block->wording }}“</span>@endif
                                                    @break
                                                {{-- Spec 50 · C-7: die PERSISTIERTEN Typen sind `header`/`header_preis`
                                                     (OfferCompositionService::TYP_ALIAS loest die Foodbook-Namen auf).
                                                     Bis hierher standen nur die Foodbook-Namen — jeder Header fiel in
                                                     @default und wurde als kursives „(Text)" gerendert. --}}
                                                @case('header')
                                                    <span class="font-semibold text-[var(--fa-ink)]">{{ $block->label ?: 'Überschrift ohne Text' }}</span>
                                                    @break
                                                @case('header_preis')
                                                    <span class="font-semibold text-[var(--fa-ink)]">{{ $block->label ?: 'Überschrift ohne Text' }}</span>
                                                    <x-fa::money :value="$block->price_value" :per="$block->price_basis === 'pauschal' ? null : 'Gast'" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                                    @if($block->price_basis === 'pauschal')<span class="{{ $hinweis }}">pauschal</span>@endif
                                                    @break
                                                @case('spacer')
                                                    <span class="italic text-[var(--fa-ink-3)]">Leerzeile ({{ $block->height ?? 'mittel' }})</span>
                                                    @break
                                                @default
                                                    <span class="italic text-[var(--fa-ink-2)]">{{ \Illuminate\Support\Str::limit($block->customer_text ?? 'Text ohne Inhalt', 80) }}</span>
                                            @endswitch
                                        </span>
                                        @if($block->variant_group_id)
                                            <button type="button" wire:click="wahlGruppeAufheben({{ $block->id }})" class="shrink-0" title="Aus der Wahlgruppe lösen">
                                                <x-fa::badge tone="warn" icon="heroicon-m-arrows-right-left">Wahlgruppe {{ $block->variant_group_id }}</x-fa::badge>
                                            </button>
                                        @endif
                                        <button type="button" wire:click="blockEbene({{ $block->id }}, -1)" class="{{ $mini }}" title="Ausrücken" aria-label="Ausrücken">@svg('heroicon-m-arrow-left', 'w-3.5 h-3.5')</button>
                                        <button type="button" wire:click="blockEbene({{ $block->id }}, 1)" class="{{ $mini }}" title="Einrücken" aria-label="Einrücken">@svg('heroicon-m-arrow-right', 'w-3.5 h-3.5')</button>
                                        @if($block->type === 'concept_ref' && $block->concept_id)
                                            <a href="{{ route('foodalchemist.concepter.index', ['edit' => $block->concept_id]) }}" target="_blank" class="{{ $mini }}" title="Im Concepter öffnen" aria-label="Im Concepter öffnen" data-angebot-block-concepter>@svg('heroicon-m-arrow-top-right-on-square', 'w-3.5 h-3.5')</a>
                                        @endif
                                        <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="{{ $block->visible ? $mini : 'inline-flex items-center gap-1 h-6 px-1.5 shrink-0 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)] hover:bg-[var(--fa-warn-soft)]' }}"
                                                title="{{ $block->visible ? 'Für den Kunden sichtbar, Klick: nur intern' : 'Nur intern, Klick: für den Kunden sichtbar' }}" aria-label="Sichtbarkeit umschalten">
                                            @if($block->visible)@svg('heroicon-m-eye', 'w-3.5 h-3.5')@else @svg('heroicon-m-eye-slash', 'w-3.5 h-3.5')<span>intern</span>@endif
                                        </button>
                                        @if($block->type !== 'spacer')
                                            <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="{{ $mini }}" title="Bearbeiten und Notiz" aria-label="Bearbeiten">@svg('heroicon-m-pencil', 'w-3.5 h-3.5')</button>
                                        @endif
                                        <button type="button" wire:click="blockRaus({{ $block->id }})" class="{{ $miniKrit }}" title="Entfernen" aria-label="Entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </div>

                                    {{-- Live-Menü-Vorschau (aufgelöste gerichtZeilen) je concept_ref-Block --}}
                                    @if($block->type === 'concept_ref' && ! empty($blockMenus[$block->id] ?? []))
                                        <div class="mt-1.5 ml-10 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-3 py-2 flex flex-col gap-0.5" data-angebot-block-vorschau>
                                            @foreach($blockMenus[$block->id] as $g)
                                                @php
                                                    $istEditierbar = isset($g['slot_id']);
                                                    $slotKey = $istEditierbar ? $block->id . ':' . $g['slot_id'] : null;
                                                @endphp
                                                @if(($g['type'] ?? '') === 'header')
                                                    <p class="mt-1.5 first:mt-0 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</p>
                                                @elseif(($g['type'] ?? '') === 'paket')
                                                    <div class="flex items-center gap-1.5 mt-1" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">
                                                        <x-fa::badge tone="info">Paket</x-fa::badge>
                                                        <span class="min-w-0 break-words text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)]">{{ $g['text'] }}</span>
                                                        @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $euro($g['preis']) }}/Gast</span>@endif
                                                    </div>
                                                @elseif($slotKey !== null && ($editSlotKey ?? null) === $slotKey)
                                                    <div class="flex items-center gap-1" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-angebot-slot-editor>
                                                        <x-fa::input size="sm" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                                                            class="flex-1" placeholder="Anzeigename für den Kunden, leer = Standardtext" aria-label="Anzeigename für den Kunden" data-angebot-slot-input />
                                                        <x-fa::button size="sm" wire:click="slotWordingSpeichern" title="Anzeigename übernehmen">Übernehmen</x-fa::button>
                                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Abbrechen" size="sm" wire:click="slotWordingAbbrechen" />
                                                    </div>
                                                @else
                                                    <div class="group/dish flex items-center gap-1 text-[length:var(--fa-text-sm)] {{ ($g['source'] ?? null) === 'name' ? 'italic text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px">
                                                        <span class="shrink-0 text-[var(--fa-ink-3)]" aria-hidden="true">·</span>
                                                        <span class="break-words">{{ $g['text'] }}</span>
                                                        @if(($g['source'] ?? null) === 'name')<x-fa::signal tone="warn" class="shrink-0 not-italic">Anzeigename fehlt</x-fa::signal>@endif
                                                        @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 text-[var(--fa-ink-3)] tabular-nums">{{ $euro($g['preis']) }}/Gast</span>@endif
                                                        @if($istEditierbar)
                                                            <button type="button" wire:click="slotWordingBearbeiten({{ $block->id }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                                                                    class="ml-1 {{ $mini }} opacity-0 group-hover/dish:opacity-100 focus:opacity-100 transition-opacity" title="Anzeigename bearbeiten" aria-label="Anzeigename bearbeiten" data-angebot-slot-edit>@svg('heroicon-m-pencil', 'w-3.5 h-3.5')</button>
                                                        @endif
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif

                                    @if(($editBlockId ?? null) === $block->id)
                                        <div class="mt-2 ml-10 flex flex-col gap-2 border-t border-[var(--fa-line)] pt-2">
                                            {{-- Persistiert heißen Überschriften `header`/`header_preis` (TYP_ALIAS) — die Foodbook-Namen
                                                 bleiben für Altdaten in der Liste, sonst liesse sich keine Überschrift beschriften. --}}
                                            @if(in_array($block->type, ['header', 'header_preis', 'header_neutral', 'header_frei', 'header_frei_preis'], true))
                                                <x-fa::input wire:model="blockForm.label" placeholder="Text der Überschrift" aria-label="Text der Überschrift" />
                                            @endif
                                            @if(in_array($block->type, ['header_preis', 'header_frei_preis'], true))
                                                <div class="flex flex-wrap gap-2">
                                                    <x-fa::select wire:model="blockForm.price_basis" class="w-40" aria-label="Preisbasis">
                                                        <option value="person">je Gast</option>
                                                        <option value="pauschal">pauschal</option>
                                                    </x-fa::select>
                                                    <x-fa::input type="number" step="0.01" numeric wire:model="blockForm.price_value" class="w-32" placeholder="0,00" aria-label="Preis in €" />
                                                </div>
                                            @endif
                                            @if($block->type === 'concept_ref')
                                                <x-fa::input wire:model="blockForm.wording" placeholder="Anzeigename für den Kunden, leer = Standardtext des Konzepts" aria-label="Anzeigename für den Kunden" data-angebot-block-wording />
                                            @endif
                                            @if($block->type === 'recipe_ref')
                                                <x-fa::input wire:model="blockForm.wording" placeholder="Anzeigename für den Kunden, leer = Standardtext des Gerichts" aria-label="Anzeigename für den Kunden" data-angebot-block-wording />
                                                <x-fa::select wire:model="blockForm.price_basis" class="w-56" aria-label="Preisbasis dieses Gerichts" title="Preisbasis für dieses Gericht">
                                                    <option value="person">je Position mal Gäste</option>
                                                    <option value="pauschal">pauschal</option>
                                                </x-fa::select>
                                            @endif
                                            @if($block->type === 'text')
                                                <x-fa::textarea wire:model="blockForm.customer_text" rows="3" placeholder="Text für den Kunden" aria-label="Text für den Kunden" />
                                            @else
                                                <div class="flex gap-1.5 items-start">
                                                    <x-fa::textarea wire:model="blockForm.customer_text" rows="2" placeholder="Beschreibung oder Untertitel für den Kunden (optional)" aria-label="Beschreibung für den Kunden" />
                                                    @if($block->type === 'concept_ref')
                                                        <x-foodalchemist::ki-action action="kiKundentext" variant="icon" icon="heroicon-o-sparkles" label="Kundentext von der KI"
                                                                title="Verkaufender Beschreibungstext zu diesem Konzept" class="shrink-0 mt-2" data-angebot-ki-kundentext />
                                                    @endif
                                                </div>
                                            @endif
                                            <x-fa::input wire:model="blockForm.interne_bemerkung" placeholder="Interne Notiz, für den Kunden nicht sichtbar" aria-label="Interne Notiz" />
                                            <div class="flex gap-2">
                                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="blockSpeichern">Übernehmen</x-fa::button>
                                                <x-fa::button variant="ghost" size="sm" wire:click="$set('editBlockId', null)">Abbrechen</x-fa::button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-squares-2x2" title="Noch kein Inhalt">
                                    Rechts im Katalog ein Konzept, Paket, Format oder Gericht einfügen oder oben Text, Leerzeile oder Überschrift hinzufügen.
                                    Die Leitstelle legt beim Planen je Kapitel automatisch ein Konzept an, es landet direkt hier.
                                </x-fa::empty>
                            @endforelse
                        </div>
                    </x-fa::section>{{-- /Inhalt --}}

                    {{-- Hinführung (Kundentext des Kapitels) + KI-Text · Schreibstil + Kapitel-Wording --}}
                    <x-fa::section title="Kundentext" icon="heroicon-o-chat-bubble-bottom-center-text"
                        description="Kurzer Text, der im Angebot in dieses Kapitel einführt.">
                        <x-slot:actions>
                            <x-foodalchemist::ki-action action="kiKapitelText" variant="ai" icon="heroicon-o-sparkles" label="Text vorschlagen"
                                    title="Hinführung aus dem Inhalt des Kapitels, der Einleitung des Angebots und der Markenstimme"
                                    busy="Schreibt …" data-angebot-ki-kapiteltext />
                        </x-slot:actions>
                        <x-fa::textarea wire:model.blur="kapitelForm.description" wire:change="kapitelSpeichern" rows="2"
                            class="resize-none min-h-[3.5rem]" aria-label="Hinführung für den Kunden"
                            placeholder="Kurzer Text für den Kunden, «Text vorschlagen» liefert einen Entwurf" />
                        {{-- KI-Vorschau (inline, geteilter Zustand kiTextZiel/kiTextVorschau) --}}
                        @if(($kiTextZiel ?? null) === 'kapitel')
                            @if(($kiTextVorschau ?? null) !== null)
                                @php
                                    $kapTextVorhanden = trim((string) ($kapitelForm['description'] ?? '')) !== '';
                                @endphp
                                <div data-angebot-ki-vorschau>
                                    <x-fa::notice tone="info" title="Vorschlag der KI, noch nicht übernommen{{ ($kiTextConfidence ?? null) !== null ? ' (' . number_format($kiTextConfidence * 100, 0) . ' % sicher)' : '' }}">
                                        <p class="whitespace-pre-line">{{ $kiTextVorschau }}</p>
                                        @if($kapTextVorhanden)
                                            <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">Im Feld steht schon ein Text. «Ersetzen» überschreibt ihn, endgültig erst beim Speichern.</p>
                                        @endif
                                        <x-slot:actions>
                                            <x-fa::button size="sm" variant="ghost" wire:click="kiTextVerwerfen">Verwerfen</x-fa::button>
                                            <x-fa::button size="sm" icon="heroicon-m-check" wire:click="kiTextUebernehmen">{{ $kapTextVorhanden ? 'Ersetzen' : 'Übernehmen' }}</x-fa::button>
                                        </x-slot:actions>
                                    </x-fa::notice>
                                </div>
                            @endif
                            @if(($kiTextHinweis ?? null) !== null)
                                <x-fa::signal tone="warn" data-angebot-ki-hinweis>{{ $kiTextHinweis }}</x-fa::signal>
                            @endif
                        @endif

                        {{-- Schreibstil PRO KAPITEL + Kapitel-Wording neu betexten --}}
                        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]" data-angebot-kapitel-stil>
                            <x-fa::field label="Schreibstil des Kapitels" for="ang-kap-stil" class="flex-1 max-w-xs">
                                <x-fa::select id="ang-kap-stil" wire:model.live="kapitelForm.writing_style_id" wire:change="kapitelSpeichern" data-angebot-kapitel-schreibstil>
                                    <option value="">Standard aus den Konzepten</option>
                                    @foreach($schreibstile ?? [] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                            <x-foodalchemist::ki-action action="kapitelWordingGenerieren" variant="ai" icon="heroicon-o-sparkles" label="Speisen neu betexten"
                                    :disabled="($kapitelForm['writing_style_id'] ?? null) === null || ($kapitelForm['writing_style_id'] ?? '') === ''"
                                    title="Betextet alle Konzepte dieses Kapitels im gewählten Schreibstil neu. Gilt nur für dieses Angebot, das Konzept selbst bleibt unverändert."
                                    busy="Betextet …" class="mb-1" data-angebot-kapitel-wording />
                        </div>
                        @error('kapitelWording')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert" data-angebot-kapitel-fehler>{{ $message }}</p>@enderror
                    </x-fa::section>

                    {{-- Kapitel-Bild (Präsentation) + Galerie --}}
                    <x-fa::section title="Bilder" icon="heroicon-o-photo" description="Ohne eigenes Bild zeigt das Kapitel automatisch das Titelbild des Konzepts." data-angebot-kapitel-image>
                        <x-fa::field label="Kapitelbild für die Präsentation">
                            <div class="flex items-center gap-3 flex-wrap">
                                @if($kapitelImageUrl ?? null)
                                    <img src="{{ $kapitelImageUrl }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="kapitelImageEntfernen" data-angebot-kapitel-image-remove>Bild entfernen</x-fa::button>
                                @endif
                                <input type="file" wire:model="kapitelImageUpload" accept="image/*" class="{{ $dateiFeld }} w-auto" aria-label="Kapitelbild hochladen" data-angebot-kapitel-image-upload>
                                <span wire:loading wire:target="kapitelImageUpload" class="{{ $hinweis }}">Lädt …</span>
                            </div>
                        </x-fa::field>
                        @if($kapitelImageFehler ?? null)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $kapitelImageFehler }}</p>@endif
                        @error('kapitelImageUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror

                        <div class="pt-3 border-t border-[var(--fa-line)]" data-angebot-kapitel-gallery>
                            <x-fa::field label="Weitere Bilder" optional hint="Mehrere Bilder für das Kapitelband. Ersetzen die Bilder des Konzepts.">
                                <div class="flex items-center gap-3 flex-wrap">
                                    @foreach($kapitelGallery ?? [] as $gi)
                                        <div class="relative">
                                            <img src="{{ $gi['url'] }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                            <button type="button" wire:click="kapitelGalerieBildEntfernen({{ $gi['id'] }})"
                                                class="absolute -top-2 -right-2 inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--fa-crit)] text-[var(--fa-on-accent)]"
                                                title="Bild entfernen" aria-label="Bild entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                        </div>
                                    @endforeach
                                    <input type="file" wire:model="kapitelGalleryUpload" accept="image/*" multiple class="{{ $dateiFeld }} w-auto" aria-label="Weitere Bilder hochladen" data-angebot-kapitel-gallery-upload>
                                    <span wire:loading wire:target="kapitelGalleryUpload" class="{{ $hinweis }}">Lädt …</span>
                                </div>
                            </x-fa::field>
                            @error('kapitelGalleryUpload.*')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </x-fa::section>
                    </div>{{-- /linke Spalte --}}

                    {{-- Persistenter Katalog: Konzept · Paket · Format · Gericht. Ein Klick bucht ins gewählte Kapitel. --}}
                    <x-foodalchemist::katalog-picker marker="angebot" switch="katalogModus" :modes="[
                        ['key' => 'concept', 'label' => 'Konzept', 'active' => ($pickerModus ?? 'concept') === 'concept'],
                        ['key' => 'paket', 'label' => 'Paket', 'active' => ($pickerModus ?? 'concept') === 'paket'],
                        ['key' => 'format', 'label' => 'Format', 'active' => ($pickerModus ?? 'concept') === 'format'],
                        ['key' => 'gericht', 'label' => 'Gericht', 'active' => ($pickerModus ?? 'concept') === 'gericht'],
                    ]">
                        @if(($pickerModus ?? 'concept') === 'concept')
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="conceptSuche" placeholder="Konzept suchen" aria-label="Konzept suchen" class="mb-2 shrink-0" data-angebot-katalog-concept />
                            @php
                                $facettenAktiv = collect($conceptFacetten ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty();
                            @endphp
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-concept-facetten>
                                <x-fa::select size="sm" wire:model.live="conceptFacetten.eventtyp" aria-label="Eventtyp" data-angebot-facet-eventtyp>
                                    <option value="">Alle Eventtypen</option>
                                    @foreach($facetteEventtypen ?? [] as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="conceptFacetten.servierform" aria-label="Servierform" data-angebot-facet-servierform>
                                    <option value="">Alle Servierformen</option>
                                    @foreach($facetteServierformen ?? [] as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="conceptFacetten.einsatzmoment" aria-label="Einsatzmoment" data-angebot-facet-einsatzmoment>
                                    <option value="">Alle Einsatzmomente</option>
                                    @foreach($facetteMomente ?? [] as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="conceptFacetten.season" aria-label="Saison" data-angebot-facet-season>
                                    <option value="">Alle Saisons</option>
                                    @foreach($facetteSaisons ?? [] as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                </x-fa::select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($conceptKandidaten ?? [] as $ck)
                                    <x-foodalchemist::katalog-row wire:key="ack-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})" :title="$ck->name" :price="$ck->price_per_person_cache !== null ? $euro($ck->price_per_person_cache) : null">{{ $ck->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="px-2 py-2 {{ $hinweis }}">{{ ($conceptSuche ?? '') !== '' || $facettenAktiv ? 'Keine Konzepte für diese Auswahl.' : 'Noch keine Konzepte angelegt.' }}</p>
                                @endforelse
                            </div>
                        @elseif(($pickerModus ?? 'concept') === 'paket')
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen" aria-label="Paket suchen" class="mb-2 shrink-0" data-angebot-katalog-paket />
                            @php
                                $paketFacettenAktiv = collect($paketFacetten ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty();
                            @endphp
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-paket-facetten>
                                <x-fa::select size="sm" wire:model.live="paketFacetten.eventtyp" aria-label="Eventtyp" data-angebot-paket-facet-eventtyp>
                                    <option value="">Alle Eventtypen</option>
                                    @foreach($facetteEventtypen ?? [] as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="paketFacetten.servierform" aria-label="Servierform" data-angebot-paket-facet-servierform>
                                    <option value="">Alle Servierformen</option>
                                    @foreach($facetteServierformen ?? [] as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="paketFacetten.einsatzmoment" aria-label="Einsatzmoment" data-angebot-paket-facet-einsatzmoment>
                                    <option value="">Alle Einsatzmomente</option>
                                    @foreach($facetteMomente ?? [] as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="paketFacetten.season" aria-label="Saison" data-angebot-paket-facet-season>
                                    <option value="">Alle Saisons</option>
                                    @foreach($facetteSaisons ?? [] as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                </x-fa::select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($paketKandidaten ?? [] as $pk)
                                    <x-foodalchemist::katalog-row wire:key="apk-{{ $pk->id }}" wire:click="paketHinzu({{ $pk->id }})" :title="$pk->consumer_name ?: $pk->name" :price="$pk->price_per_person_cache !== null ? $euro($pk->price_per_person_cache) : null">{{ $pk->consumer_name ?: $pk->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="px-2 py-2 {{ $hinweis }}">{{ ($paketSuche ?? '') !== '' || $paketFacettenAktiv ? 'Keine Pakete für diese Auswahl.' : 'Noch keine Pakete angelegt.' }}</p>
                                @endforelse
                            </div>
                        @elseif(($pickerModus ?? 'concept') === 'format')
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen" aria-label="Format suchen" class="mb-2 shrink-0" data-angebot-katalog-format />
                            @error('formatKapitel')<p class="px-1 mb-1 shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                            <p class="mb-1 shrink-0 {{ $hinweis }}">Bucht ein Format als eigenes Kapitel, die Editionen bleiben aktuell.</p>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($formatKandidaten ?? [] as $fk)
                                    <x-foodalchemist::katalog-row wire:key="afmt-{{ $fk->id }}" wire:click="formatEinfuegen({{ $fk->id }})" :title="$fk->consumer_name ?: $fk->name">{{ $fk->name }}@if($fk->origin === 'kunde')<span class="ml-1 {{ $hinweis }}">(Kundenkonzept)</span>@endif</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="px-2 py-2 {{ $hinweis }}">Keine Formate vorhanden.</p>
                                @endforelse
                            </div>
                        @else
                            {{-- Gericht (recipe_ref): Suche + Hauptgruppe/Untergruppe --}}
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen" aria-label="Gericht suchen" class="mb-2 shrink-0" data-angebot-katalog-gericht />
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-gericht-facetten>
                                <x-fa::select size="sm" wire:model.live="gerichtHauptgruppe" aria-label="Hauptgruppe" data-angebot-gericht-hg>
                                    <option value="">Alle Hauptgruppen</option>
                                    @foreach($gerichtHauptgruppen ?? [] as $hg)<option value="{{ $hg->id }}">{{ $hg->label ?? $hg->name }}</option>@endforeach
                                </x-fa::select>
                                <x-fa::select size="sm" wire:model.live="gerichtDishClass" aria-label="Untergruppe" data-angebot-gericht-klasse :disabled="($gerichtHauptgruppe ?? null) === null">
                                    <option value="">Alle Untergruppen</option>
                                    @foreach($gerichtUntergruppen ?? [] as $ug)<option value="{{ $ug->id }}">{{ $ug->label }}</option>@endforeach
                                </x-fa::select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($gerichtKandidaten ?? [] as $gk)
                                    <x-foodalchemist::katalog-row wire:key="agk-{{ $gk->id }}" wire:click="gerichtHinzu({{ $gk->id }})" :title="$gk->name" :price="$gk->sales_net !== null ? $euro($gk->sales_net) : null">{{ $gk->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="px-2 py-2 {{ $hinweis }}">{{ ($gerichtSuche ?? '') !== '' ? 'Keine Gerichte für diese Auswahl.' : 'Gericht suchen oder Hauptgruppe wählen.' }}</p>
                                @endforelse
                            </div>
                        @endif
                    </x-foodalchemist::katalog-picker>
                    </div>{{-- /2col Aufbau --}}
                @else
                    <div class="fa-surface">
                        <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Kapitel gewählt">
                            Links im Kapitelbaum ein Kapitel wählen, um seinen Aufbau zu bearbeiten, oder unter «Neues Kapitel» eines anlegen.
                        </x-fa::empty>
                    </div>
                @endif
                </div>{{-- /Aufbau-Reiter --}}

                {{-- ═══ Reiter: KALKULATION — Preis je Kapitel (Angebotssumme) · Preis festlegen · Vollkosten (B3) · Mengen ═══ --}}
                <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="kalkulation">
                    {{-- Per-Kapitel-Aufschlüsselung (Σ Kapitel-Pax × €/P) — Kern der Angebots-Kalkulation. --}}
                    @if($kalkulation && ! ($kalkulation['leer'] ?? true) && count($kalkulation['kapitel'] ?? []))
                        <x-fa::section title="Preis je Kapitel" icon="heroicon-o-currency-euro" data-angebot-kalk-kapitel>
                            <div class="overflow-x-auto -mx-4">
                                <table class="fa-table">
                                    <thead>
                                        <tr>
                                            <th class="w-full">Kapitel</th>
                                            <th class="num">Gäste</th>
                                            <th class="num">Preis je Gast</th>
                                            <th class="num">Summe</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($kalkulation['kapitel'] as $kb)
                                            <tr wire:key="kalkkap-{{ $kb['id'] }}">
                                                <td class="min-w-[10rem] text-[var(--fa-ink)]">{{ $kb['titel'] }}</td>
                                                <td class="num text-[var(--fa-ink-2)]">
                                                    {{ $kb['pax'] ?: '–' }}@if($kb['eigene_pax'] ?? false)<span class="text-[var(--fa-accent)]" title="Eigene Gästezahl des Kapitels">*</span>@endif
                                                </td>
                                                <td class="num text-[var(--fa-ink-2)]">
                                                    @if($kb['ist_format'] && ($kb['format_price_mode'] ?? null) === 'alternativen' && ($kb['preis_range'] ?? null))
                                                        {{ $kb['preis_range']['min'] !== null ? number_format((float) $kb['preis_range']['min'], 2, ',', '.') : '–' }} bis {{ $kb['preis_range']['max'] !== null ? $euro($kb['preis_range']['max']) : '–' }}
                                                    @else
                                                        <x-fa::money :value="$kb['vk_pro_person']" />
                                                    @endif
                                                </td>
                                                <td class="num font-medium text-[var(--fa-ink)]">{{ $euro($kb['gesamt'] ?? 0) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="[&>td]:border-t-2 [&>td]:border-[var(--fa-accent-line)]">
                                            <td class="font-semibold text-[var(--fa-ink)]">Angebotssumme</td>
                                            <td class="num text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" title="Durchschnitt der Gäste">Ø {{ $kalkulation['pax'] ?: '–' }}</td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $euro($kalkulation['vk_pro_person']) }}</td>
                                            <td class="num text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-accent)]" data-angebot-summe>{{ $euro($kalkulation['gesamt_vk']) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p class="{{ $hinweis }}">* eigene Gästezahl des Kapitels, sonst gelten die Gäste des Angebots ({{ $angebot->personen ?: '–' }}). Preis je Gast im Kopf = Angebotssumme geteilt durch die Gäste des Angebots.</p>
                        </x-fa::section>
                    @else
                        <div class="fa-surface">
                            <x-fa::empty icon="heroicon-o-calculator" title="Noch nichts zu rechnen">
                                Sobald ein Kapitel Inhalt mit Preis hat, steht hier die Angebotssumme je Kapitel.
                            </x-fa::empty>
                        </div>
                    @endif

                    {{-- Preis-Modus (auto/fixiert) + Begründung --}}
                    <x-fa::section title="Angebotspreis festlegen" icon="heroicon-o-adjustments-horizontal">
                        <x-fa::choice name="form.price_mode" label="Preisermittlung" id-prefix="ang-preis"
                            :options="['auto' => 'Automatisch aus Gästen und Aufbau', 'fixed' => 'Festpreis']" />
                        @if(in_array($form['price_mode'] ?? 'auto', ['fixed', 'manuell'], true))
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <x-fa::field label="Gesamtpreis in €" for="ang-festpreis">
                                    <x-fa::input id="ang-festpreis" type="number" step="0.01" numeric wire:model="form.total_price" />
                                </x-fa::field>
                                <x-fa::field label="Begründung" for="ang-preis-grund" class="md:col-span-2">
                                    <x-fa::input id="ang-preis-grund" wire:model="form.price_override_reason" placeholder="Warum weicht der Angebotspreis ab?" />
                                </x-fa::field>
                            </div>
                            <div><x-fa::button size="sm" icon="heroicon-m-check" wire:click="speichern">Festpreis übernehmen</x-fa::button></div>
                        @else
                            <div><x-fa::button size="sm" icon="heroicon-m-check" wire:click="speichern">Automatischen Preis übernehmen</x-fa::button></div>
                        @endif
                    </x-fa::section>

                    {{-- B3: Vollkosten-/Zuschlagskalkulation über das gesamte Angebot × Pax ($auftragsKalkulation von B1). --}}
                    @includeIf('foodalchemist::livewire.angebote.partials.zuschlagskalkulation')

                    {{-- Mengen-Hochrechnung für die Pax --}}
                    @if($kalkulation && ! ($kalkulation['leer'] ?? true) && ($kalkulation['pax'] ?? 0) > 0 && count($kalkulation['mengen'] ?? []))
                        <x-fa::section title="Mengen für {{ $kalkulation['pax'] }} Gäste" icon="heroicon-o-scale">
                            <div class="max-h-72 overflow-y-auto -mx-4">
                                <table class="fa-table fa-table--compact">
                                    <thead class="sticky top-0 bg-[var(--fa-surface)]">
                                        <tr><th class="w-full">Gericht</th><th class="num">Menge gesamt</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach($kalkulation['mengen'] as $m)
                                            <tr wire:key="mng-{{ $loop->index }}">
                                                <td class="text-[var(--fa-ink-2)]">{{ $m['gericht'] ?? '–' }}</td>
                                                <td class="num"><x-fa::menge :value="$m['gesamt_menge']" :unit="$m['unit'] ?? ''" :decimals="2" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-fa::section>
                    @endif
                </div>

                {{-- ═══ Reiter: KUNDE & BUSINESS-CASE ═══ --}}
                <div x-show="tab === 'kunde'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="kunde">
                    <x-fa::section title="Kunde" icon="heroicon-o-building-office">
                        <x-foodalchemist::crm-kunde-picker
                            :ausgabe="$angebot" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                    </x-fa::section>

                    <x-fa::section title="Business-Case" icon="heroicon-o-presentation-chart-line">
                        @include('foodalchemist::livewire.canvas.partials.board')
                    </x-fa::section>
                </div>

                {{-- ═══ Reiter: BRANDING & PRÄSENTATION (pro Angebot) ═══ --}}
                <div x-show="tab === 'branding'" x-cloak class="pt-4 flex flex-col gap-4" data-angebot-panel="branding"
                     x-data="{ brand: @entangle('brandingForm.brand_color'), band: @entangle('brandingForm.band_color'), footer: @entangle('brandingForm.footer_text') }">
                    <x-fa::section title="Branding für Dokument und PDF" icon="heroicon-o-swatch">
                        <x-slot:actions>
                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-top-right-on-square" href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}?pdf=1" target="_blank" title="Branding im PDF gegenprüfen">Im PDF ansehen</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-check" wire:click="brandingSpeichern" data-branding-speichern>Branding speichern</x-fa::button>
                        </x-slot:actions>

                        @if($brandingFehler ?? null)
                            <x-fa::notice tone="crit" data-branding-fehler>{{ $brandingFehler }}</x-fa::notice>
                        @endif
                        @if($brandingGespeichert ?? false)
                            <x-fa::notice tone="ok">Gespeichert, gilt ab jetzt im Angebotsdokument.</x-fa::notice>
                        @endif

                        {{-- Live-Vorschau: Kopf-Band (Bandfarbe + Logo) · Fuß-Linie (Marken-Farbe) — die Farben sind Daten des Kunden. --}}
                        <x-fa::field label="Vorschau">
                            <div class="rounded-[var(--fa-radius-control)] overflow-hidden border border-[var(--fa-line)]">
                                <div class="flex items-center justify-between gap-2 px-3 h-9 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-on-accent)]" :style="`background:${band || brand}`">
                                    <span class="truncate">{{ $angebot->name }}</span>
                                    @if($angebot->logo_path)<img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->logo_context_file_id, $angebot->logo_path) }}" alt="Logo" class="max-h-5 max-w-[90px] object-contain shrink-0" />@endif
                                </div>
                                <div class="px-3 py-3 bg-[var(--fa-surface)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" :style="`border-top:3px solid ${brand}`">
                                    <span x-text="footer || 'Erstellt mit Food Alchemist'"></span>
                                </div>
                            </div>
                        </x-fa::field>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-fa::field label="Markenfarbe" hint="Rahmen, Linien und Etiketten im PDF.">
                                <div class="flex items-center gap-2">
                                    <input type="color" x-model="brand" class="h-9 w-12 shrink-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-transparent cursor-pointer p-0.5" aria-label="Markenfarbe wählen" data-brand-color />
                                    <x-fa::input x-model="brand" class="w-32 font-mono" placeholder="Farbwert" aria-label="Markenfarbe als Farbwert" />
                                </div>
                            </x-fa::field>
                            <x-fa::field label="Bandfarbe" optional hint="Band in Kopf und Fuß. Leer übernimmt die Markenfarbe.">
                                <div class="flex items-center gap-2">
                                    <input type="color" x-model="band" class="h-9 w-12 shrink-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-transparent cursor-pointer p-0.5" aria-label="Bandfarbe wählen" />
                                    <x-fa::input x-model="band" class="w-32 font-mono" placeholder="wie Marke" aria-label="Bandfarbe als Farbwert" />
                                    <x-fa::icon-button icon="heroicon-m-x-mark" label="Bandfarbe leeren, dann gilt die Markenfarbe" size="sm" x-on:click="band = ''" />
                                </div>
                            </x-fa::field>
                        </div>

                        <x-fa::field label="Fußzeile" for="ang-fusszeile">
                            <x-fa::input id="ang-fusszeile" x-model="footer" placeholder="Erstellt mit Food Alchemist" />
                        </x-fa::field>

                        {{-- Logo + Cover --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-[var(--fa-line)]">
                            <x-fa::field label="Logo" error="logoUpload">
                                @if($angebot->logo_path)
                                    <div class="flex items-center gap-2 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->logo_context_file_id, $angebot->logo_path) }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-1" />
                                        <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingLogoEntfernen" data-logo-entfernen>Logo entfernen</x-fa::button>
                                    </div>
                                @endif
                                <input type="file" wire:model="logoUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Logo hochladen" data-logo-upload />
                                <span wire:loading wire:target="logoUpload" class="{{ $hinweis }}">Lädt …</span>
                            </x-fa::field>
                            <x-fa::field label="Titelbild" error="coverUpload">
                                @if($angebot->cover_image_path)
                                    <div class="flex items-center gap-2 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->cover_context_file_id, $angebot->cover_image_path) }}" alt="Titelbild" class="h-10 max-w-[120px] object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" />
                                        <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingCoverEntfernen" data-cover-entfernen>Titelbild entfernen</x-fa::button>
                                    </div>
                                @endif
                                <input type="file" wire:model="coverUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Titelbild hochladen" data-cover-upload />
                                <span wire:loading wire:target="coverUpload" class="{{ $hinweis }}">Lädt …</span>
                            </x-fa::field>
                        </div>
                    </x-fa::section>

                    {{-- Präsentation — digitales Kundenbuch (Kundenlink + eingefrorener Stand + Freigabe) --}}
                    <x-fa::section title="Kundenpräsentation" icon="heroicon-o-globe-alt" description="Das Angebot als Webseite für den Kunden. Beim Veröffentlichen wird der aktuelle Stand eingefroren." data-angebot-praesentation>
                        <x-slot:actions>
                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-top-right-on-square" href="{{ route('foodalchemist.einstellungen', ['sektion' => 'praesentations-designs']) }}" target="_blank">Designs gestalten</x-fa::button>
                        </x-slot:actions>

                        @if($presentationHinweis ?? null)<x-fa::notice tone="ok" data-angebot-praes-hinweis>{{ $presentationHinweis }}</x-fa::notice>@endif
                        @if($presentationFehler ?? null)<x-fa::notice tone="crit" data-angebot-praes-fehler>{{ $presentationFehler }}</x-fa::notice>@endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-fa::field label="Design" for="ang-praes-design">
                                <x-fa::select id="ang-praes-design" wire:model="presentationDesign" data-angebot-praes-design>
                                    @foreach($presentationDesignOptionen ?? [] as $opt)
                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Gültig bis" for="ang-praes-gueltig" required>
                                <x-fa::input id="ang-praes-gueltig" type="date" wire:model.live="presentationGueltigBis" data-angebot-praes-gueltig />
                            </x-fa::field>
                        </div>

                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer"><input type="checkbox" wire:model="presentationPreisAnzeige" class="{{ $haken }}"> Preise je Gast zeigen</label>
                            <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer"><input type="checkbox" wire:model="presentationDeklaration" class="{{ $haken }}"> Allergen-Legende zeigen</label>
                            <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen, neue Speisen kommen mit aktuellem Preis dazu. An: alle aktuellen Verkaufspreise übernehmen. Die erste Veröffentlichung ist immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren" class="{{ $haken }}"> Preise aktualisieren</label>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-fa::field label="Text des Aktionsknopfs" for="ang-praes-cta" optional>
                                <x-fa::input id="ang-praes-cta" wire:model="presentationCtaText" placeholder="z. B. Jetzt anfragen" />
                            </x-fa::field>
                            <x-fa::field label="Link des Aktionsknopfs" for="ang-praes-cta-link" optional>
                                <x-fa::input id="ang-praes-cta-link" type="url" wire:model="presentationCtaLink" placeholder="https://…" />
                            </x-fa::field>
                        </div>

                        <x-fa::field label="Eigener Linkname" for="ang-praes-slug" optional>
                            <x-fa::input id="ang-praes-slug" wire:model.live.debounce.400ms="presentationSlug" placeholder="z. B. broich-empfang-2027" data-angebot-praes-slug />
                            <p class="{{ $hinweis }}">
                                Kundenlink:
                                <span class="font-mono break-all text-[var(--fa-ink-2)]">{{ url('/p/angebot') }}/@if(trim((string) ($presentationSlug ?? '')) !== ''){{ \Illuminate\Support\Str::slug($presentationSlug) }}@else<span class="italic">automatischer Code</span>@endif</span>.
                                Gilt nach dem Veröffentlichen. Leer lassen ergibt einen zufälligen Code.
                            </p>
                        </x-fa::field>

                        @if($presentationInfo['design_veraltet'] ?? false)
                            {{-- Bug-Runde 2026-09-17 #2: Der Link rendert nur den eingefrorenen Snapshot.
                                 Ohne diesen Hinweis sieht man die Design-Änderung in der Vorschau, im
                                 Kundenlink aber nie — und hält das für einen Render-Fehler. --}}
                            <x-fa::notice tone="warn" title="Design wurde nach der Veröffentlichung geändert" data-fa-design-veraltet>
                                Der Kundenlink zeigt weiter den Stand vom {{ $presentationInfo['published_at'] ?? '–' }}. Zum Übernehmen unten «Neu veröffentlichen».
                            </x-fa::notice>
                        @endif
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::button variant="ghost" icon="heroicon-m-eye" href="{{ route('foodalchemist.angebote.praesentation', ['id' => $angebot->id, 'design' => $presentationDesign]) }}" target="_blank">Vorschau öffnen</x-fa::button>
                            <x-fa::button icon="heroicon-m-globe-alt" wire:click="veroeffentlichen"
                                wire:confirm="Diesen Stand als Kundenpräsentation veröffentlichen? Der Stand wird eingefroren."
                                data-angebot-praes-publish :disabled="! ($presentationGueltigBis ?? null)">
                                {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                            </x-fa::button>
                            @if($presentationInfo['enabled'] ?? false)
                                <x-fa::button variant="danger" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link ist dann nicht mehr erreichbar." data-angebot-praes-withdraw>Veröffentlichung zurückziehen</x-fa::button>
                            @endif
                        </div>
                        @unless($presentationGueltigBis ?? null)
                            <x-fa::signal tone="warn">Zum Veröffentlichen ein Datum bei «Gültig bis» setzen.</x-fa::signal>
                        @endunless

                        @if($presentationLink ?? null)
                            <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3 flex flex-col gap-1.5" x-data>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 min-w-0 rounded-[var(--fa-radius-control)] px-2 py-1 font-mono text-[length:var(--fa-text-sm)] break-all select-all bg-[var(--fa-surface)] border border-[var(--fa-line)] text-[var(--fa-ink)]" data-angebot-praes-link>{{ $presentationLink }}</div>
                                    <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.querySelector('span').textContent='Kopiert'"><span>Link kopieren</span></x-fa::button>
                                </div>
                                <p class="{{ $hinweis }}">
                                    Freigegeben am {{ $presentationInfo['published_at'] ?? '–' }}, gültig bis {{ $presentationInfo['expires_at'] ?? '–' }},
                                    {{ ($presentationInfo['live'] ?? false) ? 'aktiv' : 'inaktiv oder abgelaufen' }}
                                </p>
                            </div>
                        @endif

                        {{-- Betriebs-Links — pro Betrieb ein eigener Link --}}
                        <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]">
                            <div>
                                <h4 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Links je Betrieb</h4>
                                <p class="{{ $hinweis }}">Ein zusätzlicher Kundenlink je Betrieb, eingefroren mit den Preisen und der Vorlage dieses Betriebs und mit eigener Freigabe. Der Standardlink oben bleibt bestehen.</p>
                            </div>

                            @forelse($betriebsLinks ?? [] as $bl)
                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-2.5 flex flex-col gap-1.5" x-data>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $bl['outlet_name'] }}</span>
                                        <x-fa::badge :tone="$bl['enabled'] ? 'ok' : 'neutral'">{{ $bl['enabled'] ? 'aktiv' : 'inaktiv' }}</x-fa::badge>
                                        <span class="ml-auto {{ $hinweis }}">Vorlage: {{ $bl['design'] }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="flex-1 min-w-0 rounded-[var(--fa-radius-control)] px-2 py-1 font-mono text-[length:var(--fa-text-sm)] break-all select-all bg-[var(--fa-ground)] border border-[var(--fa-line)] text-[var(--fa-ink)]">{{ $bl['url'] }}</div>
                                        <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.querySelector('span').textContent='Kopiert'"><span>Kopieren</span></x-fa::button>
                                        @if($bl['enabled'])
                                            <x-fa::button variant="danger" size="sm" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebslink zurückziehen? Er ist dann nicht mehr erreichbar.">Zurückziehen</x-fa::button>
                                        @else
                                            <x-fa::button size="sm" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})">Wieder freigeben</x-fa::button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="{{ $hinweis }}">Noch kein Link für einen Betrieb angelegt.</p>
                            @endforelse

                            @if(count($betriebsOptionen ?? []) > 0)
                                <div class="rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line-strong)] p-3 flex flex-col gap-2">
                                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Weiteren Betrieb hinzufügen</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 items-end">
                                        <x-fa::field label="Betrieb" for="ang-betrieb">
                                            <x-fa::select id="ang-betrieb" wire:model="outletPublishId" placeholder="Betrieb wählen">
                                                @foreach($betriebsOptionen as $o)
                                                    <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Gültig bis" for="ang-betrieb-gueltig" optional>
                                            <x-fa::input id="ang-betrieb-gueltig" type="date" wire:model="outletPublishGueltigBis" />
                                        </x-fa::field>
                                        <x-fa::field label="Vorlage" for="ang-betrieb-design" optional>
                                            <x-fa::select id="ang-betrieb-design" wire:model="outletPublishDesign" placeholder="Vorlage des Betriebs oder wie Dokument">
                                                @foreach($presentationDesignOptionen ?? [] as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Linkname" for="ang-betrieb-slug" optional>
                                            <x-fa::input id="ang-betrieb-slug" wire:model="outletPublishSlug" placeholder="z. B. broich-nord-2027" />
                                        </x-fa::field>
                                    </div>
                                    <div><x-fa::button size="sm" icon="heroicon-m-plus" wire:click="betriebVeroeffentlichen">Betrieb hinzufügen</x-fa::button></div>
                                    <p class="{{ $hinweis }}">Beliebig viele Betriebe möglich, je Betrieb ein eigener Link. Ohne eigenes Datum gilt «Gültig bis» des Standardlinks.</p>
                                </div>
                            @else
                                <x-fa::signal tone="info">Noch keine Betriebe angelegt. Das geht unter Einstellungen, Betriebe.</x-fa::signal>
                            @endif
                        </div>
                    </x-fa::section>
                </div>{{-- /Branding & Präsentation --}}

                </x-foodalchemist::editor-tabs>
            </div>{{-- /angcockpit --}}
        </div>{{-- /Mitte --}}
    </div>{{-- /2-Spalten-Cockpit --}}
    @endif
</x-foodalchemist::modal>
