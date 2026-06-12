{{-- M4-04: Basisrezept-Browser (P-1/Screen 4) — HG-Baum links, dichte Tabelle, Panel rechts (M4-05) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Basisrezepte" icon="heroicon-o-book-open" />
    </x-slot:navbar>

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

                <button type="button" wire:click="waehleHauptgruppe(null)"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-all duration-150 {{ $hauptgruppe === null
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Hauptgruppen</span>
                    <span class="text-xs text-gray-400">{{ number_format(array_sum($hgCounts), 0, ',', '.') }}</span>
                </button>

                <div class="space-y-0.5 -mx-1" data-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="hg-{{ $hg->id }}">
                            <button type="button" wire:click="waehleHauptgruppe({{ $hg->id }})"
                                    class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-sm transition-all duration-150 {{ $hauptgruppe === $hg->id
                                        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate">{{ $hg->bezeichnung }}</span>
                                <span class="text-xs text-gray-400 shrink-0 ml-2">{{ $hgCounts[$hg->id] ?? 0 }}</span>
                            </button>
                            @if($hauptgruppe === $hg->id && $kategorien->isNotEmpty())
                                <div class="ml-4 mt-0.5 space-y-0.5" data-kat-liste>
                                    @foreach($kategorien as $kat)
                                        @if(($katCounts[$kat->id] ?? 0) > 0)
                                            <button type="button" wire:key="kat-{{ $kat->id }}" wire:click="waehleKategorie({{ $kat->id }})"
                                                    class="w-full flex items-center justify-between px-2 py-0.5 rounded text-xs transition-all duration-150 {{ $kategorie === $kat->id
                                                        ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                        : 'text-gray-500 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                                <span class="min-w-0 truncate">{{ $kat->bezeichnung }}</span>
                                                <span class="text-gray-400 shrink-0 ml-2">{{ $katCounts[$kat->id] }}</span>
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

    {{-- M4-07/08: Zutaten-Editor (P-8) --}}
    <livewire:foodalchemist.recipes.ingredient-editor />

    {{-- M4-14: Generator --}}
    <livewire:foodalchemist.recipes.generator-modal />

    {{-- M5-07: Aroma-Netz-Graph (innerhalb x-ui-page, P-2) --}}
    <livewire:foodalchemist.recipes.aroma-netz-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between -mb-2">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('recipe-modal.oeffnen')" class="{{ $btnPrimary }}" data-rezept-anlegen>+ Neues Basisrezept</button>
                <button type="button" wire:click="$dispatch('generator-modal.oeffnen')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-generator-oeffnen>✨ KI-Rezept</button>
            </div>
            @if($bulkRunId !== null)
                @php($bulkSvc = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class))
                @php($run = $bulkSvc->status(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId))
                @if($run !== null)
                    <div class="flex items-center gap-2" @if($run->status === 'running') wire:poll.2s @endif data-bulk-progress>
                        @if($run->status === 'running')
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">✨ Bulk läuft … {{ $run->done }}/{{ $run->total }}</span>
                        @else
                            <span class="{{ $pill }} {{ $variantPill['success'] }}">✨ Bulk fertig: {{ $run->done }}/{{ $run->total }}{{ $run->fehler > 0 ? " · {$run->fehler} Fehler" : '' }}</span>
                            <span class="text-xs text-gray-500">{{ $bulkSvc->offeneVorschlaege(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId) }} Vorschläge offen</span>
                            <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-bulk-alle-uebernehmen>Alle übernehmen</button>
                            <button type="button" wire:click="bulkSchliessen" class="{{ $btnGhostXs }}" title="Vorschläge bleiben offen (Review)">Schließen</button>
                        @endif
                    </div>
                @endif
            @endif
            @if(count(array_filter($auswahl)) > 0)
                <div class="flex items-center gap-1.5" data-bulk-status>
                    <button type="button" wire:click="bulkAnreichern" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Beschreibung · Kategorie · Geschmack als Review-Vorschläge (GL-07: nie Auto-Persistenz)" data-bulk-anreichern>✨ Bulk anreichern</button>
                    <span class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ count(array_filter($auswahl)) }} ausgewählt:</span>
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
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-xs uppercase tracking-wider text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    <th class="{{ $th }} !pr-0 w-8"></th>
                    @foreach(['Name', 'Kategorie', 'Geschmack', 'Fertigung', 'Status', 'Zutaten', 'Yield', 'Allergen-Konf.'] as $head)
                        <th class="{{ $th }}">{{ $head }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($rezepte as $r)
                        <tr wire:key="r-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                            class="{{ $tr }} cursor-pointer {{ $recipeId === $r->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}"
                            data-rezept-zeile="{{ $r->id }}">
                            <td class="{{ $td }} !pr-0" wire:click.stop>
                                <input type="checkbox" wire:model.live="auswahl.{{ $r->id }}" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-rezept-checkbox="{{ $r->id }}" />
                            </td>
                            <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100 max-w-sm truncate" title="{{ $r->name }}">{{ $r->name }}</td>
                            <td class="{{ $td }} text-gray-500 truncate max-w-[12rem]">{{ $r->kategorie?->bezeichnung ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $r->geschmacksrichtung ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $r->fertigungstiefe ?? '—' }}</td>
                            <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $statusPill['merged'] ?? $variantPill['secondary'] }}">{{ $r->status->label() }}</span></td>
                            <td class="{{ $td }} text-gray-500">
                                {{ $r->n_zutaten_total }}
                                @if($r->n_zutaten_ungemappt > 0)<span class="{{ $pill }} {{ $variantPill['warning'] }} ml-1" title="ungemappte Zutaten — F7.1: Allergene unbekannt">{{ $r->n_zutaten_ungemappt }}?</span>@endif
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $r->yield_kg !== null ? number_format((float) $r->yield_kg, 3, ',', '.') . ' kg' : '—' }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergene_konfidenz] ?? $variantPill['secondary'] }}">{{ $r->allergene_konfidenz }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-5 py-10 text-center text-gray-400">Keine Rezepte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
