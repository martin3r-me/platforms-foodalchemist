{{-- M4-04: Basisrezept-Browser (P-1/Screen 4) — HG-Baum links, dichte Tabelle, Panel rechts (M4-05) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Basisrezepte" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Basisrezepte'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Hauptgruppen" width="w-80">
            <div class="p-3 space-y-2" data-rezept-baum>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Rezept-Name oder Key …" class="{{ $input }}" data-rezept-suche />
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="">Alle Status</option>
                    @foreach($statusFaelle as $fall)
                        <option value="{{ $fall->value }}">{{ $fall->label() }} ({{ $statusCounts[$fall->value] ?? 0 }})</option>
                    @endforeach
                </select>
                <div class="grid grid-cols-2 gap-2">
                    {{-- MVP-023: zentrale deutsche Labels statt Rohwerte/„from scratch" --}}
                    <select wire:model.live="geschmack" class="{{ $input }}">
                        <option value="">Geschmack</option>
                        @foreach(['suess', 'herzhaft', 'neutral'] as $wert)
                            <option value="{{ $wert }}">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($wert) }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="fertigung" class="{{ $input }}">
                        <option value="">Fertigung</option>
                        @foreach(['from_scratch', 'teilfertig', 'convenience'] as $wert)
                            <option value="{{ $wert }}">{{ \Platform\FoodAlchemist\Support\Labels::fertigung($wert) }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- R6: Template-Filter (Jarvis-Sidebar) --}}
                <button type="button" wire:click="toggleTemplates"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $nurTemplates
                            ? 'bg-gradient-to-r from-orange-500/15 to-amber-500/15 text-orange-700'
                            : 'text-gray-700 hover:bg-black/[0.03]' }}" data-templates-toggle>
                    <span class="font-medium">@svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5 inline-block align-middle') Templates</span>
                    <span class="text-[11px] {{ $nurTemplates ? 'text-orange-500 font-medium' : 'text-gray-500' }}">{{ $nurTemplates ? 'active' : $templateAnzahl }}</span>
                </button>

                {{-- MVP-042: Gesamtzahl kommt aus der Tabellenquery, NICHT aus array_sum($hgCounts) —
                     die Summe der Hauptgruppen verlor jedes Rezept ohne Kategorie (64 vs. 62). --}}
                <x-foodalchemist::filter-row wire:click="waehleHauptgruppe(null)"
                    :active="$hauptgruppe === null && ! $ohneKategorie"
                    :count="$gesamtCount" data-gesamt-count><span class="font-medium">Alle Hauptgruppen</span></x-foodalchemist::filter-row>

                <div class="space-y-0.5 -mx-1" data-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="hg-{{ $hg->id }}">
                            <x-foodalchemist::filter-row wire:click="waehleHauptgruppe({{ $hg->id }})"
                                :active="$hauptgruppe === $hg->id" :child-active="$kategorie !== null"
                                :count="$hgCounts[$hg->id] ?? 0">{{ $hg->label }}</x-foodalchemist::filter-row>
                            @if($hauptgruppe === $hg->id && $kategorien->isNotEmpty())
                                <x-foodalchemist::filter-ast data-kat-liste>
                                    @foreach($kategorien as $kat)
                                        @if(($katCounts[$kat->id] ?? 0) > 0)
                                            <x-foodalchemist::filter-row level="child" wire:key="kat-{{ $kat->id }}"
                                                wire:click="waehleKategorie({{ $kat->id }})"
                                                :active="$kategorie === $kat->id"
                                                :count="$katCounts[$kat->id]">{{ $kat->label }}</x-foodalchemist::filter-row>
                                        @endif
                                    @endforeach
                                </x-foodalchemist::filter-ast>
                            @endif
                        </div>
                    @endforeach

                    {{-- MVP-042: Rezepte ohne Kategorie waren in der Tabelle sichtbar, über den Baum
                         aber unerreichbar. Nur zeigen, wenn es welche gibt — sonst wäre es eine
                         Dauer-Null, die den Baum verrauscht. --}}
                    @if($ohneKategorieCount > 0 || $ohneKategorie)
                        <button type="button" wire:click="waehleOhneKategorie"
                                class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-xs transition-all duration-150 {{ $ohneKategorie
                                    ? 'bg-gradient-to-r from-amber-500/15 to-orange-500/15 text-amber-700'
                                    : 'text-gray-600 hover:bg-black/[0.03]' }}"
                                title="Basisrezepte ohne Kategorie — über die Hauptgruppen nicht auffindbar"
                                data-ohne-kategorie>
                            <span class="min-w-0 truncate italic">Ohne Kategorie</span>
                            <span class="text-[11px] text-gray-500 shrink-0 ml-2 tabular-nums">{{ $ohneKategorieCount }}</span>
                        </button>
                    @endif
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_recipes" side="right">
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- M4-06: Stammdaten-Modal (P-2: innerhalb x-ui-page) --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    {{-- R7-Fix: Zutat-Klick öffnet das GP als Modal ÜBER dem Editor (neuer Tab bei Dominique blockiert) --}}
    <livewire:foodalchemist.gps.gp-modal />
    {{-- M9-05-Rest: VK-Eltern aus dem Basis-Panel öffnen den VK-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal />

    {{-- M4-07/08: Zutaten-Editor (P-8) --}}
    <livewire:foodalchemist.recipes.ingredient-editor />

    {{-- M4-14: Generator --}}
    <livewire:foodalchemist.recipes.generator-modal />

    {{-- D-5: Aus Vorlage instanziieren (Variante + Slot-Binding) --}}
    <livewire:foodalchemist.recipes.template-instantiate-modal />

    {{-- M5-07: Pairing-Netz-Graph (innerhalb x-ui-page, P-2) --}}
    <livewire:foodalchemist.recipes.pairing-netz-modal />

    {{-- M7-10: Voice-Interface --}}
    <livewire:foodalchemist.recipes.voice-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('recipe-modal.oeffnen')" class="{{ $btnPrimary }}" data-rezept-anlegen>+ Neues Basisrezept</button>
                {{-- KI-Erstellung ist in die Planung-Leitstelle konsolidiert (2026-08): der KI-Rezept-Knopf lebt jetzt dort mit den Regler-Leitplanken. --}}
                {{-- R6: «Aus Template» — Liste der 📐-Templates, Klick dupliziert + öffnet den Editor --}}
                <div class="relative">
                    <button type="button" wire:click="$toggle('templateWahlOffen')" class="{{ $btnGhostXs }}" data-aus-template>@svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5') Aus Template</button>
                    @if($templateWahlOffen)
                        <div class="absolute left-0 top-full mt-1 z-30 w-80 max-h-80 overflow-y-auto rounded-lg bg-white border border-black/10 shadow-xl" data-template-liste>
                            @forelse($templateListe as $template)
                                <button type="button" wire:key="tpl-{{ $template->id }}" wire:click="ausTemplate({{ $template->id }})"
                                        class="block w-full text-left px-3 py-1.5 text-[11px] text-gray-700 hover:bg-violet-500/10">
                                    {{ $template->name }}
                                    <span class="text-gray-500">· {{ $template->n_ingredients_total }} Zutaten{{ $template->yield_kg !== null ? ' · ' . number_format((float) $template->yield_kg, 2, ',', '.') . ' kg' : '' }}</span>
                                </button>
                            @empty
                                <p class="px-3 py-2 text-[11px] text-gray-500">Keine Templates — im Editor «@svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5 inline-block align-middle') Template» markieren.</p>
                            @endforelse
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="$dispatch('voice-modal.oeffnen')" class="{{ $btnAi }}" title="Sprachbedienung (M7-10) — zweiter Bedienweg, UI bleibt parallel" data-voice-oeffnen>@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</button>
            </div>
            @if($bulkRunId !== null)
                @php($bulkSvc = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class))
                @php($run = $bulkSvc->status(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId))
                @if($run !== null)
                    <div class="flex items-center gap-2" @if($run->status === 'running') wire:poll.2s @endif data-bulk-progress>
                        @if($run->status === 'running')
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle') Bulk läuft … {{ $run->done }}/{{ $run->total }}</span>
                        @else
                            <span class="{{ $pill }} {{ $variantPill['success'] }}">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle') Bulk fertig: {{ $run->done }}/{{ $run->total }}{{ $run->failed > 0 ? " · {$run->failed} Fehler" : '' }}</span>
                            <span class="text-[11px] text-gray-600">{{ $bulkSvc->offeneVorschlaege(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId) }} Vorschläge offen</span>
                            <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-bulk-alle-uebernehmen>Alle übernehmen</button>
                            <button type="button" wire:click="bulkSchliessen" class="{{ $btnGhostXs }}" title="Vorschläge bleiben offen (Review)">Schließen</button>
                        @endif
                    </div>
                @endif
            @endif
            @if(count(array_filter($auswahl)) > 0)
                <div class="flex items-center gap-1.5" data-bulk-status>
                    <button type="button" wire:click="bulkAnreichern" class="{{ $btnAi }}" title="Beschreibung · Kategorie · Geschmack als Review-Vorschläge (GL-07: nie Auto-Persistenz)" data-bulk-anreichern>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Bulk anreichern</button>
                    <span class="text-xs text-gray-900 font-medium">{{ count(array_filter($auswahl)) }} ausgewählt:</span>
                    @foreach(['draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigeben'] as $wert => $lbl)
                        <button type="button" wire:click="bulkStatus('{{ $wert }}')" class="{{ $btnGhostXs }}" data-bulk-status-btn="{{ $wert }}">→ {{ $lbl }}</button>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="relative overflow-hidden {{ $card }}" data-rezept-tabelle>
            <div class="{{ $cardAccent }}"></div>
            {{-- MVP-022: Statuswechsel-Fehler sichtbar statt still verschluckt --}}
            @if($statusFehler !== null)
                <div class="mx-5 mt-4 rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-2 text-xs text-rose-700" data-status-fehler>{{ $statusFehler }}</div>
            @endif
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Basisrezepte</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{-- E14: Ansichts-Schalter — knappe Spalten je Aufgabe statt einer Tabelle für alles --}}
                    <span class="flex items-center gap-1" data-ansicht-schalter>
                        @foreach($ansichten as $ak => [$al, $unused])
                            <button type="button" wire:click="$set('ansicht', '{{ $ak }}')"
                                    class="{{ $pill }} {{ $ansicht === $ak ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                    data-ansicht="{{ $ak }}">{{ $al }}</button>
                        @endforeach
                    </span>
                    <span class="text-gray-300">·</span>
                    {{ number_format($rezepte->total(), 0, ',', '.') }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-[11px] uppercase tracking-wider text-gray-500 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <div class="max-h-[70vh] overflow-auto">{{-- R13: schmaler Mittelteil scrollt statt abzuschneiden --}}
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    <th class="{{ $th }} !pr-0 w-8 sticky top-0 z-20 bg-white/95 backdrop-blur-xl"></th>
                    {{-- R13 (Jarvis-Dichte): Name flexibel, Zahlen rechtsbündig --}}
                    {{-- E14: Kopf folgt der aktiven Ansicht. „Name" steht immer. --}}
                    <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                    @foreach($spalten as $sp)
                        <th class="{{ $th }} {{ $spaltenKatalog[$sp][1] }} w-px sticky top-0 z-20 bg-white/95 backdrop-blur-xl">{{ $spaltenKatalog[$sp][0] }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($rezepte as $r)
                        <x-foodalchemist::table-row :active="$recipeId === $r->id" wire:key="r-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity_recipes', 'open', true)"
                            data-rezept-zeile="{{ $r->id }}">
                            <td class="{{ $td }} !pr-0" wire:click.stop>
                                <input type="checkbox" wire:model.live="auswahl.{{ $r->id }}" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-rezept-checkbox="{{ $r->id }}" />
                            </td>
                            {{-- R6: Namens-Klick öffnet direkt den Voll-Editor (Zeilen-Klick bleibt Panel-Selektion) --}}
                            <td class="{{ $td }} font-medium w-full min-w-[8rem] break-words" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }} — Klick: bearbeiten">
                                <span class="text-gray-900 hover:text-violet-600 hover:underline cursor-pointer" data-rezept-name>{{ $r->name }}</span>
                                @if($r->is_template)<span class="{{ $pill }} {{ $variantPill['success'] }} ml-1.5" data-template-badge>@svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5 inline-block align-middle') Template</span>@endif
                            </td>
                            @if(in_array('kategorie', $spalten, true))<td class="{{ $td }} text-[11px] italic text-gray-600 truncate max-w-[5rem] whitespace-nowrap">{{ $r->category?->label ?? '—' }}</td>@endif
                            @if(in_array('geschmack', $spalten, true))<td class="{{ $td }} text-gray-600 whitespace-nowrap">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($r->taste_direction) }}</td>@endif
                            @if(in_array('fertigung', $spalten, true))<td class="{{ $td }} text-gray-600 whitespace-nowrap">{{ \Platform\FoodAlchemist\Support\Labels::fertigung($r->production_depth) }}</td>@endif
                            @if(in_array('ekkg', $spalten, true))<td class="{{ $td }} text-gray-900 whitespace-nowrap text-right tabular-nums" title="Einkaufspreis je Kilogramm — die Kostenzahl des Rezepts">{{ $r->ek_per_kg_eur !== null ? number_format((float) $r->ek_per_kg_eur, 2, ',', '.') : '—' }}</td>@endif
                            @if(in_array('yield', $spalten, true))<td class="{{ $td }} text-gray-600 whitespace-nowrap text-right tabular-nums">{{ $r->yield_kg !== null ? number_format((float) $r->yield_kg, 3, ',', '.') . ' kg' : '—' }}</td>@endif
                            @if(in_array('zutaten', $spalten, true))<td class="{{ $td }} text-gray-600 text-right tabular-nums whitespace-nowrap">
                                {{ $r->n_ingredients_total }}
                                @if($r->n_ingredients_unmapped > 0)<span class="{{ $pill }} {{ $variantPill['warning'] }} ml-1" title="ungemappte Zutaten — F7.1: Allergene unbekannt">{{ $r->n_ingredients_unmapped }}?</span>@endif
                            </td>@endif
                            @if(in_array('allergen', $spalten, true))<td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergens_confidence] ?? $variantPill['secondary'] }}">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($r->allergens_confidence) }}</span>
                            </td>@endif
                            @if(in_array('status', $spalten, true))
                            {{-- Inline-Status-Pflege wie bei GP (Kuratoren; Stub bleibt Badge — Auto-Zustand) --}}
                            <td class="{{ $td }} whitespace-nowrap" wire:click.stop @click.stop>
                                @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $r) && $r->status !== \Platform\FoodAlchemist\Enums\RecipeStatus::Stub)
                                    <select wire:key="rst-{{ $r->id }}-{{ $r->status->value }}" wire:change="statusSetzen({{ $r->id }}, $event.target.value)"
                                            class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6 !w-24" data-status-select>
                                        @foreach([\Platform\FoodAlchemist\Enums\RecipeStatus::Draft, \Platform\FoodAlchemist\Enums\RecipeStatus::Review, \Platform\FoodAlchemist\Enums\RecipeStatus::Approved, \Platform\FoodAlchemist\Enums\RecipeStatus::Deprecated] as $fall)
                                            <option value="{{ $fall->value }}" @selected($r->status === $fall)>{{ $fall->label() }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }}">{{ $r->status->label() }}</span>
                                @endif
                            </td>@endif
                        </x-foodalchemist::table-row>
                    @empty
                        <tr><td colspan="{{ count($spalten) + 2 }}" class="px-5 py-10 text-center text-gray-500">Keine Rezepte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
