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
                <h2 class="text-base font-semibold text-gray-800">Planungs-Ebene</h2>
                <p class="text-sm text-gray-600 mt-1">
                    Analysiere einen Trend, sammle Skizzen und gib dann „Go" auf ein Basisrezept, Gericht oder Concept.
                    Starte links oben eine neue Planung — oder öffne einen Trend im Trendradar über „In Planung öffnen".
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
                <button type="button" @click="tab='planung'"
                        :class="tab==='planung' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Planung</button>
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

            {{-- PLANUNG + GO --}}
            <div x-show="tab==='planung'" class="space-y-4">
                <x-foodalchemist::modal-section title="Rahmen">
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Titel</label>
                    <input type="text" wire:model="form.title" class="{{ $input }} mb-3" />
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Brief (geht in die Erzeugung)</label>
                    <textarea wire:model="form.brief" rows="3" class="{{ $input }} mb-3"></textarea>
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
                    <select wire:model="form.creative_mode" class="{{ $input }}">
                        @foreach($modeLabel as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Go — erzeugen (Draft)">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
                        Wähle die Stufe — der Entwurf entsteht in-place (Draft), im Hintergrund. Jede Stufe ist
                        einzeln abrufbar; die Kaskade läuft im Ergebnis unten sichtbar durch.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="goKaskade('rezept')" @disabled($laeuft) class="{{ $btnGhost }} disabled:opacity-40">
                            @svg('heroicon-o-beaker', 'w-4 h-4') Basisrezept
                        </button>
                        <button wire:click="goKaskade('gericht')" @disabled($laeuft) class="{{ $btnGhost }} disabled:opacity-40">
                            @svg('heroicon-o-cake', 'w-4 h-4') Gericht
                        </button>
                        <button wire:click="goKaskade('concept')" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                            @svg('heroicon-o-squares-2x2', 'w-4 h-4') Concept
                        </button>
                    </div>

                    @if($laeuft)
                        <div wire:poll.1500ms="pruefeLauf" class="mt-3 flex items-center gap-2 text-xs text-amber-300">
                            @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                            <span>Entwurf wird erzeugt — läuft im Hintergrund …</span>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>

                {{-- Ergebnis des Laufs (Steps + erzeugte Draft-Artefakte) + Freigabe (Gate 2). --}}
                @if($lauf)
                    @php
                        $stepLabel = ['queued' => 'wartet', 'running' => 'läuft', 'done' => 'Entwurf', 'freigegeben' => 'freigegeben', 'verworfen' => 'verworfen', 'failed' => 'Fehler', 'skipped' => 'übernommen'];
                        $stepColor = ['queued' => 'text-amber-300', 'running' => 'text-amber-300', 'done' => 'text-emerald-300', 'freigegeben' => 'text-emerald-400', 'verworfen' => 'text-gray-500', 'failed' => 'text-rose-300', 'skipped' => 'text-gray-400'];
                        $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
                        $offeneEntwuerfe = $lauf->steps->where('status', 'done')->count();
                    @endphp
                    <x-foodalchemist::modal-section title="Ergebnis (Entwürfe) — Freigabe">
                        @if($offeneEntwuerfe > 0)
                            <div class="flex items-center justify-between gap-2 mb-2 pb-2 border-b border-white/10">
                                <span class="text-[11px] text-gray-400">{{ $offeneEntwuerfe }} Entwurf/Entwürfe warten auf Freigabe</span>
                                <span class="flex gap-2">
                                    <button wire:click="alleFrei" class="text-[11px] text-emerald-300 hover:text-emerald-200">Alle freigeben</button>
                                    <button wire:click="alleVerwerfen" class="text-[11px] text-rose-300 hover:text-rose-200">Alle verwerfen</button>
                                </span>
                            </div>
                        @endif
                        <div class="space-y-1.5">
                            @forelse($lauf->steps as $st)
                                <div wire:key="step-{{ $st->id }}" class="flex items-center justify-between gap-3 text-xs">
                                    <span class="truncate text-gray-200">{{ $st->label ?: ucfirst($st->kind) }}</span>
                                    <span class="shrink-0 flex items-center gap-2">
                                        <span class="{{ $stepColor[$st->status] ?? 'text-gray-400' }}">{{ $stepLabel[$st->status] ?? $st->status }}</span>
                                        @if($st->ref_id && isset($refRoute[$st->kind]) && in_array($st->status, ['done', 'freigegeben'], true))
                                            <a href="{{ route($refRoute[$st->kind]) }}" class="text-violet-300 hover:text-violet-200 underline">öffnen</a>
                                        @endif
                                        @if($st->status === 'done')
                                            <button wire:click="gibFrei({{ $st->id }})" class="text-emerald-300 hover:text-emerald-200" title="Freigeben">@svg('heroicon-o-check', 'w-4 h-4')</button>
                                            <button wire:click="verwirf({{ $st->id }})" class="text-rose-300 hover:text-rose-200" title="Verwerfen">@svg('heroicon-o-trash', 'w-4 h-4')</button>
                                        @endif
                                    </span>
                                </div>
                                @if($st->status === 'failed' && $st->error)
                                    <p class="text-[10px] text-rose-400/80 pl-1">{{ \Illuminate\Support\Str::limit($st->error, 160) }}</p>
                                @endif
                            @empty
                                <p class="text-xs text-gray-500">Noch keine Schritte.</p>
                            @endforelse
                        </div>
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            {{-- COMPOSER — Foodpairing-Fläche: Anker zusammenstellen, Netz zeigt live was passt (★★★/★★).
                 Graph-only (keine Generierung — separates Thema). Klick auf Kandidat nimmt ihn auf. --}}
            <div x-show="tab==='composer'" class="space-y-4">
                <x-foodalchemist::modal-section title="Foodpairing — Komposition">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
                        Zutaten/Anker zusammenstellen — das Netz zeigt live, was harmoniert (★★★ Best · ★★ Good).
                        Suche unten oder klick einen Kandidaten im Netz.
                    </p>
                    <input type="text" wire:model.live.debounce.300ms="composerTerm"
                           placeholder="Zutat / Anker suchen …" class="{{ $input }}" />
                    @if(!empty($composerTreffer))
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($composerTreffer as $t)
                                <button type="button" wire:key="ctreffer-{{ $t->id }}" wire:click="composerAdd({{ $t->id }})"
                                        class="px-2 py-1 rounded-full text-[11px] bg-white/10 text-gray-100 hover:bg-white/20 border border-white/15">
                                    + {{ $t->display_de ?: $t->slug }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if(!empty($composerAnker))
                        <div class="flex flex-wrap gap-1.5 mt-3">
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
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Was passt zusammen">
                    @if(empty($composerAnker))
                        <p class="text-[13px] text-slate-400">
                            Noch keine Zutat gewählt — such oben eine und füg sie hinzu. Dann zeigt das Netz die
                            passenden Kandidaten (★★★/★★) und wie die Anker untereinander zusammenhängen.
                        </p>
                    @else
                        <div wire:ignore
                             wire:key="composer-netz-{{ $composerNetz['meta']['sig'] ?? '0' }}"
                             x-data="pairingNetzGraph({
                                 nodes: @js($composerNetz['nodes']),
                                 edges: @js($composerNetz['edges']),
                                 mode: 'modal',
                                 canvasW: {{ (float) ($composerNetz['meta']['canvas_w'] ?? 1000) }},
                                 canvasH: {{ (float) ($composerNetz['meta']['canvas_h'] ?? 760) }},
                                 typDefault: @js($composerNetz['meta']['typ_default'] ?? ['stern3' => true, 'stern2' => true]),
                                 onKandidatClick: (id) => $wire.composerAdd(id),
                             })">
                            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet"
                                 class="w-full rounded-xl" style="height:64vh; background:#0b1120" data-fa-netz-mount></svg>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
            </div>
        @endif
    </x-foodalchemist::modal>
</x-ui-page>
