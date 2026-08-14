{{-- Planungs-/Kreativ-Cockpit (Doppel-Diamant). Haus-Layout: links Kategorie→Session-Baum,
     Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor (Analyse·Skizzen·Planung·Composer). --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $statusLabel = ['divergenz' => 'Divergenz', 'konvergenz' => 'Konvergenz', 'erledigt' => 'Erledigt'];
    $modeLabel = ['voll_kreativ' => 'Voll kreativ', 'hybrid' => 'Hybrid', 'datenbank' => 'Datenbank'];
    $chip = fn ($t, $c = 'bg-black/[0.04] text-gray-600') => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ' . $c . '">' . e($t) . '</span>';
    $skizzenAnzahl = $skizzen ? (count($skizzen['einzel']) + collect($skizzen['gruppen'])->sum(fn ($g) => count($g['ideen']))) : 0;
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Planung" icon="heroicon-o-light-bulb" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Planung'],
        ]" />
    </x-slot>

    {{-- LINKS: Neue Planung + Kategorie→Session-Baum --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Planungen" width="w-80">
            <div class="p-3 space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="neuTitel" wire:keydown.enter="neuePlanung"
                           placeholder="Neue Planung …" class="{{ $input }}" />
                    <button wire:click="neuePlanung" class="{{ $btnPrimary }} shrink-0" title="Neue Planung">
                        @svg('heroicon-o-plus', 'w-4 h-4')
                    </button>
                </div>

                <div class="space-y-2 max-h-[68vh] overflow-y-auto -mx-1 px-1">
                    @forelse($baum as $ast)
                        <div wire:key="cat-{{ $loop->index }}">
                            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide px-1 mb-0.5">{{ $ast['category'] }}</p>
                            <div class="space-y-0.5">
                                @foreach($ast['sessions'] as $s)
                                    <button wire:key="sess-{{ $s->id }}" wire:click="waehle({{ $s->id }})"
                                            class="w-full flex items-center justify-between gap-2 text-left px-2 py-1 rounded-md text-xs hover:bg-black/[0.04] {{ $active && $active->id === $s->id ? 'bg-violet-500/10 text-violet-700' : 'text-gray-700' }}">
                                        <span class="truncate">{{ $s->title }}</span>
                                        <span class="shrink-0 text-[9px] text-gray-400">{{ $statusLabel[$s->status] ?? $s->status }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500 px-1">Noch keine Planungen — links oben eine starten oder im Trendradar „In Planung öffnen".</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE: Vorschau / Dashboard --}}
    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        {{-- Leitstelle: freie 1-Klick-Erstellung — die eine KI-Erstell-Fläche (de-trend). Legt eine
             leichte „Freie Erstellung"-Session (cockpit_frei) an und öffnet den Editor auf dem
             Planung-Tab mit den Regler-Leitplanken. Trend bleibt EIN Input, nicht der Rahmen. --}}
        <div class="{{ $card }} p-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-gray-800 mr-1">Neu erstellen</span>
                <button wire:click="schnellErstellen('rezept')" class="{{ $btnGhost }}" data-frei-rezept>
                    @svg('heroicon-o-beaker', 'w-4 h-4') Basisrezept
                </button>
                <button wire:click="schnellErstellen('gericht')" class="{{ $btnGhost }}" data-frei-gericht>
                    @svg('heroicon-o-cake', 'w-4 h-4') Gericht
                </button>
                <button wire:click="schnellErstellen('concept')" class="{{ $btnPrimary }}" data-frei-concept>
                    @svg('heroicon-o-squares-2x2', 'w-4 h-4') Concept
                </button>
                <span class="text-[11px] text-gray-500 ml-1">— mit KI, direkt mit Regler-Leitplanken.</span>
            </div>
        </div>

        @if($active)
            <div class="{{ $card }} p-5 space-y-3">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-800">{{ $active->title }}</h2>
                        <div class="mt-1 flex flex-wrap gap-1">
                            {!! $chip($statusLabel[$active->status] ?? $active->status, 'bg-violet-500/10 text-violet-700') !!}
                            {!! $chip($active->source_knowledge_document_id ? 'aus Trend' : 'Freier Brief', 'bg-sky-500/10 text-sky-700') !!}
                            {!! $chip($skizzenAnzahl . ' Skizzen') !!}
                        </div>
                    </div>
                    <button wire:click="oeffne({{ $active->id }})" class="{{ $btnPrimary }} shrink-0">
                        @svg('heroicon-o-pencil-square', 'w-4 h-4')
                        Öffnen
                    </button>
                </div>
                @if($active->brief)
                    <p class="text-sm text-gray-600">{{ $active->brief }}</p>
                @endif
                @if($active->analysis)
                    <p class="text-xs text-gray-500 line-clamp-3">{{ \Illuminate\Support\Str::limit($active->analysis, 320) }}</p>
                @endif
            </div>
        @else
            <div class="{{ $card }} p-5">
                <h2 class="text-base font-semibold text-gray-800">Planung — KI-Leitstelle</h2>
                <p class="text-sm text-gray-600 mt-1">
                    Erstelle direkt ein Basisrezept, Gericht oder Concept — mit KI und Regler-Leitplanken (oben „Neu erstellen").
                    Ein Trend ist EIN möglicher Input (im Trendradar „In Planung öffnen"), nicht der Rahmen.
                </p>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-2">Zuletzt</h3>
                @if($sessions->count() === 0)
                    <div class="{{ $card }} p-4 text-xs text-gray-500">Noch keine Planungen.</div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach($sessions->take(9) as $s)
                            <button wire:key="dash-{{ $s->id }}" wire:click="waehle({{ $s->id }})"
                                    class="{{ $card }} p-3 text-left hover:shadow-md transition-all">
                                <span class="text-xs font-semibold text-gray-800 truncate block">{{ $s->title }}</span>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    {!! $chip($statusLabel[$s->status] ?? $s->status, 'bg-violet-500/10 text-violet-700') !!}
                                    @if($s->category)  {!! $chip($s->category, 'bg-sky-500/10 text-sky-700') !!} @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </x-ui-page-container>

    {{-- RECHTS: Detail --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Details" width="w-80" :maxWidth="560" scope="activity_planung" side="right">
            @if($active)
                <div class="p-4 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-800">{{ $active->title }}</h3>
                    <div class="flex flex-wrap gap-1">
                        {!! $chip($statusLabel[$active->status] ?? $active->status, 'bg-violet-500/10 text-violet-700') !!}
                        {!! $chip($modeLabel[$active->creative_mode] ?? $active->creative_mode) !!}
                    </div>
                    <dl class="text-[11px] text-gray-500 space-y-1">
                        <div class="flex justify-between"><dt>Herkunft</dt><dd>{{ $active->source_knowledge_document_id ? 'Trend #' . $active->source_knowledge_document_id : 'Freier Brief' }}</dd></div>
                        <div class="flex justify-between"><dt>Skizzen</dt><dd>{{ $skizzenAnzahl }}</dd></div>
                    </dl>
                    <button wire:click="oeffne({{ $active->id }})" class="{{ $btnGhostXs }}">Im Editor öffnen</button>
                </div>
            @else
                <div class="p-4 text-xs text-gray-500">Eine Planung wählen, um Herkunft, Status und Lineage zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- FULLSCREEN-DARK-EDITOR --}}
    <x-foodalchemist::modal name="planung-editor" fullscreen dark-canvas title="Planung"
                            :title-name="$active?->title" tab-init="analyse">
        <x-slot:actions>
            <button wire:click="speichern" class="{{ $btnPrimary }}">
                @svg('heroicon-o-check', 'w-4 h-4')
                Speichern
            </button>
            @if($meldung !== null)
                <span class="text-xs text-emerald-300">{{ $meldung }}</span>
            @endif
            @if($fehler !== null)
                <span class="text-xs text-rose-300">{{ $fehler }}</span>
            @endif
        </x-slot:actions>

        <x-slot:tabs>
            <div class="flex gap-1">
                <button type="button" @click="tab='analyse'"
                        :class="tab==='analyse' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Analyse</button>
                <button type="button" @click="tab='skizzen'"
                        :class="tab==='skizzen' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Skizzen</button>
                <button type="button" @click="tab='basisrezept'"
                        :class="tab==='basisrezept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Basisrezept</button>
                <button type="button" @click="tab='gericht'"
                        :class="tab==='gericht' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Gericht</button>
                <button type="button" @click="tab='concept'"
                        :class="tab==='concept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Concept</button>
                <button type="button" @click="tab='worker'"
                        :class="tab==='worker' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium inline-flex items-center gap-1">Worker @if($laeuft)<span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>@endif</button>
                <button type="button" @click="tab='composer'"
                        :class="tab==='composer' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Composer</button>
            </div>
        </x-slot:tabs>

        @if($active)
            {{-- ANALYSE --}}
            <div x-show="tab==='analyse'" class="space-y-4">
                <x-foodalchemist::modal-section title="Analyse / Ausgangslage">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Trend-Inhalt bzw. deine Analyse — die Grundlage für Skizzen und „Go".</p>
                    <textarea wire:model="form.analysis" rows="14" class="{{ $input }} font-mono text-[12px] leading-relaxed"
                              placeholder="Was ist der Trend? Was ist die Idee? Constraints, Anlass, Richtung …"></textarea>
                </x-foodalchemist::modal-section>
            </div>

            {{-- SKIZZEN (Divergenz-Board) --}}
            <div x-show="tab==='skizzen'" class="space-y-4">
                <x-foodalchemist::modal-section title="Skizze hinzufügen">
                    <div class="flex gap-2">
                        <input type="text" wire:model="ideeTitel" wire:keydown.enter="ideeHinzu"
                               placeholder="Gericht-Skizze (Titel) …" class="{{ $input }}" />
                        <button wire:click="ideeHinzu" class="{{ $btnPrimary }} shrink-0">Skizze</button>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <input type="text" wire:model="paketName" wire:keydown.enter="paketBilden"
                               placeholder="Paket/Gruppe (Name) …" class="{{ $input }}" />
                        <button wire:click="paketBilden" class="{{ $btnGhost }} shrink-0">Paket</button>
                    </div>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Skizzen ({{ $skizzenAnzahl }})">
                    @if($skizzen)
                        @forelse($skizzen['gruppen'] as $g)
                            <div wire:key="grp-{{ $g['gruppe']->id }}" class="mb-3">
                                <p class="text-xs font-semibold text-gray-800">📦 {{ $g['gruppe']->name }}</p>
                                <div class="pl-3 space-y-1 mt-1">
                                    @foreach($g['ideen'] as $i)
                                        <div wire:key="gi-{{ $i->id }}" class="flex items-center justify-between text-xs text-gray-700">
                                            <span class="truncate">{{ $i->title }}</span>
                                            <button wire:click="ideeVerwerfen({{ $i->id }})" class="text-[10px] text-rose-500 hover:text-rose-600">verwerfen</button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                        @endforelse

                        @forelse($skizzen['einzel'] as $i)
                            <div wire:key="ei-{{ $i->id }}" class="flex items-center justify-between text-xs text-gray-700 py-0.5">
                                <span class="truncate">{{ $i->title }}</span>
                                <button wire:click="ideeVerwerfen({{ $i->id }})" class="text-[10px] text-rose-500 hover:text-rose-600">verwerfen</button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Noch keine Skizzen — oben eine anlegen.</p>
                        @endforelse
                    @endif
                </x-foodalchemist::modal-section>
            </div>

            {{-- BASISREZEPT — eigener Tab mit seinen Leitplanken --}}
            <div x-show="tab==='basisrezept'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'rezept', 'vk' => false, 'goLabel' => 'Basisrezept', 'goIcon' => 'heroicon-o-beaker'])
            </div>

            {{-- GERICHT — Leitplanken inkl. VK-Achsen --}}
            <div x-show="tab==='gericht'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'gericht', 'vk' => true, 'goLabel' => 'Gericht', 'goIcon' => 'heroicon-o-cake'])
            </div>

            {{-- CONCEPT — reuse-basiert, keine Rezept-Regler --}}
            <div x-show="tab==='concept'" class="space-y-4">
                <x-foodalchemist::modal-section title="Brief">
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Brief (geht in die Erzeugung)</label>
                    <textarea wire:model="form.brief" rows="3" class="{{ $input }} mb-3" placeholder="Konzept-Brief — Anlass, Zielgruppe, Richtung …"></textarea>
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
                    <select wire:model="form.creative_mode" class="{{ $input }}">
                        @foreach($modeLabel as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </x-foodalchemist::modal-section>
                <x-foodalchemist::modal-section title="Go — Concept erzeugen (Draft)">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">Reuse-basiert — die Concept-Struktur entsteht aus dem Brief; Fortschritt im <b>Worker</b>-Tab.</p>
                    <button wire:click="goKaskade('concept')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                        @svg('heroicon-o-squares-2x2', 'w-4 h-4') Concept erzeugen
                    </button>
                </x-foodalchemist::modal-section>
            </div>

            {{-- WORKER — alle Läufe/Entwürfe zusammen: Status + Fan-out-Baum + Freigabe --}}
            <div x-show="tab==='worker'" class="space-y-4">
                @if($laeuft)
                    <div wire:poll.1500ms="pruefeLauf" class="flex items-center gap-2 text-xs text-amber-300">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                        <span>Läuft — der Worker arbeitet die Kaskade ab …</span>
                    </div>
                    @if($hinweis !== null)
                        <p class="text-[11px] text-amber-400" data-planung-watchdog>⏱ {{ $hinweis }}</p>
                    @endif
                @endif

            {{-- Worker-Ergebnis: Status + Fan-out-Baum + Freigabe (Gate 2) --}}
                @if($lauf)
                    @include('foodalchemist::livewire.planung.partials.ergebnis')
                @else
                    <div class="{{ $card }} p-4 text-xs text-gray-500">Noch kein Lauf — starte in „Basisrezept", „Gericht" oder „Concept" einen Go, der Fortschritt läuft hier durch.</div>
                @endif
            </div>

            {{-- COMPOSER — Foodpairing-Fläche: Anker zusammenstellen, Netz zeigt live was passt (★★★/★★).
                 Graph-only (keine Generierung — separates Thema). Klick auf Kandidat nimmt ihn auf. --}}
            <div x-show="tab==='composer'">
                <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] gap-4 items-start">
                {{-- LINKE SPALTE: Picker + Kohäsion --}}
                <div class="space-y-4 min-w-0">
                {{-- Reicher Anker-Picker: Kategorie + Suche + browsebare Liste + gewählte Chips --}}
                <x-foodalchemist::modal-section title="Foodpairing — Komposition">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
                        Zutaten/Anker zusammenstellen — das Netz zeigt live, was harmoniert (★★★ Best · ★★ Good).
                        Filtere/such unten oder klick einen Kandidaten im Netz.
                    </p>

                    <div class="flex flex-wrap gap-2 mb-2">
                        <select wire:model.live="composerCategory" class="{{ $input }} sm:w-56">
                            <option value="">Alle Kategorien</option>
                            @foreach($composerBrowse['kategorien'] as $kat)
                                <option value="{{ $kat }}">{{ $kat }}</option>
                            @endforeach
                        </select>
                        <input type="search" wire:model.live.debounce.300ms="composerTerm"
                               placeholder="Anker suchen …" class="{{ $input }} flex-1 min-w-[12rem]" />
                    </div>

                    @if(!empty($composerAnker))
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            @foreach($composerAnker as $a)
                                <span wire:key="canker-{{ $a['id'] }}"
                                      class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[11px] bg-violet-500/25 text-violet-100 border border-violet-400/40">
                                    {{ $a['label'] }}
                                    <button type="button" wire:click="composerRemove({{ $a['id'] }})"
                                            class="text-violet-200 hover:text-white leading-none">&times;</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <p class="text-[10px] text-slate-500 mb-1">
                        @if($composerFocus !== null && $composerFokusLabel)
                            Punkt = passt zu <span class="text-violet-300">{{ $composerFokusLabel }}</span> · Klick fügt hinzu
                        @else
                            {{ $composerBrowse['total'] }} Anker · Punkt = Fit zur Auswahl · Klick fügt hinzu
                        @endif
                    </p>
                    <div class="max-h-64 overflow-y-auto rounded-lg border border-white/10 divide-y divide-white/5">
                        @forelse($composerBrowse['items'] as $it)
                            <button type="button" wire:key="cbrowse-{{ $it['id'] }}" wire:click="composerAdd({{ $it['id'] }})"
                                    class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left text-[12px] text-slate-100 hover:bg-white/10">
                                <span class="w-2 h-2 rounded-full shrink-0"
                                      style="background: {{ $it['typ'] === 'stern3' ? '#fcd34d' : ($it['typ'] === 'stern2' ? '#f59e0b' : 'transparent') }}; {{ $it['typ'] ? '' : 'border:1px solid rgba(148,163,184,.35);' }}"
                                      title="{{ $it['typ'] === 'stern3' ? '★★★ Best-Match' : ($it['typ'] === 'stern2' ? '★★ Good-Match' : 'kein Match zur Auswahl') }}"></span>
                                <span class="flex-1 truncate">{{ $it['label'] }}</span>
                                @if($it['category'])
                                    <span class="text-[10px] text-slate-500 shrink-0">{{ $it['category'] }}</span>
                                @endif
                                <span class="text-violet-300 shrink-0">+</span>
                            </button>
                        @empty
                            <p class="px-2.5 py-2 text-[12px] text-slate-500">Keine Anker — Filter/Suche anpassen.</p>
                        @endforelse
                    </div>
                </x-foodalchemist::modal-section>

                {{-- „Passt das zusammen?" — geerdet auf GETEILTE Partner (Brücken), nicht auf die
                     in Inspire fast immer leeren Direktkanten (die die irreführende 0 % erzeugten). --}}
                @php $bridge = $composerNetz['meta']['bridge'] ?? null; @endphp
                @if($bridge !== null && count($composerAnker) >= 2)
                    <x-foodalchemist::modal-section title="Passt das zusammen?">
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[12px] text-slate-200">
                            <span>Verbunden:
                                <strong class="{{ ($bridge['pairs_total'] > 0 && $bridge['pairs_connected'] === $bridge['pairs_total']) ? 'text-emerald-300' : ($bridge['pairs_connected'] > 0 ? 'text-amber-300' : 'text-rose-300') }}">{{ $bridge['pairs_connected'] }}/{{ $bridge['pairs_total'] }}</strong>
                                Anker-Paare über gemeinsame Partner
                            </span>
                            @if(!empty($bridge['top']))
                                <span class="text-slate-400">stärkste Brücken: {{ implode(', ', $bridge['top']) }}</span>
                            @endif
                        </div>
                        @if($composerCohesion !== null && ($composerCohesion['rated_pairs'] ?? 0) > 0)
                            <p class="mt-1 text-[11px] text-slate-500">direktes Pairing: {{ $composerCohesion['rated_pairs'] }}/{{ $composerCohesion['total_pairs'] }} Paare (Kohäsion {{ $composerCohesion['score'] }}%)</p>
                        @endif
                        @if(!empty($bridge['orphans']))
                            <p class="mt-1 text-[12px] text-amber-300">⚠ passt (noch) nicht zu den anderen: {{ implode(', ', $bridge['orphans']) }}</p>
                        @endif
                    </x-foodalchemist::modal-section>
                @endif
                </div>{{-- /linke Spalte --}}

                {{-- RECHTE SPALTE: Netz --}}
                <div class="min-w-0">
                {{-- Netz + Filter-Chips in EINER Alpine-Instanz (wie im Detail-Modal) --}}
                <x-foodalchemist::modal-section title="Netz">
                    @if(empty($composerAnker))
                        <p class="text-[13px] text-slate-400">
                            Noch keine Zutat gewählt — oben eine hinzufügen. Dann zeigt das Netz die passenden
                            Kandidaten (★★★/★★), wie die Anker zusammenhängen und wo etwas nicht passt.
                        </p>
                    @else
                        @if($composerFocus !== null && $composerFokusLabel)
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-[11px]">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-violet-500/25 text-violet-100 border border-violet-400/40">
                                    Fokus: {{ $composerFokusLabel }}
                                    <button type="button" wire:click="composerFocus({{ $composerFocus }})" class="text-violet-200 hover:text-white leading-none" title="Fokus aufheben">&times;</button>
                                </span>
                                <span class="text-slate-500">nur seine Verbindungen · Klick aufs Zentrum oder × hebt auf</span>
                            </div>
                        @else
                            <p class="mb-2 text-[10px] text-slate-500">Tipp: Klick auf einen Anker fokussiert ihn — nur seine Verbindungen + Stärke bleiben sichtbar.</p>
                        @endif
                        <div wire:ignore
                             wire:key="composer-netz-{{ $composerNetz['meta']['sig'] ?? '0' }}-f{{ $composerFocus ?? 0 }}"
                             x-data="pairingNetzGraph({
                                 nodes: @js($composerNetz['nodes']),
                                 edges: @js($composerNetz['edges']),
                                 mode: 'modal',
                                 canvasW: {{ (float) ($composerNetz['meta']['canvas_w'] ?? 1000) }},
                                 canvasH: {{ (float) ($composerNetz['meta']['canvas_h'] ?? 760) }},
                                 typDefault: @js($composerNetz['meta']['typ_default'] ?? ['stern3' => true, 'stern2' => true]),
                                 focusId: {{ $composerFocus ?? 'null' }},
                                 onKandidatClick: (id) => $wire.composerAdd(id),
                                 onAnkerClick: (id) => $wire.composerFocus(id),
                             })">
                            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]">
                                <span class="text-slate-400 mr-1">Zeigen:</span>
                                <button type="button" @click="toggleTyp('stern3')"
                                        :class="typAktiv['stern3'] ? 'ring-2 ring-offset-1 ring-offset-slate-900' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-slate-200"
                                        style="border-color:#fcd34d; --tw-ring-color:#fcd34d;">
                                    <span class="w-2 h-2 rounded-full" style="background:#fcd34d"></span> ★★★ Best
                                </button>
                                <button type="button" @click="toggleTyp('stern2')"
                                        :class="typAktiv['stern2'] ? 'ring-2 ring-offset-1 ring-offset-slate-900' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-slate-200"
                                        style="border-color:#f59e0b; --tw-ring-color:#f59e0b;">
                                    <span class="w-2 h-2 rounded-full" style="background:#f59e0b"></span> ★★ Good
                                </button>
                            </div>
                            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet"
                                 class="w-full rounded-xl" style="height:70vh; background:#0b1120" data-fa-netz-mount></svg>
                            {{-- Legende: Punkt auf der Anker-Linie = Beziehungsstärke (groß→klein) --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-slate-300">
                                <span class="text-slate-500">Beziehungsstärke:</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:18px;height:18px;background:#a78bfa;box-shadow:0 0 0 2px #ede9fe"></span> Best</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:11px;height:11px;background:#a78bfa;opacity:.82"></span> Good</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:5px;height:5px;background:#a78bfa;opacity:.5"></span> Match</span>
                                <span class="text-[10px] text-slate-500">· groß→klein = stark→schwach · violett = geteilte Partner, gold = direktes Pairing · Hover zeigt die Partner</span>
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
    <livewire:foodalchemist.verkauf.vk-modal />
</x-ui-page>
