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
                    <select wire:model.live="geschmack" class="{{ $input }}">
                        <option value="">Geschmack</option>
                        @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="fertigung" class="{{ $input }}">
                        <option value="">Fertigung</option>
                        @foreach(['from_scratch' => 'from scratch', 'teilfertig' => 'teilfertig', 'convenience' => 'Convenience'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- R6: Template-Filter (Jarvis-Sidebar) --}}
                <button type="button" wire:click="toggleTemplates"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $nurTemplates
                            ? 'bg-gradient-to-r from-orange-500/15 to-amber-500/15 text-orange-700 dark:text-orange-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}" data-templates-toggle>
                    <span class="font-medium">📐 Templates</span>
                    <span class="text-[11px] {{ $nurTemplates ? 'text-orange-500 font-medium' : 'text-gray-500 dark:text-gray-400' }}">{{ $nurTemplates ? 'active' : $templateAnzahl }}</span>
                </button>

                <button type="button" wire:click="waehleHauptgruppe(null)"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === null
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Hauptgruppen</span>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ number_format(array_sum($hgCounts), 0, ',', '.') }}</span>
                </button>

                <div class="space-y-0.5 -mx-1" data-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="hg-{{ $hg->id }}">
                            <button type="button" wire:click="waehleHauptgruppe({{ $hg->id }})"
                                    class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === $hg->id
                                        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate">{{ $hg->label }}</span>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400 shrink-0 ml-2">{{ $hgCounts[$hg->id] ?? 0 }}</span>
                            </button>
                            @if($hauptgruppe === $hg->id && $kategorien->isNotEmpty())
                                <div class="ml-4 mt-0.5 space-y-0.5" data-kat-liste>
                                    @foreach($kategorien as $kat)
                                        @if(($katCounts[$kat->id] ?? 0) > 0)
                                            <button type="button" wire:key="kat-{{ $kat->id }}" wire:click="waehleKategorie({{ $kat->id }})"
                                                    class="w-full flex items-center justify-between px-2 py-0.5 rounded text-[11px] transition-all duration-150 {{ $kategorie === $kat->id
                                                        ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                        : 'text-gray-600 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                                <span class="min-w-0 truncate">{{ $kat->label }}</span>
                                                <span class="text-gray-500 dark:text-gray-400 shrink-0 ml-2">{{ $katCounts[$kat->id] }}</span>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Detail" width="w-96" :maxWidth="760" storeKey="activityOpen" side="right">
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" />
        </x-ui-page-sidebar>
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

    {{-- M5-07: Aroma-Netz-Graph (innerhalb x-ui-page, P-2) --}}
    <livewire:foodalchemist.recipes.aroma-netz-modal />

    {{-- M7-10: Voice-Interface --}}
    <livewire:foodalchemist.recipes.voice-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('recipe-modal.oeffnen')" class="{{ $btnPrimary }}" data-rezept-anlegen>+ Neues Basisrezept</button>
                <button type="button" wire:click="$dispatch('generator-modal.oeffnen')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-generator-oeffnen>✨ KI-Rezept</button>
                {{-- R6: «Aus Template» — Liste der 📐-Templates, Klick dupliziert + öffnet den Editor --}}
                <div class="relative">
                    <button type="button" wire:click="$toggle('templateWahlOffen')" class="{{ $btnGhostXs }}" data-aus-template>📐 Aus Template</button>
                    @if($templateWahlOffen)
                        <div class="absolute left-0 top-full mt-1 z-30 w-80 max-h-80 overflow-y-auto rounded-lg bg-white dark:bg-gray-900 border border-black/10 dark:border-white/10 shadow-xl" data-template-liste>
                            @forelse($templateListe as $template)
                                <button type="button" wire:key="tpl-{{ $template->id }}" wire:click="ausTemplate({{ $template->id }})"
                                        class="block w-full text-left px-3 py-1.5 text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10">
                                    {{ $template->name }}
                                    <span class="text-gray-500 dark:text-gray-400">· {{ $template->n_ingredients_total }} Zutaten{{ $template->yield_kg !== null ? ' · ' . number_format((float) $template->yield_kg, 2, ',', '.') . ' kg' : '' }}</span>
                                </button>
                            @empty
                                <p class="px-3 py-2 text-[11px] text-gray-500 dark:text-gray-400">Keine Templates — im Editor «📐 Template» markieren.</p>
                            @endforelse
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="$dispatch('voice-modal.oeffnen')" class="{{ $btnGhostXs }}" title="Sprachbedienung (M7-10) — zweiter Bedienweg, UI bleibt parallel" data-voice-oeffnen>🎙</button>
            </div>
            @if($bulkRunId !== null)
                @php($bulkSvc = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class))
                @php($run = $bulkSvc->status(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId))
                @if($run !== null)
                    <div class="flex items-center gap-2" @if($run->status === 'running') wire:poll.2s @endif data-bulk-progress>
                        @if($run->status === 'running')
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">✨ Bulk läuft … {{ $run->done }}/{{ $run->total }}</span>
                        @else
                            <span class="{{ $pill }} {{ $variantPill['success'] }}">✨ Bulk fertig: {{ $run->done }}/{{ $run->total }}{{ $run->failed > 0 ? " · {$run->failed} Fehler" : '' }}</span>
                            <span class="text-[11px] text-gray-600 dark:text-gray-400">{{ $bulkSvc->offeneVorschlaege(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId) }} Vorschläge offen</span>
                            <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-bulk-alle-uebernehmen>Alle übernehmen</button>
                            <button type="button" wire:click="bulkSchliessen" class="{{ $btnGhostXs }}" title="Vorschläge bleiben offen (Review)">Schließen</button>
                        @endif
                    </div>
                @endif
            @endif
            @if(count(array_filter($auswahl)) > 0)
                <div class="flex items-center gap-1.5" data-bulk-status>
                    <button type="button" wire:click="bulkAnreichern" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Beschreibung · Kategorie · Geschmack als Review-Vorschläge (GL-07: nie Auto-Persistenz)" data-bulk-anreichern>✨ Bulk anreichern</button>
                    <span class="text-xs text-gray-900 dark:text-gray-100 font-medium">{{ count(array_filter($auswahl)) }} ausgewählt:</span>
                    @foreach(['draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigeben'] as $wert => $lbl)
                        <button type="button" wire:click="bulkStatus('{{ $wert }}')" class="{{ $btnGhostXs }}" data-bulk-status-btn="{{ $wert }}">→ {{ $lbl }}</button>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="relative overflow-hidden {{ $card }}" data-rezept-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Basisrezepte</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ number_format($rezepte->total(), 0, ',', '.') }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <div class="overflow-x-auto">{{-- R13: schmaler Mittelteil scrollt statt abzuschneiden --}}
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    <th class="{{ $th }} !pr-0 w-8"></th>
                    {{-- R13 (Jarvis-Dichte): Name flexibel, Zahlen rechtsbündig --}}
                    @foreach([['Name', 'w-full'], ['Kategorie', ''], ['Geschmack', ''], ['Fertigung', ''], ['Status', ''], ['Zutaten', 'text-right'], ['Yield', 'text-right'], ['Allergen-Konf.', ''], ['Feedback', 'text-right']] as [$head, $align])
                        <th class="{{ $th }} {{ $align }}">{{ $head }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($rezepte as $r)
                        <tr wire:key="r-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity', 'open', true)"
                            class="{{ $tr }} cursor-pointer {{ $recipeId === $r->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}"
                            data-rezept-zeile="{{ $r->id }}">
                            <td class="{{ $td }} !pr-0" wire:click.stop>
                                <input type="checkbox" wire:model.live="auswahl.{{ $r->id }}" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-rezept-checkbox="{{ $r->id }}" />
                            </td>
                            {{-- R6: Namens-Klick öffnet direkt den Voll-Editor (Zeilen-Klick bleibt Panel-Selektion) --}}
                            <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }} — Klick: bearbeiten">
                                <span class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 hover:underline cursor-pointer" data-rezept-name>{{ $r->name }}</span>
                                @if($r->is_template)<span class="{{ $pill }} {{ $variantPill['success'] }} ml-1.5" data-template-badge>📐 Template</span>@endif
                            </td>
                            <td class="{{ $td }} text-[11px] italic text-gray-600 dark:text-gray-400 truncate max-w-[12rem] whitespace-nowrap">{{ $r->category?->label ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $r->taste_direction ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $r->production_depth ?? '—' }}</td>
                            {{-- Inline-Status-Pflege wie bei GP (Kuratoren; Stub bleibt Badge — Auto-Zustand) --}}
                            <td class="{{ $td }} whitespace-nowrap" wire:click.stop @click.stop>
                                @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $r) && $r->status !== \Platform\FoodAlchemist\Enums\RecipeStatus::Stub)
                                    <select wire:key="rst-{{ $r->id }}-{{ $r->status->value }}" wire:change="statusSetzen({{ $r->id }}, $event.target.value)"
                                            class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6" data-status-select>
                                        @foreach([\Platform\FoodAlchemist\Enums\RecipeStatus::Draft, \Platform\FoodAlchemist\Enums\RecipeStatus::Review, \Platform\FoodAlchemist\Enums\RecipeStatus::Approved, \Platform\FoodAlchemist\Enums\RecipeStatus::Deprecated] as $fall)
                                            <option value="{{ $fall->value }}" @selected($r->status === $fall)>{{ $fall->label() }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }}">{{ $r->status->label() }}</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 text-right tabular-nums whitespace-nowrap">
                                {{ $r->n_ingredients_total }}
                                @if($r->n_ingredients_unmapped > 0)<span class="{{ $pill }} {{ $variantPill['warning'] }} ml-1" title="ungemappte Zutaten — F7.1: Allergene unbekannt">{{ $r->n_ingredients_unmapped }}?</span>@endif
                            </td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap text-right tabular-nums">{{ $r->yield_kg !== null ? number_format((float) $r->yield_kg, 3, ',', '.') . ' kg' : '—' }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergens_confidence] ?? $variantPill['secondary'] }}">{{ $r->allergens_confidence }}</span>
                            </td>
                            <td class="{{ $td }} whitespace-nowrap text-right tabular-nums">
                                @php($fb = $feedbackAgg[$r->id] ?? null)
                                @if($fb && $fb['count'] > 0)
                                    <span class="{{ $pill }} {{ ($fb['avg'] ?? 0) >= 4 ? $variantPill['success'] : (($fb['avg'] ?? 0) >= 3 ? $variantPill['warning'] : $variantPill['danger']) }}" title="{{ $fb['count'] }} Feedback-Einträge">★ {{ $fb['avg'] !== null ? number_format((float) $fb['avg'], 1, ',', '.') : '–' }} <span class="opacity-60">({{ $fb['count'] }})</span></span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">Keine Rezepte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
