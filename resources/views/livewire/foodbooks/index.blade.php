{{-- M11-03 / Doc 15 §9.3: Foodbook — stellt Concepts zu einem Kunden-Portfolio zusammen.
     fa-pass (2026-10-05): Übersicht hell, Editor im Werkbank-Modus (darkCanvas → data-fa-theme="dark"),
     nur --fa-*-Tokens und x-fa-Bausteine. Grundanordnung wie zuvor: links Foodbook-Liste, Mitte die
     Kundensicht, rechts Details; im Editor links Kapitelbaum, Mitte Reiter, im Speisen-Reiter rechts der Katalog.

     Häufigste Aufgabe: ein Kapitel öffnen und seine Speisen pflegen (einfügen, sortieren, Gästetext),
     danach Stand und Preise im Überblick prüfen. Deshalb: Kapitelbaum immer links erreichbar, der Überblick
     ist der Start-Reiter, Speisen erscheinen, sobald ein Kapitel gewählt ist.

     Laptop-Breite (Dominique 2026-10-05: „auf dem großen Bildschirm passt es, auf dem Laptop nicht"):
     Seitenleisten etwas schmaler, Kapitelbaum unter 2xl schmaler, Zeilen brechen um statt zu quetschen,
     selten genutzte Zeilen-Aktionen liegen in einem Menü je Zeile, der Katalog rutscht unter xl unter den Inhalt.

     Alle wire:-Bindungen, wire:keys, Event-Namen (modal.open, fb-goto, fb-cockpit-tab) und data-Marker unverändert. --}}
@php
    $aktiv = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    $hover = 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuePunktRot = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $menueGruppe = 'px-3 pt-2 pb-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]';
    $menueSymbol = 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $kleinSymbol = 'inline-flex items-center justify-center w-7 h-7 shrink-0 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] transition-colors duration-150';
    $dateiFeld = 'block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:text-[length:var(--fa-text-sm)] file:font-medium hover:file:bg-[var(--fa-hover)]';
    $farbFeld = 'h-9 w-12 shrink-0 cursor-pointer rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-transparent p-0.5';
    $haken = 'w-4 h-4 shrink-0 accent-[var(--fa-accent)]';
    $medien = fn ($fileId, $pfad) => app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($fileId, $pfad);
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
    $statusTon = fn ($ausgabe) => ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'info' => 'info', 'primary' => 'accent'][$ausgabe->statusWert()->badgeVariant()] ?? 'neutral';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Foodbook / Portfolio" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Foodbook / Portfolio'],
        ]" />
    </x-slot>

    {{-- Laptop: w-72 statt w-80 — beide Seitenleisten zusammen geben der Mitte 64 px zurück. --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Foodbooks" width="w-72">
            <div class="p-3 flex flex-col gap-3">
                <x-fa::input type="search" wire:model.live.debounce.300ms="search" placeholder="Foodbook oder Kunde suchen" aria-label="Foodbook oder Kunde suchen" class="w-full" />
                {{-- Phasen-Filter entfernt (2026-08-31): die alte Foodbook-Statusmaschine ist tot (Planung
                     lebt in der Leitstelle), phase wird nicht mehr fortgeschrieben → Filter filterte auf statischen Daten. --}}
                <x-fa::button icon="heroicon-m-plus" wire:click="neu" class="w-full">Foodbook anlegen</x-fa::button>
                <div class="-mx-1 flex flex-col gap-0.5">
                    @forelse($foodbooks as $f)
                        <button type="button" wire:key="fb-{{ $f->id }}" wire:click="waehle({{ $f->id }})"
                                class="w-full text-left px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedId === $f->id ? $aktiv : $hover }}"
                                @if($selectedId === $f->id) aria-current="true" @endif>
                            <span class="block text-[length:var(--fa-text-md)] leading-snug break-words">{{ $f->label }}</span>
                            <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $f->crmCompany?->display_name ?? 'Kein Kunde' }} · {{ $f->chapters_count }} Kapitel</span>
                        </button>
                    @empty
                        <x-fa::empty icon="heroicon-o-book-open" title="{{ $search !== '' ? 'Kein Treffer' : 'Noch keine Foodbooks' }}" compact>
                            {{ $search !== '' ? 'Anderen Suchbegriff versuchen.' : 'Mit „Foodbook anlegen“ das erste Portfolio beginnen.' }}
                        </x-fa::empty>
                    @endforelse
                </div>

                {{-- Kapitel-Navigation lebt im Editor-Modal (linke Navi-Spalte, Spec 29 / S8).
                     Die Seiten-Sidebar führt nur die Foodbook-Liste — kein doppelter Baum. --}}
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail-Panel (nur lesen) — konsistent zu Speisekarte/Speiseplan. --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-72" scope="activity_foodbook" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($fb)
                @include('foodalchemist::livewire.foodbooks.partials.detail', ['fb' => $fb])
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-book-open" title="Kein Foodbook gewählt">Links ein Foodbook anklicken, dann erscheinen hier Preis, Kapitel und offene Punkte.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-4 sm:px-6 pb-6" spacing="space-y-4">
        @if($fb)
            {{-- ═══ LISTEN-EBENE: Kundensicht + Ausgabe (Spec 29). Ansehen ≠ Bearbeiten. ═══ --}}
            <x-fa::page-header :title="$fb->label" :subtitle="$fb->crmCompany?->display_name ?? 'Kein Kunde zugeordnet'" class="pt-2">
                <x-slot:actions>
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                            {{-- #5a: technischer Report (Profile + Filter + Produktions-Kaskade + Kapitel-Filter). --}}
                            <a href="{{ route('foodalchemist.foodbooks.report', $fb->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}"
                               title="Ausführlicher Bericht mit Preisen, Lieferanten, Deklaration und Nährwerten, nach Kapiteln filterbar">
                                @svg('heroicon-o-document-text', $menueSymbol) Bericht öffnen
                            </a>
                            <a href="{{ route('foodalchemist.foodbooks.praesentation', $fb->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}"
                               title="Web-Seite für den Kunden mit Preisen pro Person, ohne interne Angaben">
                                @svg('heroicon-o-presentation-chart-bar', $menueSymbol) Präsentation ansehen
                            </a>
                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                            <button type="button" role="menuitem" wire:click="duplizieren" x-on:click="offen = false"
                                    wire:confirm="Dieses Foodbook mit allen Kapiteln und Einträgen als Kopie (Entwurf) anlegen?" class="{{ $menuePunkt }}" data-fb-duplizieren>
                                @svg('heroicon-o-document-duplicate', $menueSymbol) Foodbook duplizieren
                            </button>
                        </div>
                    </div>
                    <x-fa::button :href="route('foodalchemist.foodbooks.dokument', $fb->id)" target="_blank" icon="heroicon-m-printer"
                        title="Kundendokument zum Drucken oder als PDF, Allergene und Zusatzstoffe zuschaltbar">Dokument öffnen</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-m-pencil-square" x-on:click="$dispatch('modal.open', { name: 'foodbook-editor' })" data-fb-bearbeiten>Foodbook bearbeiten</x-fa::button>
                </x-slot:actions>
            </x-fa::page-header>

            {{-- Live-Ergebnis (nur lesen, Kundensicht) — dieselbe Quelle wie Dokument/Präsentation --}}
            @include('foodalchemist::livewire.foodbooks.partials.menue-vorschau')

            {{-- ═══════════════ EDITOR = Vollbild-Modal (Spec 29), Werkbank-Modus ═══════════════
                 Gleiche Livewire-Komponente (Index), nur in ein Modal gehüllt — Bus/State/Nested bleiben. --}}
            @php
                $fbKapitelN = $fb->chapters->count();
                $fbSpeisenN = collect($menue['kapitel'] ?? [])->sum(fn ($k) => collect($k['bloecke'] ?? [])->sum(fn ($b) => collect($b['gerichte'] ?? [])->reject(fn ($g) => in_array($g['type'] ?? '', ['paket', 'header'], true))->count()));
                // Ø Wareneinsatz-% (ΣEK/ΣVK) statt Summe aller Kapitel-Preise (Dominique 2026-08-27): Verhältnis = robust.
                $fbWePct = ($menue['gesamt']['food_cost_percent'] ?? null);
                // „Fertig X/Y": Kapitel, die per Überblick-Auswahl auf fortschritt=fertig stehen.
                $fbFertig = collect($kapitelBoard)->where('fortschritt', 'fertig')->count();
            @endphp
            <x-foodalchemist::modal name="foodbook-editor" fullscreen dark-canvas title="Foodbook" :title-name="$fb->label">
                <x-slot:titleExtra>
                    <x-fa::badge :tone="$statusTon($fb)">{{ $fb->statusWert()->label() }}</x-fa::badge>
                </x-slot:titleExtra>

                <x-slot:actions>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        {{-- Entry in die Leitstelle-Planung (Stage 2): öffnet die Leitstelle im Owner-Kontext dieses
                             Foodbooks (fb_owner). Die Planung lebt dort, nicht hier. --}}
                        <x-fa::button icon="heroicon-m-arrow-top-right-on-square" wire:click="vollKaskadeStarten"
                            title="Gerüst und Speisen je Kapitel in der Leitstelle planen lassen" data-fb-in-leitstelle>In der Leitstelle planen</x-fa::button>

                        <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                            <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-60 fa-surface shadow-lg py-1">
                                <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                    @svg('heroicon-o-printer', $menueSymbol) Dokument öffnen
                                </a>
                                <a href="{{ route('foodalchemist.foodbooks.praesentation', $fb->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                    @svg('heroicon-o-presentation-chart-bar', $menueSymbol) Präsentation ansehen
                                </a>
                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                <button type="button" role="menuitem" wire:click="loeschen({{ $fb->id }})" x-on:click="offen = false" wire:confirm="Foodbook löschen?"
                                        class="{{ $menuePunktRot }}" data-fb-loeschen>
                                    @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Foodbook löschen
                                </button>
                            </div>
                        </div>

                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-fb-speichern>Speichern</x-fa::button>
                    </div>
                </x-slot:actions>

                {{-- Spec 29 / S4: Kennzahlen fix im Kopf (scrollen nie weg). Eine Leiste statt Kacheln — spart auf dem
                     Laptop Höhe. Hauptzahl = Ø Wareneinsatz. Alle Werte aus render()-Daten. --}}
                <x-slot:kpiHeader>
                    <x-fa::kpis data-fb-kpis :items="[
                        ['kpi' => 'kapitel', 'label' => 'Kapitel', 'value' => (string) $fbKapitelN],
                        ['kpi' => 'speisen', 'label' => 'Speisen', 'value' => (string) $fbSpeisenN],
                        ['kpi' => 'we', 'label' => 'Ø Wareneinsatz', 'primary' => true,
                         'value' => $fbWePct !== null ? number_format((float) $fbWePct, 1, ',', '.') . ' %' : 'Noch offen',
                         'title' => 'Einkauf durch Verkauf über alle bepreisten Positionen'],
                        ['kpi' => 'fertig', 'label' => 'Kapitel fertig',
                         'tone' => ($fbKapitelN > 0 && $fbFertig >= $fbKapitelN) ? 'ok' : null,
                         'value' => $fbFertig . ' von ' . $fbKapitelN],
                    ]" />
                </x-slot:kpiHeader>

            {{-- Cockpit rendert IMMER, auch mit gewähltem Kapitel (Koexistenz-Bugfix 2026-07-28):
                 `$kapitel` ist genau dann gesetzt, wenn `selectedKapitelId` gesetzt ist. --}}
            {{-- E5.2: Sprung-Event-Bus — `fb-goto` {tab, anker}; E5.3: `fb-cockpit-tab` meldet den aktiven Tab.
                 Tab-Zustand lebt im editor-tabs-Baustein (kein eigenes `tab` am Cockpit-Root, sonst Desync). --}}
            {{-- Spec 29 / S8: Spalten-Cockpit IM Modal — links Navigation (Foodbook + Kapitelbaum), Mitte Reiter.
                 `-mx-6` hebt das px-6 des Modal-Bodys auf; die Mitte bekommt px-6 zurück (sticky Reiterleiste -mx-6). --}}
            <div class="flex gap-4 -mx-6 items-start"
                 x-data="{ ftab: 'board' }" @fb-cockpit-tab="ftab = $event.detail.tab">
                {{-- LINKS: Navigation. Unter 2xl schmaler (Laptop). #4: x-data hält den Kapitel-Drag-Zustand. --}}
                <nav class="w-56 2xl:w-64 shrink-0 pl-6 flex flex-col gap-1" aria-label="Kapitel" data-fb-nav x-data="{ dragKapId: null }">
                    <button type="button" wire:click="kopfAnzeigen"
                            class="w-full flex items-center gap-2 text-left text-[length:var(--fa-text-md)] px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedKapitelId === null ? $aktiv : $hover }}"
                            data-fb-kopf-modal>@svg('heroicon-o-book-open', 'w-4 h-4 shrink-0') Ganzes Foodbook</button>

                    <p class="mt-3 px-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]">Kapitel</p>
                    <div class="flex items-center gap-1">
                        <x-fa::input size="sm" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu" placeholder="Neues Kapitel" aria-label="Titel für ein neues Kapitel" class="min-w-0 flex-1" />
                        <x-fa::icon-button icon="heroicon-m-plus" label="Kapitel anlegen" size="sm" wire:click="kapitelNeu" />
                    </div>

                    <div class="mt-1 flex flex-col gap-0.5">
                        @forelse($kapitelTree as $kt)
                            <div wire:key="ktm-{{ $kt['id'] }}"
                                 @dragover.prevent @drop.prevent="if (dragKapId && dragKapId !== {{ $kt['id'] }}) { $wire.kapitelVerschiebenAuf(dragKapId, {{ $kt['id'] }}); } dragKapId = null"
                                 :class="dragKapId === {{ $kt['id'] }} ? 'opacity-40' : (dragKapId ? 'ring-1 ring-[var(--fa-accent-line)] rounded-[var(--fa-radius-control)]' : '')"
                                 class="group flex items-center gap-0.5" style="padding-left: {{ $kt['depth'] * 12 }}px">
                                {{-- #4: Ziehgriff zum Umsortieren (setData ist Pflicht für Safari) --}}
                                <span class="inline-flex shrink-0 cursor-grab active:cursor-grabbing select-none text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] opacity-0 group-hover:opacity-100 focus-within:opacity-100" draggable="true"
                                      @dragstart="dragKapId = {{ $kt['id'] }}; $event.dataTransfer.setData('text/plain', String({{ $kt['id'] }})); $event.dataTransfer.effectAllowed = 'move'"
                                      @dragend="dragKapId = null" title="Ziehen zum Sortieren" data-kapitel-drag>@svg('heroicon-m-bars-2', 'w-4 h-4')</span>
                                <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})"
                                        class="flex-1 min-w-0 text-left break-words leading-snug text-[length:var(--fa-text-md)] px-2 py-1.5 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedKapitelId === $kt['id'] ? $aktiv : $hover }}"
                                        @if($selectedKapitelId === $kt['id']) aria-current="true" @endif>{{ $kt['title'] }}</button>
                                {{-- Seltene Kapitel-Aktionen in einem Menü je Zeile — spart Breite auf dem Laptop. --}}
                                <div class="relative shrink-0" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                    <button type="button" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen"
                                            class="{{ $kleinSymbol }} opacity-0 group-hover:opacity-100 focus:opacity-100" aria-label="Aktionen für {{ $kt['title'] }}" title="Aktionen für das Kapitel">@svg('heroicon-m-ellipsis-horizontal', 'w-4 h-4')</button>
                                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                                        <button type="button" role="menuitem" wire:click="kapitelNeu({{ $kt['id'] }})" x-on:click="offen = false" class="{{ $menuePunkt }}">@svg('heroicon-o-plus', $menueSymbol) Unterkapitel anlegen</button>
                                        <button type="button" role="menuitem" wire:click="kapitelHoch({{ $kt['id'] }})" x-on:click="offen = false" class="{{ $menuePunkt }}">@svg('heroicon-o-chevron-up', $menueSymbol) Nach oben schieben</button>
                                        <button type="button" role="menuitem" wire:click="kapitelRunter({{ $kt['id'] }})" x-on:click="offen = false" class="{{ $menuePunkt }}">@svg('heroicon-o-chevron-down', $menueSymbol) Nach unten schieben</button>
                                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                        <button type="button" role="menuitem" wire:click="kapitelLoeschen({{ $kt['id'] }})" wire:confirm="Kapitel löschen?" x-on:click="offen = false" class="{{ $menuePunktRot }}">@svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Kapitel löschen</button>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="px-1 py-2 {{ $hinweis }}">Noch keine Kapitel. Titel eingeben und mit Enter anlegen.</p>
                        @endforelse
                    </div>
                </nav>

                {{-- MITTE: Reiter --}}
                <div class="flex-1 min-w-0 px-6">
            <div wire:key="fbcockpit-{{ $fb->id }}" class="space-y-4">
                {{-- Spec 42: reine Ausgabe-Form — Planung/Kreativ/DNA/Trend leben in der Leitstelle.
                     Überblick (2026-08-27): Status + Inhalt + Preis je Kapitel auf EINER Seite, Start-Reiter. --}}
                <x-foodalchemist::editor-tabs marker="fb" wire-key="fb-tabs-{{ $fb->id }}"
                    :tabs="[
                        'board' => 'Überblick',
                        'briefing' => 'Stammdaten',
                        'speisen' => $selectedKapitelId ? 'Speisen' : null,
                        'branding' => 'Branding & Präsentation',
                    ]">

                {{-- headless: Event-Bus im editor-tabs-Scope (kein sichtbares Element).
                     $root = editor-tabs-Wurzel (trägt die data-fb-tab-Buttons + data-fb-anker-Panels). --}}
                <div x-effect="$dispatch('fb-cockpit-tab', { tab })"
                     @fb-goto.window="let d=$event.detail; if(d.tab && $root.querySelector(`[data-fb-tab='${d.tab}']`)) tab=d.tab; $nextTick(()=>{ if(d.anker){ let el=$root.querySelector(`[data-fb-anker='${d.anker}']`); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); } });"></div>

                {{-- ═══ Reiter: ÜBERBLICK — Kapitelbaum mit Stand, Preis und Wareneinsatz je Kapitel ═══
                     Jedes Kapitel eine Zeile, aufklappbar zu Positionen + Abdeckung. „Kapitel öffnen" → Speisen. --}}
                <div x-show="tab === 'board'" x-cloak class="pt-4 flex flex-col gap-3" data-fb-panel="board" data-fb-anker="board">
                    @php
                        $ampelDot = ['gruen' => 'bg-[var(--fa-ok)]', 'gelb' => 'bg-[var(--fa-warn)]', 'rot' => 'bg-[var(--fa-crit)]', 'unbekannt' => 'bg-[var(--fa-line-strong)]'];
                        $ampelText = ['gruen' => 'text-[var(--fa-ok)]', 'gelb' => 'text-[var(--fa-warn)]', 'rot' => 'text-[var(--fa-crit)]', 'unbekannt' => 'text-[var(--fa-ink-3)]'];
                        $befundAmpel = ['erfuellt' => 'ok', 'teilerfuellt' => 'warn', 'verletzt' => 'crit', 'info' => 'info'];
                        $fortDot = ['offen' => 'bg-[var(--fa-line-strong)]', 'in_arbeit' => 'bg-[var(--fa-warn)]', 'fertig' => 'bg-[var(--fa-ok)]'];
                        $fortLabel = ['offen' => 'Offen', 'in_arbeit' => 'In Arbeit', 'fertig' => 'Fertig'];
                        $preisModus = ['auto' => 'Preis aus Inhalt', 'manuell' => 'Preis von Hand'];
                        $pct = fn ($wert) => number_format((float) $wert, 1, ',', '.') . ' %';
                        // Kapitel-Liste = echter Baum. auf{} = aufgeklappte Kapitel-IDs (Default leer → nur Oberkapitel).
                        $byId = collect($kapitelBoard)->keyBy('kapitel_id');
                        $hatKinder = collect($kapitelBoard)->pluck('parent_id')->filter()->mapWithKeys(fn ($pid) => [(int) $pid => true])->all();
                        $ahnen = function ($kid) use ($byId) { $ids = []; $cur = data_get($byId->get($kid), 'parent_id'); $g = 0; while ($cur !== null && $g++ < 20) { $ids[] = (int) $cur; $cur = data_get($byId->get($cur), 'parent_id'); } return $ids; };
                        $alleIds = collect($kapitelBoard)->pluck('kapitel_id')->map(fn ($i) => (int) $i)->all();
                    @endphp
                    <div class="flex flex-col gap-2" x-data="{ auf: {} }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="{{ $hinweis }}">Zeile anklicken klappt Positionen und Abdeckung auf.</p>
                            <div class="flex items-center gap-1">
                                <x-fa::button variant="ghost" size="sm" icon="heroicon-m-chevron-double-down" x-on:click="auf = Object.fromEntries({{ json_encode($alleIds) }}.map(i => [i, true]))">Alle aufklappen</x-fa::button>
                                <x-fa::button variant="ghost" size="sm" icon="heroicon-m-chevron-double-up" x-on:click="auf = {}">Alle zuklappen</x-fa::button>
                            </div>
                        </div>
                        <div class="fa-surface overflow-hidden divide-y divide-[var(--fa-line)]">
                            @forelse($kapitelBoard as $kap)
                                @php
                                    $we = $kap['wareneinsatz'];
                                    $agg = $kap['aggregat'];
                                    $ahnenIds = $ahnen($kap['kapitel_id']);
                                    $istRollup = count($kap['positionen']) === 0 && ($agg['vk_pro_person'] > 0 || $agg['pauschal'] > 0);
                                @endphp
                                <div wire:key="board-{{ $kap['kapitel_id'] }}" x-show="{{ empty($ahnenIds) ? 'true' : '[' . implode(',', $ahnenIds) . '].filter(a => auf[a]).length === ' . count($ahnenIds) }}" x-cloak>
                                    {{-- Kopfzeile: Klick = Ast auf/zu (Objekt-Reassignment → sichere Alpine-Reaktivität);
                                         Kennzahlen + Aktionen rechts stoppen die Propagation. Bricht auf dem Laptop um. --}}
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2 pr-3 cursor-pointer hover:bg-[var(--fa-hover)]"
                                         @click="auf = {...auf, {{ $kap['kapitel_id'] }}: ! auf[{{ $kap['kapitel_id'] }}]}"
                                         style="padding-left: {{ 12 + ($kap['depth'] - 1) * 16 }}px">
                                        <div class="flex flex-1 min-w-[12rem] items-center gap-2">
                                            <span class="inline-flex shrink-0 text-[var(--fa-ink-3)] transition-transform duration-150" :class="auf[{{ $kap['kapitel_id'] }}] && 'rotate-90'">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                                            <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $fortDot[$kap['fortschritt']] ?? $fortDot['offen'] }}" title="Fortschritt: {{ $fortLabel[$kap['fortschritt']] ?? 'Offen' }}"></span>
                                            <span class="min-w-0 break-words text-[length:var(--fa-text-base)] font-medium {{ $kap['is_struktur'] ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-ink)]' }}">{{ $kap['titel'] }}</span>
                                            {{-- Textkapitel: Etikett statt Preis-Modus, Speisen-Kennzeichen ausgeblendet. --}}
                                            @if($kap['is_struktur'])
                                                <x-fa::badge class="shrink-0">Textkapitel</x-fa::badge>
                                            @elseif($kap['pricing_mode'])
                                                <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $preisModus[$kap['pricing_mode']] ?? $kap['pricing_mode'] }}</span>
                                            @endif
                                        </div>
                                        <div class="ml-auto flex flex-wrap items-center justify-end gap-x-3 gap-y-1.5 text-[length:var(--fa-text-md)] tabular-nums" @click.stop>
                                            @unless($kap['is_struktur'])
                                                <span class="flex flex-wrap items-center gap-1">
                                                    @if($kap['hat_ziele'])<x-fa::badge tone="accent" title="Vorgaben für dieses Kapitel gesetzt">Vorgaben</x-fa::badge>@endif
                                                    <x-fa::badge :tone="$kap['positionen_count'] > 0 ? 'info' : 'neutral'">{{ $kap['positionen_count'] }} {{ (int) $kap['positionen_count'] === 1 ? 'Position' : 'Positionen' }}</x-fa::badge>
                                                    @if($kap['bepreist'])
                                                        <x-fa::badge tone="ok">Bepreist</x-fa::badge>
                                                    @elseif($kap['hat_inhalt'])
                                                        <x-fa::badge tone="warn" title="Inhalt angelegt, aber ohne Preis">Preis fehlt</x-fa::badge>
                                                    @else
                                                        <x-fa::badge>Leer</x-fa::badge>
                                                    @endif
                                                </span>
                                            @endunless
                                            @if($agg['ek_per_person'] > 0)<span class="text-[var(--fa-ink-3)]" title="Wareneinsatz pro Gast">EK {{ $euro($agg['ek_per_person']) }}</span>@endif
                                            @if($agg['vk_pro_person'] > 0)
                                                <span class="font-semibold text-[var(--fa-ink)]" title="{{ $istRollup ? 'Summe der Unterkapitel' : 'Verkauf pro Gast' }}">@if($istRollup)<span class="font-normal text-[var(--fa-ink-3)]">Summe </span>@endif{{ $euro($agg['vk_pro_person']) }}<span class="font-normal text-[var(--fa-ink-3)]"> pro Gast</span></span>
                                            @endif
                                            <span class="inline-flex items-center gap-1.5 {{ $ampelText[$we['status']] ?? $ampelText['unbekannt'] }}"
                                                  title="Wareneinsatz {{ $we['ist_pct'] !== null ? $pct($we['ist_pct']) : 'nicht berechenbar' }}, Ziel {{ $pct($we['ziel_pct']) }}">
                                                <span class="inline-block h-2 w-2 rounded-full {{ $ampelDot[$we['status']] ?? $ampelDot['unbekannt'] }}"></span>WE {{ $we['ist_pct'] !== null ? $pct($we['ist_pct']) : '–' }}
                                            </span>
                                            {{-- Fortschritt von Hand setzen (offen|in_arbeit|fertig) → treibt Punkt + Kennzahl „Kapitel fertig". --}}
                                            <x-fa::select size="sm" wire:change="kapitelFortschritt({{ $kap['kapitel_id'] }}, $event.target.value)" aria-label="Fortschritt von {{ $kap['titel'] }}" title="Fortschritt setzen" class="w-auto">
                                                <option value="offen" @selected($kap['fortschritt'] === 'offen')>Offen</option>
                                                <option value="in_arbeit" @selected($kap['fortschritt'] === 'in_arbeit')>In Arbeit</option>
                                                <option value="fertig" @selected($kap['fortschritt'] === 'fertig')>Fertig</option>
                                            </x-fa::select>
                                            <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-right" wire:click="kapitelWaehle({{ $kap['kapitel_id'] }})" title="Kapitel öffnen und Speisen bearbeiten">Kapitel öffnen</x-fa::button>
                                        </div>
                                    </div>
                                    {{-- Aufgeklappt: eigene Positionen + Abdeckung (Kinder sind eigene Zeilen darunter) --}}
                                    <div x-show="auf[{{ $kap['kapitel_id'] }}]" x-cloak class="pb-3 pr-3 flex flex-col gap-1" style="padding-left: {{ 40 + ($kap['depth'] - 1) * 16 }}px">
                                        @forelse($kap['positionen'] as $p)
                                            @php
                                                $vkLink = $p['ref_id'] === null ? null : ($p['ref_typ'] === 'concept'
                                                    ? route('foodalchemist.concepter.index', ['tab' => 'concepts', 'sel' => $p['ref_id']])
                                                    : route('foodalchemist.verkauf.index', ['rezept' => $p['ref_id']]));
                                            @endphp
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1 border-b border-[var(--fa-line)] last:border-b-0 text-[length:var(--fa-text-md)]">
                                                <x-fa::badge :tone="$p['art'] === 'paket' ? 'info' : 'neutral'" class="shrink-0">{{ $p['art'] === 'paket' ? 'Paket' : 'Einzel' }}</x-fa::badge>
                                                <span class="flex-1 min-w-[10rem] break-words text-[var(--fa-ink)]">{{ $p['label'] }}</span>
                                                <div class="ml-auto flex flex-wrap items-center gap-3 tabular-nums">
                                                    @if($p['ek'] > 0)<span class="text-[var(--fa-ink-3)]">EK {{ $euro($p['ek']) }}</span>@endif
                                                    <span class="font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$p['vk'] > 0 ? $p['vk'] : null" missing="VK fehlt" :per="$p['preis_einheit'] === 'gast' ? 'Gast' : 'Position'" /></span>
                                                    @if($p['we_pct'] !== null)<span class="text-[var(--fa-ink-3)]" title="Wareneinsatz dieser Position">{{ $pct($p['we_pct']) }}</span>@endif
                                                    @if($vkLink)<x-fa::icon-button :href="$vkLink" target="_blank" icon="heroicon-m-arrow-top-right-on-square" label="Im Verkauf öffnen" size="sm" />@endif
                                                </div>
                                            </div>
                                        @empty
                                            @if(($agg['vk_pro_person'] ?? 0) > 0 || ($agg['pauschal'] ?? 0) > 0)
                                                <p class="{{ $hinweis }}">Keine eigenen Positionen. Der Preis ist die Summe der Unterkapitel.</p>
                                            @else
                                                <p class="{{ $hinweis }}">Noch keine bepreisten Positionen. Im Reiter Speisen oder in der Leitstelle anlegen.</p>
                                            @endif
                                        @endforelse
                                        @if(! empty($boardCoverage[$kap['kapitel_id']] ?? []))
                                            <div class="flex flex-wrap items-center gap-1.5 pt-2 mt-1 border-t border-[var(--fa-line)]">
                                                <span class="mr-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]">Abdeckung</span>
                                                @foreach($boardCoverage[$kap['kapitel_id']] as $b)
                                                    <x-fa::badge :tone="$befundAmpel[$b['ampel']] ?? 'neutral'">{{ $b['label'] }}: {{ $b['ist'] }}</x-fa::badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Kapitel">
                                    Links ein neues Kapitel anlegen oder das Foodbook in der Leitstelle planen lassen.
                                </x-fa::empty>
                            @endforelse
                        </div>
                    </div>{{-- /auf-Wrapper (Baum-Expand) --}}
                </div>

                {{-- ═══ Reiter: STAMMDATEN (Bezeichnung · Status · Segment · Kunde) ═══ --}}
                <div x-show="tab === 'briefing'" x-cloak class="pt-4 flex flex-col gap-4" data-fb-panel="briefing">
                    <x-fa::section title="Foodbook" icon="heroicon-o-book-open" wire:key="fbhdr-{{ $fb->id }}">
                        {{-- „Personen" entfernt (Dominique 2026-08-27): das Foodbook ist ein person-unabhängiges
                             Portfolio — Pax liegt im Angebot. DB-Spalte/Default bleiben, nur das UI-Feld ist weg. --}}
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-fa::field label="Bezeichnung" for="fb-label" class="sm:col-span-2">
                                <x-fa::input id="fb-label" wire:model="form.label" />
                            </x-fa::field>
                            <x-fa::field label="Jahr" for="fb-jahr">
                                <x-fa::input id="fb-jahr" type="number" wire:model="form.jahr" numeric />
                            </x-fa::field>
                        </div>

                        {{-- Spec 33 P5: Status, Gültigkeitsfenster und beide Zuordnungsachsen aus einem geteilten Bauteil. --}}
                        <div class="pt-3 border-t border-[var(--fa-line)]">
                            <x-foodalchemist::ausgabe-status
                                status-model="form.status" von-model="form.gueltig_von" bis-model="form.gueltig_bis"
                                outlet-model="form.outlet_id"
                                :betriebe="$betriebe" :zustand="$fb->laufZustand()" :grund="$fb->laufGrund()"
                                :konflikt="$portfolioKonflikt" toggle="aktivUmschalten" />
                        </div>

                        {{-- Phase 5: Segment (aus Küchen-Typ) = Achse für Portionen/Preis/Komplexität/Ton. --}}
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 pt-3 border-t border-[var(--fa-line)]" data-segment>
                            <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Segment</span>
                            @if($segment ?? null)
                                <x-fa::badge tone="accent">{{ $segment['label'] }}</x-fa::badge>
                                <span class="{{ $hinweis }}">Niveau {{ \Platform\FoodAlchemist\Services\TeamSettingsService::NIVEAU_LABEL[$segment['niveau']] ?? $segment['niveau'] }} · {{ \Platform\FoodAlchemist\Services\TeamSettingsService::CONVENIENCE_LABEL[$segment['convenience']] ?? $segment['convenience'] }}</span>
                            @else
                                <x-fa::signal tone="warn">Nicht gesetzt. In den Einstellungen ein Küchenprofil wählen, es steuert Niveau und Convenience der KI.</x-fa::signal>
                            @endif
                        </div>

                        <x-foodalchemist::crm-kunde-picker
                            :ausgabe="$fb" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />

                        {{-- Spec-42-Vollzug S3b: Leitplanken, Briefing/Einleitung + KI-Text leben in der Leitstelle
                             (Planung\FoodbookKontextRail). Hier nur Stammdaten/Status/Kunde. --}}
                    </x-fa::section>
                </div>{{-- /Stammdaten --}}


                {{-- ═══ Reiter: BRANDING und PRÄSENTATION (pro Foodbook, FoodbookService-Branding-API) ═══
                     Farben und Logo sind Kundendaten: Felder bleiben, nur die Darstellung ist neu. --}}
                <div x-show="tab === 'branding'" x-cloak class="pt-4 flex flex-col gap-4" data-fb-panel="branding"
                     x-data="{ brand: @entangle('brandingForm.brand_color'), band: @entangle('brandingForm.band_color'), footer: @entangle('brandingForm.footer_text') }">
                    <x-fa::section title="Branding im Dokument" icon="heroicon-o-swatch" description="Farben, Logo und Titelbild für das gedruckte Foodbook und das PDF.">
                        @if($brandingFehler)
                            <x-fa::notice tone="crit" title="Nicht gespeichert" data-branding-fehler>{{ $brandingFehler }}</x-fa::notice>
                        @endif
                        @if($brandingGespeichert)
                            <x-fa::notice tone="ok">Gespeichert. Das Dokument nutzt ab jetzt dieses Branding.</x-fa::notice>
                        @endif

                        {{-- Live-Vorschau: Kopf-Band (Bandfarbe + Logo) · Fuß-Linie (Marken-Farbe). Kundenfarben nur zur Laufzeit. --}}
                        <div class="flex flex-col gap-1.5">
                            <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Vorschau</span>
                            <div class="overflow-hidden rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                                <div class="flex items-center justify-between gap-2 px-3 h-10 text-[var(--fa-on-accent)] text-[length:var(--fa-text-sm)] font-semibold" x-bind:style="`background:${band || brand}`">
                                    <span class="truncate">{{ $fb->label }}</span>
                                    @if($fb->logo_path)<img src="{{ $medien($fb->logo_context_file_id, $fb->logo_path) }}" alt="Logo" class="max-h-6 max-w-[90px] object-contain shrink-0" />@endif
                                </div>
                                <div class="px-3 py-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" x-bind:style="`border-top:3px solid ${brand}`">
                                    <span x-text="footer || 'Erstellt mit Food Alchemist'"></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <x-fa::field label="Markenfarbe" for="fb-brand" hint="Rahmen, Linien und Etiketten im PDF.">
                                <div class="flex items-center gap-2">
                                    <input type="color" x-model="brand" class="{{ $farbFeld }}" aria-label="Markenfarbe wählen" data-brand-color />
                                    <x-fa::input id="fb-brand" x-model="brand" class="w-32 font-mono" placeholder="Farbcode" />
                                </div>
                            </x-fa::field>
                            <x-fa::field label="Bandfarbe" for="fb-band" optional hint="Kopf- und Fußband. Leer heißt: wie die Markenfarbe.">
                                <div class="flex items-center gap-2">
                                    <input type="color" x-model="band" class="{{ $farbFeld }}" aria-label="Bandfarbe wählen" />
                                    <x-fa::input id="fb-band" x-model="band" class="w-32 font-mono" placeholder="Wie Marke" />
                                    <x-fa::icon-button icon="heroicon-m-x-mark" label="Bandfarbe leeren, dann gilt die Markenfarbe" size="sm" x-on:click="band = ''" />
                                </div>
                            </x-fa::field>
                        </div>

                        <x-fa::field label="Fußzeile" for="fb-footer">
                            <x-fa::input id="fb-footer" x-model="footer" placeholder="Erstellt mit Food Alchemist" />
                        </x-fa::field>

                        {{-- Logo + Titelbild --}}
                        <div class="grid gap-4 md:grid-cols-2 pt-3 border-t border-[var(--fa-line)]">
                            <x-fa::field label="Logo">
                                @if($fb->logo_path)
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $medien($fb->logo_context_file_id, $fb->logo_path) }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-1" />
                                        <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingLogoEntfernen" data-logo-entfernen>Logo entfernen</x-fa::button>
                                    </div>
                                @endif
                                <input type="file" wire:model="logoUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Logo hochladen" data-logo-upload />
                                <span wire:loading wire:target="logoUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                                @error('logoUpload')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</span>@enderror
                            </x-fa::field>
                            <x-fa::field label="Titelbild">
                                @if($fb->cover_image_path)
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $medien($fb->cover_context_file_id, $fb->cover_image_path) }}" alt="Titelbild" class="h-10 max-w-[120px] object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" />
                                        <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingCoverEntfernen" data-cover-entfernen>Titelbild entfernen</x-fa::button>
                                    </div>
                                @endif
                                <input type="file" wire:model="coverUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Titelbild hochladen" data-cover-upload />
                                <span wire:loading wire:target="coverUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                                @error('coverUpload')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</span>@enderror
                            </x-fa::field>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                            <x-fa::button variant="ghost" :href="route('foodalchemist.foodbooks.dokument', $fb->id) . '?pdf=1'" target="_blank" icon="heroicon-m-arrow-top-right-on-square" title="Branding im PDF gegenprüfen">PDF prüfen</x-fa::button>
                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="brandingSpeichern" data-branding-speichern>Branding speichern</x-fa::button>
                        </div>
                    </x-fa::section>

                    {{-- ═══ Spec 43: Präsentation — digitales Kundenbuch (Kundenlink + eingefrorener Stand + Freigabe) ═══ --}}
                    <x-fa::section title="Präsentation für den Kunden" icon="heroicon-o-presentation-chart-bar" description="Ein Link zum digitalen Kundenbuch. Beim Veröffentlichen wird der Stand eingefroren." data-fb-praesentation>
                        <x-slot:actions>
                            <x-fa::button variant="ghost" size="sm" :href="route('foodalchemist.einstellungen', ['sektion' => 'praesentations-designs'])" target="_blank" icon-right="heroicon-m-arrow-top-right-on-square">Designs gestalten</x-fa::button>
                        </x-slot:actions>

                        @if($presentationHinweis)<x-fa::notice tone="ok" data-fb-praes-hinweis>{{ $presentationHinweis }}</x-fa::notice>@endif
                        @if($presentationFehler)<x-fa::notice tone="crit" data-fb-praes-fehler>{{ $presentationFehler }}</x-fa::notice>@endif

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field label="Design" for="fb-praes-design">
                                <x-fa::select id="fb-praes-design" wire:model="presentationDesign" data-fb-praes-design>
                                    @foreach($presentationDesignOptionen as $opt)
                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Gültig bis" for="fb-praes-gueltig" required>
                                <x-fa::input id="fb-praes-gueltig" type="date" wire:model="presentationGueltigBis" data-fb-praes-gueltig />
                            </x-fa::field>
                        </div>

                        <fieldset class="flex flex-wrap gap-x-5 gap-y-2">
                            <legend class="sr-only">Anzeige</legend>
                            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="presentationPreisAnzeige" class="{{ $haken }}"> Preise pro Person zeigen</label>
                            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="presentationDeklaration" class="{{ $haken }}"> Allergen-Legende zeigen</label>
                            {{-- Ebene 2 · Republish-Preis-Schutz: aus = eingefrorene Preise behalten (neue Speisen kommen live rein), an = aktuelle VK ziehen. --}}
                            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"
                                   title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen, neue Speisen kommen mit aktuellem Preis dazu. An: alle aktuellen Verkaufspreise übernehmen. Die erste Veröffentlichung ist immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren" class="{{ $haken }}"> Preise beim Neu-Veröffentlichen aktualisieren</label>
                        </fieldset>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field label="Text für den Kontakt-Knopf" for="fb-praes-cta" optional>
                                <x-fa::input id="fb-praes-cta" wire:model="presentationCtaText" placeholder="z. B. Jetzt anfragen" />
                            </x-fa::field>
                            <x-fa::field label="Ziel des Kontakt-Knopfs" for="fb-praes-cta-link" optional>
                                <x-fa::input id="fb-praes-cta-link" type="url" wire:model="presentationCtaLink" placeholder="https://…" />
                            </x-fa::field>
                        </div>

                        <x-fa::field label="Eigener Link-Name" for="fb-praes-slug" optional>
                            <x-fa::input id="fb-praes-slug" wire:model.live.debounce.400ms="presentationSlug" placeholder="z. B. broich-empfang-2027" data-fb-praes-slug />
                            <p class="{{ $hinweis }}">
                                Kundenlink:
                                <span class="font-mono break-all text-[var(--fa-ink-2)]">{{ url('/p/foodbook') }}/{{ trim((string) $presentationSlug) !== '' ? \Illuminate\Support\Str::slug($presentationSlug) : '(automatischer Code)' }}</span>.
                                Gilt nach dem Veröffentlichen. Leer lassen ergibt einen zufälligen Code.
                            </p>
                        </x-fa::field>

                        @if($presentationInfo['design_veraltet'] ?? false)
                            {{-- Bug-Runde 2026-09-17 #2: Der Link rendert nur den eingefrorenen Snapshot. --}}
                            <x-fa::notice tone="warn" title="Design nach der Veröffentlichung geändert" data-fa-design-veraltet>
                                Der Kundenlink zeigt weiter den Stand vom {{ $presentationInfo['published_at'] ?? 'unbekannten Datum' }}. Zum Übernehmen neu veröffentlichen.
                            </x-fa::notice>
                        @endif

                        <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                            @if($presentationInfo['enabled'] ?? false)
                                <x-fa::button variant="danger" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link ist dann nicht mehr erreichbar." data-fb-praes-withdraw>Veröffentlichung zurückziehen</x-fa::button>
                            @endif
                            <x-fa::button variant="ghost" :href="route('foodalchemist.foodbooks.praesentation', ['id' => $fb->id, 'design' => $presentationDesign])" target="_blank" icon="heroicon-m-eye">Vorschau öffnen</x-fa::button>
                            <x-fa::button variant="primary" icon="heroicon-m-globe-alt" wire:click="veroeffentlichen"
                                wire:confirm="Diesen Stand als Kundenbuch veröffentlichen? Der Stand wird eingefroren."
                                data-fb-praes-publish :disabled="! $presentationGueltigBis">
                                {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                            </x-fa::button>
                        </div>
                        @unless($presentationGueltigBis)
                            <x-fa::signal tone="warn" class="self-end">Zum Veröffentlichen ein Datum bei „Gültig bis“ setzen.</x-fa::signal>
                        @endunless

                        @if($presentationLink)
                            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3" x-data>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="flex-1 min-w-[12rem] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-2.5 py-1.5 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] break-all select-all" data-fb-praes-link>{{ $presentationLink }}</div>
                                    <x-fa::button size="sm" icon="heroicon-m-link" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.textContent='Kopiert'">Link kopieren</x-fa::button>
                                </div>
                                <p class="{{ $hinweis }} flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span>Freigegeben am {{ $presentationInfo['published_at'] ?? '–' }}, gültig bis {{ $presentationInfo['expires_at'] ?? '–' }}</span>
                                    @if($presentationInfo['live'] ?? false)<x-fa::badge tone="ok">Erreichbar</x-fa::badge>@else<x-fa::badge tone="warn">Nicht erreichbar oder abgelaufen</x-fa::badge>@endif
                                </p>
                            </div>
                        @endif

                        {{-- ── Slice F: Betriebs-Links — pro Betrieb ein eigener Link ── --}}
                        <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]">
                            <div>
                                <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Eigener Link je Betrieb</h4>
                                <p class="{{ $hinweis }}">Zusätzlicher Kundenlink pro Betrieb, eingefroren mit den Preisen und der Vorlage dieses Betriebs, mit eigener Freigabe. Der Standard-Link oben bleibt bestehen.</p>
                            </div>

                            @forelse($betriebsLinks as $bl)
                                <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3" x-data>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $bl['outlet_name'] }}</span>
                                        @if($bl['enabled'])<x-fa::badge tone="ok">Freigegeben</x-fa::badge>@else<x-fa::badge>Zurückgezogen</x-fa::badge>@endif
                                        <span class="ml-auto {{ $hinweis }}">Vorlage: {{ $bl['design'] }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="flex-1 min-w-[12rem] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-2.5 py-1.5 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] break-all select-all">{{ $bl['url'] }}</div>
                                        <x-fa::button size="sm" icon="heroicon-m-link" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.textContent='Kopiert'">Link kopieren</x-fa::button>
                                        @if($bl['enabled'])
                                            <x-fa::button size="sm" variant="danger" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er ist dann nicht mehr erreichbar.">Zurückziehen</x-fa::button>
                                        @else
                                            <x-fa::button size="sm" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})">Wieder freigeben</x-fa::button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="{{ $hinweis }}">Noch kein Betriebs-Link angelegt.</p>
                            @endforelse

                            @if(count($betriebsOptionen) > 0)
                                {{-- eigener „Betrieb hinzufügen"-Kasten — klar als Hinzufügen abgesetzt --}}
                                <div class="flex flex-col gap-3 rounded-[var(--fa-radius-surface)] border border-dashed border-[var(--fa-line-strong)] p-3">
                                    <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Link für einen weiteren Betrieb</p>
                                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                                        <x-fa::field label="Betrieb" for="fb-outlet">
                                            <x-fa::select id="fb-outlet" wire:model="outletPublishId">
                                                <option value="">Betrieb wählen</option>
                                                @foreach($betriebsOptionen as $o)
                                                    <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Gültig bis" for="fb-outlet-gueltig" optional>
                                            <x-fa::input id="fb-outlet-gueltig" type="date" wire:model="outletPublishGueltigBis" />
                                        </x-fa::field>
                                        <x-fa::field label="Vorlage" for="fb-outlet-design" optional>
                                            <x-fa::select id="fb-outlet-design" wire:model="outletPublishDesign">
                                                <option value="">Vorlage des Betriebs oder wie Dokument</option>
                                                @foreach($presentationDesignOptionen as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Link-Name" for="fb-outlet-slug" optional>
                                            <x-fa::input id="fb-outlet-slug" wire:model="outletPublishSlug" placeholder="z. B. broich-nord-2027" />
                                        </x-fa::field>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="{{ $hinweis }}">Beliebig viele Betriebe möglich. Ohne eigenes Datum gilt „Gültig bis“ des Standard-Links.</p>
                                        <x-fa::button icon="heroicon-m-plus" wire:click="betriebVeroeffentlichen">Betriebs-Link anlegen</x-fa::button>
                                    </div>
                                </div>
                            @else
                                <x-fa::signal tone="info">Noch keine Betriebe angelegt. Betriebe stehen in den Einstellungen unter Betriebe.</x-fa::signal>
                            @endif
                        </div>
                    </x-fa::section>
                </div>{{-- /Branding --}}

                {{-- ═══ Reiter: SPEISEN — Kapitel- und Eintrags-Editor (Spec 29 / S6) ═══
                     Erscheint nur, wenn ein Kapitel gewählt ist (Label null ⇒ Reiter entfällt). --}}
                <div x-show="tab === 'speisen'" x-cloak class="pt-4 flex flex-col gap-3" data-fb-panel="speisen">
            @if($kapitel)
                {{-- Picker-Umbau: links [Kapitel-Kopf + Inhalt], rechts der Katalog (Dominique 2026-08-23).
                     Unter xl (kleiner Laptop) rutscht der Katalog unter den Inhalt, statt den Inhalt zu quetschen. --}}
                <div class="flex flex-col xl:flex-row gap-4 xl:items-start" data-fb-speisen-2col>
                <div class="flex-1 min-w-0 flex flex-col gap-4" data-fb-speisen-links>
                {{-- Kapitel-Kopf --}}
                <x-fa::section title="Kapitel" icon="heroicon-o-bookmark" wire:key="kaphdr-{{ $kapitel->id }}">
                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        <x-fa::field label="Interner Titel" for="kap-title">
                            <x-fa::input id="kap-title" wire:model.blur="kapitelForm.title" wire:change="kapitelSpeichern" />
                        </x-fa::field>
                        <x-fa::field label="Titel für Gäste" for="kap-consumer" class="2xl:col-span-2" hint="Steht im PDF. Leer lassen, dann gilt der interne Titel.">
                            <x-fa::input id="kap-consumer" wire:model.blur="kapitelForm.consumer_title" wire:change="kapitelSpeichern" />
                        </x-fa::field>
                        <x-fa::field label="Preis" for="kap-price-mode">
                            <x-fa::select id="kap-price-mode" wire:model.live="kapitelForm.price_mode" wire:change="kapitelSpeichern">
                                <option value="auto">Aus dem Inhalt berechnen</option>
                                <option value="manuell">Von Hand festlegen</option>
                            </x-fa::select>
                        </x-fa::field>
                    </div>
                    {{-- Textkapitel/Sektion (Dominique 2026-08-27): reine Überschrift/Text ohne eigenes Food. --}}
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="kapitelForm.is_struktur" wire:change="kapitelSpeichern" class="{{ $haken }} mt-0.5" />
                        <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">Textkapitel ohne eigene Speisen
                            <span class="block {{ $hinweis }}">Für Einleitung, Überschrift oder Format-Abschnitt. Kennzahlen kommen dann nur aus den Unterkapiteln.</span>
                        </span>
                    </label>

                    {{-- Spec 43 (Bild-Epic): Kapitel-Bild — überschreibt das Concept-Titelbild im Präsentations-Band --}}
                    <div class="grid gap-4 md:grid-cols-2 pt-3 border-t border-[var(--fa-line)]" data-fb-kapitel-image>
                        <x-fa::field label="Kapitelbild in der Präsentation" hint="Ohne eigenes Bild nutzt das Kapitel das Titelbild des Konzepts.">
                            <div class="flex flex-wrap items-center gap-3">
                                @if($kapitelImageUrl)
                                    <img src="{{ $kapitelImageUrl }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="kapitelImageEntfernen" data-fb-kapitel-image-remove>Bild entfernen</x-fa::button>
                                @endif
                                <input type="file" wire:model="kapitelImageUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Kapitelbild hochladen" data-fb-kapitel-image-upload>
                                <span wire:loading wire:target="kapitelImageUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                            </div>
                            @if($kapitelImageFehler)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $kapitelImageFehler }}</span>@endif
                            @error('kapitelImageUpload')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</span>@enderror
                        </x-fa::field>

                        {{-- Kapitel-Galerie: mehrere Bilder direkt am Kapitel (Vorrang vor den Concept-Bildern) --}}
                        <x-fa::field label="Weitere Bilder" optional hint="Mehrere Bilder für das Kapitelband. Ersetzen die Bilder der Konzepte." data-fb-kapitel-gallery>
                            <div class="flex flex-wrap items-center gap-3">
                                @foreach($kapitelGallery as $gi)
                                    <div class="relative" wire:key="kgal-{{ $gi['id'] }}">
                                        <img src="{{ $gi['url'] }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                        <button type="button" wire:click="kapitelGalerieBildEntfernen({{ $gi['id'] }})"
                                            class="absolute -top-2 -right-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-[var(--fa-crit)] text-[var(--fa-on-accent)] shadow"
                                            aria-label="Bild entfernen" title="Bild entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </div>
                                @endforeach
                                <input type="file" wire:model="kapitelGalleryUpload" accept="image/*" multiple class="{{ $dateiFeld }}" aria-label="Weitere Bilder hochladen" data-fb-kapitel-gallery-upload>
                                <span wire:loading wire:target="kapitelGalleryUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                            </div>
                            @error('kapitelGalleryUpload.*')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</span>@enderror
                        </x-fa::field>
                    </div>

                    {{-- Spec 03 · L2b: der Kapitel-Kundentext (foodbook_chapters.description), auch im Dokument. --}}
                    <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="kap-description" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Einleitung für Gäste</label>
                            <x-foodalchemist::ki-action action="kiKapitelText" variant="ai" icon="heroicon-o-sparkles" label="Text vorschlagen"
                                    title="Die KI schreibt eine Einleitung aus dem Inhalt des Kapitels, der Einleitung des Foodbooks und der Markenstimme"
                                    busy="Schreibt …" data-fb-ki-kapiteltext />
                        </div>
                        <x-fa::textarea id="kap-description" wire:model.blur="kapitelForm.description" wire:change="kapitelSpeichern" rows="2"
                                  class="resize-y min-h-[3.5rem]"
                                  placeholder="Kurzer Text, der Gäste ins Kapitel führt. „Text vorschlagen“ liefert einen Entwurf."></x-fa::textarea>
                        @include('foodalchemist::livewire.foodbooks.partials.ki-text-vorschau', [
                            'ziel' => 'kapitel',
                            'vorhanden' => trim((string) ($kapitelForm['description'] ?? '')) !== '',
                        ])
                    </div>

                    {{-- #2: Schreibstil PRO KAPITEL — Standard (aus den Concepten) oder eigener. Der KI-Knopf betextet alle
                         Konzepte dieses Kapitels im gewählten Stil neu, foodbook-LOKAL (Snapshot); das Concept bleibt unangetastet. --}}
                    <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]" data-fb-kapitel-stil>
                        <x-fa::field label="Schreibstil für dieses Kapitel" for="kap-stil" class="flex-1 min-w-[14rem] max-w-sm">
                            <x-fa::select id="kap-stil" wire:model.live="kapitelForm.writing_style_id" wire:change="kapitelSpeichern" data-fb-kapitel-schreibstil>
                                <option value="">Wie in den Concepts</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-foodalchemist::ki-action action="kapitelWordingGenerieren" variant="ai" icon="heroicon-o-sparkles" label="Speisen neu betexten"
                                :disabled="($kapitelForm['writing_style_id'] ?? null) === null || ($kapitelForm['writing_style_id'] ?? '') === ''"
                                title="Schreibt die Gästetexte aller Konzepte dieses Kapitels im gewählten Schreibstil neu. Gilt nur in diesem Foodbook, das Concept bleibt unverändert. Erst einen Schreibstil wählen."
                                busy="Betextet …" class="shrink-0 mb-0.5" data-fb-kapitel-wording />
                    </div>
                    @error('kapitelWording')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert" data-fb-kapitel-fehler>{{ $message }}</p>@enderror
                </x-fa::section>

                {{-- Inhalt (linke Spalte, unter dem Kapitel-Kopf) --}}
                <x-fa::section title="Inhalt" icon="heroicon-o-queue-list" :meta="$kapitel->blocks->count() === 1 ? '1 Eintrag' : $kapitel->blocks->count() . ' Einträge'" data-fb-inhalt>
                    {{-- Werkzeugleiste: bricht auf schmalen Bildschirmen um (nicht im Kopf, der umbricht nicht). --}}
                    <div class="flex flex-wrap items-center gap-2">
                        @if(count($markiert) >= 2)
                            <x-fa::button size="sm" icon="heroicon-m-squares-2x2" wire:click="wahlGruppeBilden" title="Die markierten Concepts werden zur Auswahl für den Gast">Wahl-Gruppe bilden ({{ count($markiert) }})</x-fa::button>
                        @endif
                        {{-- Concept/Gericht einfügen → im Katalog rechts. --}}
                        <x-fa::button size="sm" icon="heroicon-m-bars-3-bottom-left" wire:click="blockBasis('text')">Text einfügen</x-fa::button>
                        <x-fa::button size="sm" icon="heroicon-m-minus" wire:click="blockBasis('spacer')">Leerzeile einfügen</x-fa::button>
                        <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                            <x-fa::button size="sm" icon="heroicon-m-plus" icon-right="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Überschrift einfügen</x-fa::button>
                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 max-h-80 overflow-y-auto fa-surface shadow-lg py-1">
                                <button type="button" role="menuitem" wire:click="blockBasis('header_frei')" x-on:click="offen = false" class="{{ $menuePunkt }}">Freie Überschrift</button>
                                <button type="button" role="menuitem" wire:click="blockBasis('header_frei_preis')" x-on:click="offen = false" class="{{ $menuePunkt }}">Überschrift mit Preis</button>
                                @foreach($headerPresets as $gruppe => $items)
                                    <div class="{{ $menueGruppe }}">{{ $gruppe }}</div>
                                    @foreach($items as $p)
                                        <button type="button" role="menuitem" x-on:click="offen = false"
                                                wire:click="presetHinzu(@js($p['type']), @js($p['slug']), @js($p['label']), @js($p['price_basis'] ?? null), {{ ($p['visible'] ?? true) ? 'true' : 'false' }})"
                                                class="{{ $menuePunkt }} pl-5 break-words">{{ $p['label'] }}</button>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- x-data hält den Drag-Zustand; Ziehgriff = umsortieren, Hoch/Runter als Alternative --}}
                    <div class="flex flex-col gap-1.5" x-data="{ dragBlockId: null }">
                        @php $hoeheText = ['klein' => 'klein', 'mittel' => 'mittel', 'gross' => 'groß']; @endphp
                        @forelse($kapitel->blocks as $block)
                            <div wire:key="block-{{ $block->id }}"
                                 @dragover.prevent @drop.prevent="if (dragBlockId && dragBlockId !== {{ $block->id }}) { $wire.blockVerschiebenAuf(dragBlockId, {{ $block->id }}); } dragBlockId = null"
                                 :class="dragBlockId === {{ $block->id }} ? 'opacity-40' : (dragBlockId ? 'ring-1 ring-[var(--fa-accent-line)]' : '')"
                                 class="rounded-[var(--fa-radius-surface)] border {{ $block->variant_group_id ? 'border-[var(--fa-warn)]' : 'border-[var(--fa-line)]' }} bg-[var(--fa-surface)] px-2 py-1.5 {{ $block->visible ? '' : 'opacity-60' }}"
                                 style="margin-left: {{ $block->level * 20 }}px">
                                <div class="flex items-start gap-1.5">
                                    <span class="flex items-center shrink-0 pt-0.5">
                                        {{-- R4: setData ist Pflicht, sonst startet Safari den Drag nicht --}}
                                        <span class="inline-flex cursor-grab active:cursor-grabbing select-none text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" draggable="true"
                                              @dragstart="dragBlockId = {{ $block->id }}; $event.dataTransfer.setData('text/plain', String({{ $block->id }})); $event.dataTransfer.effectAllowed = 'move'"
                                              @dragend="dragBlockId = null" title="Ziehen zum Sortieren" data-block-drag>@svg('heroicon-m-bars-2', 'w-4 h-4')</span>
                                        <span class="inline-flex flex-col">
                                            <button type="button" wire:click="blockHoch({{ $block->id }})" class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
                                            <button type="button" wire:click="blockRunter({{ $block->id }})" class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
                                        </span>
                                    </span>
                                    @if($block->type === 'concept_ref')
                                        <input type="checkbox" wire:click="markiere({{ $block->id }})" @checked(in_array($block->id, $markiert)) title="Für eine Wahl-Gruppe markieren" aria-label="Für eine Wahl-Gruppe markieren" class="{{ $haken }} mt-1" />
                                    @else
                                        <span class="w-4 shrink-0"></span>
                                    @endif
                                    <div class="flex-1 min-w-0 pt-0.5 text-[length:var(--fa-text-md)] leading-snug break-words text-[var(--fa-ink)]">
                                        @switch($block->type)
                                            @case('concept_ref')
                                                <x-fa::badge tone="info" class="mr-1 align-middle">Concept</x-fa::badge><span class="font-medium">{{ $block->concept?->name ?? 'Concept fehlt' }}</span>
                                                {{-- Einzelpreis-Concept zeigt keinen Summenpreis (die Preise stehen je Gericht in der Vorschau darunter). --}}
                                                @if($block->concept?->price_per_person_cache !== null && ! $block->concept?->istEinzelpreis())<span class="text-[var(--fa-ink-2)] tabular-nums"> · {{ $euro($block->concept->price_per_person_cache) }} pro Person</span>@elseif($block->concept?->istEinzelpreis())<span class="text-[var(--fa-ink-3)]"> · Preise je Gericht</span>@endif
                                                @if(trim((string) $block->wording) !== '')<span class="italic text-[var(--fa-ink-2)]"> · „{{ $block->wording }}“</span>@endif
                                                @break
                                            @case('recipe_ref')
                                                <x-fa::badge class="mr-1 align-middle">Gericht</x-fa::badge><span class="font-medium">{{ $block->dish?->name ?? 'Gericht fehlt' }}</span>
                                                <span class="text-[var(--fa-ink-2)] tabular-nums"> · </span><x-fa::money :value="$block->dish?->sales_net" />@if($block->dish?->sales_net !== null)<span class="text-[var(--fa-ink-3)]">{{ $block->price_basis === 'pauschal' ? ' pauschal' : ' pro Position' }}</span>@endif
                                                @if(trim((string) $block->wording) !== '')<span class="italic text-[var(--fa-ink-2)]"> · „{{ $block->wording }}“</span>@endif
                                                @break
                                            @case('header_neutral') @case('header_frei')
                                                @if($block->label)<span class="font-semibold">{{ $block->label }}</span>@else<span class="italic text-[var(--fa-ink-3)]">Überschrift ohne Text</span>@endif
                                                @break
                                            @case('header_frei_preis')
                                                @if($block->label)<span class="font-semibold">{{ $block->label }}</span>@else<span class="italic text-[var(--fa-ink-3)]">Überschrift ohne Text</span>@endif
                                                <span class="text-[var(--fa-ink-2)] tabular-nums"> · {{ $block->price_basis === 'staffel' ? 'Staffelpreis' : $euro($block->price_value ?? 0) . ($block->price_basis === 'pauschal' ? ' pauschal' : ' pro Person') }}</span>
                                                @break
                                            @case('spacer') <span class="italic text-[var(--fa-ink-3)]">Leerzeile, {{ $hoeheText[$block->height ?? 'mittel'] ?? ($block->height ?? 'mittel') }}</span> @break
                                            @case('image') <span class="inline-flex items-center gap-1 text-[var(--fa-ink-2)]">@svg('heroicon-o-photo', 'w-4 h-4') Bild</span> @break
                                            @default @if(trim((string) $block->customer_text) !== '')<span class="italic">{{ \Illuminate\Support\Str::limit($block->customer_text, 80) }}</span>@else<span class="italic text-[var(--fa-ink-3)]">Text ohne Inhalt</span>@endif
                                        @endswitch
                                    </div>
                                    <div class="flex flex-wrap items-center justify-end gap-0.5 shrink-0">
                                        @if($block->variant_group_id)
                                            <button type="button" wire:click="wahlGruppeAufheben({{ $block->id }})" title="Aus der Wahl-Gruppe nehmen">
                                                <x-fa::badge tone="warn" icon="heroicon-m-x-mark">Wahl {{ $block->variant_group_id }}</x-fa::badge>
                                            </button>
                                        @endif
                                        {{-- #6: Deep-Jump ins Concept/Paket — öffnet es direkt im Concepter-Editor (neuer Tab). --}}
                                        @if($block->type === 'concept_ref' && $block->concept_id)
                                            <a href="{{ route('foodalchemist.concepter.index', ['edit' => $block->concept_id]) }}" target="_blank" class="{{ $kleinSymbol }}" title="Im Concepter öffnen" aria-label="Im Concepter öffnen" data-fb-block-concepter>@svg('heroicon-m-arrow-top-right-on-square', 'w-4 h-4')</a>
                                        @endif
                                        <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="{{ $block->visible ? $kleinSymbol : 'inline-flex items-center gap-1 h-7 px-2 shrink-0 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)] hover:bg-[var(--fa-hover)]' }}"
                                                title="{{ $block->visible ? 'Für Gäste sichtbar. Klicken macht den Eintrag intern.' : 'Nur intern. Klicken zeigt den Eintrag wieder für Gäste.' }}">@if($block->visible)@svg('heroicon-m-eye', 'w-4 h-4')@else @svg('heroicon-m-eye-slash', 'w-4 h-4') Intern @endif</button>
                                        @if($block->type !== 'spacer')
                                            <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="{{ $kleinSymbol }}" title="Bearbeiten und Notiz" aria-label="Eintrag bearbeiten">@svg('heroicon-m-pencil', 'w-4 h-4')</button>
                                        @endif
                                        <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                            <button type="button" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" class="{{ $kleinSymbol }}" aria-label="Weitere Aktionen" title="Weitere Aktionen">@svg('heroicon-m-ellipsis-horizontal', 'w-4 h-4')</button>
                                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-52 fa-surface shadow-lg py-1">
                                                <button type="button" role="menuitem" wire:click="blockEbene({{ $block->id }}, -1)" x-on:click="offen = false" class="{{ $menuePunkt }}">@svg('heroicon-o-arrow-left', $menueSymbol) Ausrücken</button>
                                                <button type="button" role="menuitem" wire:click="blockEbene({{ $block->id }}, 1)" x-on:click="offen = false" class="{{ $menuePunkt }}">@svg('heroicon-o-arrow-right', $menueSymbol) Einrücken</button>
                                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                <button type="button" role="menuitem" wire:click="blockRaus({{ $block->id }})" x-on:click="offen = false" class="{{ $menuePunktRot }}">@svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Aus dem Kapitel entfernen</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- #7/C2: Live-Menü-Vorschau (aufgelöste gerichtZeilen); eingebettete Pakete aufgelöst (#1).
                                     C1: Gericht-Zeilen mit slot_id sind inline editierbar (foodbook-lokaler Gästetext). --}}
                                @if($block->type === 'concept_ref' && ! empty($blockMenus[$block->id]))
                                    <div class="mt-2 ml-6 flex flex-col gap-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2" data-fb-block-vorschau>
                                        @foreach($blockMenus[$block->id] as $g)
                                            @php
                                                // editierbar = jede Gericht-Zeile mit slot_id (direkt ODER eingebettetes Paket).
                                                $istEditierbar = isset($g['slot_id']);
                                                $slotKey = $istEditierbar ? $block->id . ':' . $g['slot_id'] : null;
                                            @endphp
                                            @if(($g['type'] ?? '') === 'header')
                                                <p class="mt-1.5 first:mt-0 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</p>
                                            @elseif(($g['type'] ?? '') === 'paket')
                                                <div class="mt-1 flex items-center gap-1.5" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">
                                                    <x-fa::badge tone="info" class="shrink-0">Paket</x-fa::badge>
                                                    <span class="min-w-0 break-words text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $g['text'] }}</span>
                                                    @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $euro($g['preis']) }} pro Person</span>@endif
                                                </div>
                                            @elseif($slotKey !== null && $editSlotKey === $slotKey)
                                                <div class="flex items-center gap-1" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-slot-editor>
                                                    <x-fa::input size="sm" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                                                           class="flex-1 min-w-0" placeholder="Name für Gäste, leer lassen für den Standard" aria-label="Name für Gäste" data-fb-slot-input />
                                                    <x-fa::icon-button icon="heroicon-m-check" label="Name speichern" size="sm" wire:click="slotWordingSpeichern" />
                                                    <x-fa::icon-button icon="heroicon-m-x-mark" label="Abbrechen" size="sm" wire:click="slotWordingAbbrechen" />
                                                </div>
                                            @else
                                                <div class="group/dish flex items-center gap-1.5 text-[length:var(--fa-text-md)] {{ ($g['source'] ?? null) === 'name' ? 'italic text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px">
                                                    <span class="min-w-0 break-words">{{ $g['text'] }}</span>
                                                    @if(($g['source'] ?? null) === 'name')<span class="shrink-0 not-italic text-[length:var(--fa-text-sm)]">Gästetext fehlt</span>@endif
                                                    {{-- Einzelpreis-Concept: VK je direkter Gericht-Zeile auch in der Editor-Vorschau. --}}
                                                    @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 not-italic text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $euro($g['preis']) }} pro Person</span>@endif
                                                    @if($istEditierbar)
                                                        <button type="button" wire:click="slotWordingBearbeiten({{ $block->id }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                                                                class="shrink-0 inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] opacity-0 group-hover/dish:opacity-100 focus:opacity-100 transition-opacity" title="Namen für Gäste bearbeiten" aria-label="Namen für Gäste bearbeiten" data-fb-slot-edit>@svg('heroicon-m-pencil', 'w-3.5 h-3.5')</button>
                                                    @endif
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                @if($editBlockId === $block->id)
                                    <div class="mt-2 ml-6 flex flex-col gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] p-3">
                                        @if(in_array($block->type, ['header_neutral', 'header_frei', 'header_frei_preis']))
                                            <x-fa::field label="Überschrift" for="blk-label-{{ $block->id }}">
                                                <x-fa::input id="blk-label-{{ $block->id }}" wire:model="blockForm.label" />
                                            </x-fa::field>
                                        @endif
                                        @if($block->type === 'header_frei_preis')
                                            <div class="flex flex-wrap gap-3">
                                                <x-fa::field label="Preisart" for="blk-basis-{{ $block->id }}">
                                                    <x-fa::select id="blk-basis-{{ $block->id }}" wire:model="blockForm.price_basis" class="w-40"><option value="person">Pro Person</option><option value="pauschal">Pauschal</option><option value="staffel">Staffel</option></x-fa::select>
                                                </x-fa::field>
                                                <x-fa::field label="Preis in €" for="blk-preis-{{ $block->id }}">
                                                    <x-fa::input id="blk-preis-{{ $block->id }}" type="number" step="0.01" wire:model="blockForm.price_value" numeric class="w-32" placeholder="0,00" />
                                                </x-fa::field>
                                            </div>
                                        @endif
                                        @if($block->type === 'concept_ref')
                                            {{-- Wording-Kette, oberste Stufe: Foodbook-Override → Konzept-Wording → VK-Wording-Standard → Name --}}
                                            <x-fa::field label="Name für Gäste" for="blk-wording-{{ $block->id }}" hint="Leer lassen, dann gilt der Text des Konzepts, sonst der Standard oder der Name.">
                                                <x-fa::input id="blk-wording-{{ $block->id }}" wire:model="blockForm.wording" data-fb-block-wording />
                                            </x-fa::field>
                                        @endif
                                        @if($block->type === 'recipe_ref')
                                            {{-- E1.3: Einzel-Gericht — Wording-Override (Foodbook → VK-Wording-Standard → Name) + Preis-Achse (E1.2) --}}
                                            <div class="flex flex-wrap gap-3">
                                                <x-fa::field label="Name für Gäste" for="blk-wording-{{ $block->id }}" class="flex-1 min-w-[14rem]" hint="Leer lassen, dann gilt der Standard oder der Name.">
                                                    <x-fa::input id="blk-wording-{{ $block->id }}" wire:model="blockForm.wording" data-fb-block-wording />
                                                </x-fa::field>
                                                <x-fa::field label="Preisart" for="blk-basis-{{ $block->id }}">
                                                    <x-fa::select id="blk-basis-{{ $block->id }}" wire:model="blockForm.price_basis" class="w-48" title="Wie der Preis dieses Gerichts zählt"><option value="person">Pro Position, mal Gäste</option><option value="pauschal">Pauschal</option></x-fa::select>
                                                </x-fa::field>
                                            </div>
                                        @endif
                                        @if($block->type === 'text')
                                            <x-fa::field label="Text für Gäste" for="blk-text-{{ $block->id }}">
                                                <x-fa::textarea id="blk-text-{{ $block->id }}" wire:model="blockForm.customer_text" rows="3"></x-fa::textarea>
                                            </x-fa::field>
                                        @else
                                            <x-fa::field label="Beschreibung für Gäste" for="blk-text-{{ $block->id }}" optional>
                                                <div class="flex items-start gap-1.5">
                                                    <x-fa::textarea id="blk-text-{{ $block->id }}" wire:model="blockForm.customer_text" rows="2" class="flex-1" placeholder="Untertitel oder kurzer Text"></x-fa::textarea>
                                                    @if($block->type === 'concept_ref')
                                                        <x-foodalchemist::ki-action action="kiKundentext" variant="icon" icon="heroicon-o-sparkles" label="Beschreibung vorschlagen"
                                                                title="Die KI schreibt einen verkaufenden Text zu diesem Concept" class="shrink-0 mt-2" data-fb-ki-kundentext />
                                                    @endif
                                                </div>
                                            </x-fa::field>
                                        @endif
                                        <x-fa::field label="Interne Notiz" for="blk-notiz-{{ $block->id }}" hint="Gäste sehen die Notiz nicht.">
                                            <x-fa::input id="blk-notiz-{{ $block->id }}" wire:model="blockForm.interne_bemerkung" />
                                        </x-fa::field>
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <x-fa::button variant="ghost" size="sm" wire:click="$set('editBlockId', null)">Abbrechen</x-fa::button>
                                            <x-fa::button variant="primary" size="sm" icon="heroicon-m-check" wire:click="blockSpeichern">Eintrag übernehmen</x-fa::button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            {{-- E1b (Spec 40): der KI-Weg (Voll-Kaskade) ist nicht tot, nur woanders — der Leerzustand zeigt ihn. --}}
                            <x-fa::empty icon="heroicon-o-squares-plus" title="Noch kein Inhalt">
                                Im Katalog ein Concept, Paket oder Format einfügen oder oben Text und Überschriften ergänzen.
                                Die Leitstelle kann je Kapitel auch automatisch ein Konzept erstellen, es landet dann hier.
                            </x-fa::empty>
                        @endforelse
                    </div>

                </x-fa::section>{{-- /Inhalt --}}
                </div>{{-- /linke Spalte (Kopf + Inhalt) --}}

                {{-- #3: Katalog — Concept · Paket · Format. Suche + Filter; „+" bucht Concept/Paket ins gewählte
                     Kapitel, Format als eigenes Kapitel (F5: live concept_ref-Blöcke). Server-Modus. --}}
                <x-foodalchemist::katalog-picker marker="fb" switch="katalogModus" :modes="[
                    ['key' => 'concept', 'label' => 'Concepts', 'active' => $pickerModus === 'concept'],
                    ['key' => 'paket', 'label' => 'Pakete', 'active' => $pickerModus === 'paket'],
                    ['key' => 'format', 'label' => 'Formate', 'active' => $pickerModus === 'format'],
                ]">
                    @if($pickerModus === 'concept')
                        <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen" aria-label="Concept suchen" class="w-full mb-2 shrink-0" data-fb-katalog-concept />
                        @php $facettenAktiv = collect($conceptFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty(); @endphp
                        <div class="grid grid-cols-2 gap-1.5 mb-2 shrink-0" data-fb-concept-facetten>
                            <x-fa::select size="sm" wire:model.live="conceptFacetten.eventtyp" aria-label="Eventtyp" data-fb-facet-eventtyp>
                                <option value="">Alle Eventtypen</option>
                                @foreach($facetteEventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="conceptFacetten.servierform" aria-label="Servierform" data-fb-facet-servierform>
                                <option value="">Alle Servierformen</option>
                                @foreach($facetteServierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="conceptFacetten.einsatzmoment" aria-label="Einsatzmoment" data-fb-facet-einsatzmoment>
                                <option value="">Alle Einsatzmomente</option>
                                @foreach($facetteMomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="conceptFacetten.season" aria-label="Saison" data-fb-facet-season>
                                <option value="">Alle Saisons</option>
                                @foreach($facetteSaisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                            </x-fa::select>
                        </div>
                        <div class="flex-1 overflow-y-auto flex flex-col gap-0.5">
                            @forelse($conceptKandidaten as $ck)
                                <x-foodalchemist::katalog-row wire:key="kck-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})" :title="$ck->name" :price="$ck->price_per_person_cache !== null ? number_format((float) $ck->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $ck->name }}</x-foodalchemist::katalog-row>
                            @empty
                                <p class="px-2 py-2 {{ $hinweis }}">{{ $conceptSuche !== '' || $facettenAktiv ? 'Keine Concepts für diese Auswahl.' : 'Noch keine Concepts angelegt.' }}</p>
                            @endforelse
                        </div>
                    @elseif($pickerModus === 'paket')
                        {{-- #3: Paket-Reiter (kind=paket-Concepts) — zeigt den Kundennamen (consumer_name). --}}
                        <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen" aria-label="Paket suchen" class="w-full mb-2 shrink-0" data-fb-katalog-paket />
                        @php $paketFacettenAktiv = collect($paketFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty(); @endphp
                        <div class="grid grid-cols-2 gap-1.5 mb-2 shrink-0" data-fb-paket-facetten>
                            <x-fa::select size="sm" wire:model.live="paketFacetten.eventtyp" aria-label="Eventtyp" data-fb-paket-facet-eventtyp>
                                <option value="">Alle Eventtypen</option>
                                @foreach($facetteEventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="paketFacetten.servierform" aria-label="Servierform" data-fb-paket-facet-servierform>
                                <option value="">Alle Servierformen</option>
                                @foreach($facetteServierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="paketFacetten.einsatzmoment" aria-label="Einsatzmoment" data-fb-paket-facet-einsatzmoment>
                                <option value="">Alle Einsatzmomente</option>
                                @foreach($facetteMomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::select size="sm" wire:model.live="paketFacetten.season" aria-label="Saison" data-fb-paket-facet-season>
                                <option value="">Alle Saisons</option>
                                @foreach($facetteSaisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                            </x-fa::select>
                        </div>
                        <div class="flex-1 overflow-y-auto flex flex-col gap-0.5">
                            @forelse($paketKandidaten as $pk)
                                <x-foodalchemist::katalog-row wire:key="kpk-{{ $pk->id }}" wire:click="paketHinzu({{ $pk->id }})" :title="$pk->consumer_name ?: $pk->name" :price="$pk->price_per_person_cache !== null ? number_format((float) $pk->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $pk->consumer_name ?: $pk->name }}</x-foodalchemist::katalog-row>
                            @empty
                                <p class="px-2 py-2 {{ $hinweis }}">{{ $paketSuche !== '' || $paketFacettenAktiv ? 'Keine Pakete für diese Auswahl.' : 'Noch keine Pakete angelegt.' }}</p>
                            @endforelse
                        </div>
                    @else
                        {{-- F5: Format WIE EIN CONCEPT buchen — wird ein eigenes Kapitel (Editionen als live concept_ref-Blöcke). --}}
                        <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen" aria-label="Format suchen" class="w-full mb-2 shrink-0" data-fb-katalog-format />
                        @error('formatKapitel')<p class="px-1 mb-1 shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                        <p class="mb-1 shrink-0 {{ $hinweis }}">Ein Format wird ein eigenes Kapitel, seine Editionen bleiben aktuell.</p>
                        <div class="flex-1 overflow-y-auto flex flex-col gap-0.5">
                            @forelse($formatKandidaten as $fk)
                                <x-foodalchemist::katalog-row wire:key="kfmt-{{ $fk->id }}" wire:click="formatEinfuegen({{ $fk->id }})" :title="$fk->consumer_name ?: $fk->name">{{ $fk->name }}@if($fk->origin === 'kunde')<span class="ml-1 text-[var(--fa-ink-3)]">(vom Kunden)</span>@endif</x-foodalchemist::katalog-row>
                            @empty
                                <p class="px-2 py-2 {{ $hinweis }}">Noch keine Formate angelegt.</p>
                            @endforelse
                        </div>
                    @endif
                </x-foodalchemist::katalog-picker>
            </div>{{-- /2col Speisen --}}
            @else
                <x-fa::empty icon="heroicon-o-queue-list" title="Kein Kapitel gewählt">Links im Kapitelbaum ein Kapitel wählen, um seine Speisen zu bearbeiten.</x-fa::empty>
            @endif{{-- /Kapitel-Editor --}}
                </div>{{-- /Speisen-Tab (Spec 29 / S6) --}}
                </x-foodalchemist::editor-tabs>
            </div>{{-- /fbcockpit --}}
                </div>{{-- /Mitte --}}

                {{-- Rechtes Overview-Panel (Leitstelle-Rail) ENTFERNT (Dominique 2026-08-23): seine Views sind eigene
                     Reiter. Die Rail-Komponente bleibt (ungemountet) für evtl. Wiederverwendung. --}}
            </div>{{-- /Spalten-Cockpit --}}
            </x-foodalchemist::modal>{{-- /Editor-Modal (Spec 29) --}}
        @else
            <x-fa::empty icon="heroicon-o-book-open" title="Kein Foodbook gewählt" class="fa-surface">
                Links ein Foodbook wählen oder ein neues anlegen. Das Foodbook bündelt fertige Concepts zu einem Portfolio
                mit Kapiteln und Preisen pro Person. Gästezahl und Gesamtpreis stehen im Angebot, einzelne Gerichte im Concepter.
                <x-slot:action>
                    <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu">Foodbook anlegen</x-fa::button>
                </x-slot:action>
            </x-fa::empty>
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
