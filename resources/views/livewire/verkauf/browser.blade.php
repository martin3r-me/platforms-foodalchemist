{{-- M6-03: VK-Browser (D-6 §4.1) — VK-Hauptgruppen [Codes] links, Marge-Spalten, Panel rechts --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Verkaufsrezepte" icon="heroicon-o-banknotes" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="VK-Hauptgruppen" width="w-80">
            <div class="p-3 space-y-2" data-vk-baum>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Name, Marketing-Name oder Kunde …" class="{{ $input }}" data-vk-suche />
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="">Alle Status</option>
                    @foreach($statusFaelle as $fall)
                        <option value="{{ $fall->value }}">{{ $fall->label() }} ({{ $statusCounts[$fall->value] ?? 0 }})</option>
                    @endforeach
                </select>
                {{-- Geschmacks-Pills (13_REFERENZ) --}}
                <div class="flex gap-1.5" data-geschmack-pills>
                    @foreach(['suess' => 'Süß', 'herzhaft' => 'Herzhaft', 'neutral' => 'Neutral'] as $wert => $lbl)
                        <button type="button" wire:click="waehleGeschmack('{{ $wert }}')"
                                class="{{ $pill }} transition-colors {{ $geschmack === $wert ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                    @endforeach
                </div>

                <button type="button" wire:click="waehleHauptgruppe(null)"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === null
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Hauptgruppen</span>
                    <span class="text-[11px] text-gray-400">{{ number_format(array_sum($hgCounts), 0, ',', '.') }}</span>
                </button>

                <div class="space-y-0.5 -mx-1" data-vk-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="vkhg-{{ $hg->id }}">
                            <button type="button" wire:click="waehleHauptgruppe({{ $hg->id }})"
                                    class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === $hg->id
                                        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate"><span class="font-mono text-[10px] text-gray-400 mr-1">[{{ $hg->code }}]</span>{{ $hg->bezeichnung }}</span>
                                <span class="text-[11px] text-gray-400 shrink-0 ml-2">{{ $hgCounts[$hg->id] ?? 0 }}</span>
                            </button>
                            @if($hauptgruppe === $hg->id && $klassen->isNotEmpty())
                                <div class="ml-4 mt-0.5 space-y-0.5" data-vk-klassen-liste>
                                    @foreach($klassen as $k)
                                        @if(($klassenCounts[$k->id] ?? 0) > 0)
                                            <button type="button" wire:key="vkk-{{ $k->id }}" wire:click="waehleKlasse({{ $k->id }})"
                                                    class="w-full flex items-center justify-between px-2 py-0.5 rounded text-[11px] transition-all duration-150 {{ $klasse === $k->id
                                                        ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                        : 'text-gray-500 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                                <span class="min-w-0 truncate">{{ $k->bezeichnung }}</span>
                                                <span class="text-gray-400 shrink-0 ml-2">{{ $klassenCounts[$k->id] }}</span>
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
            <livewire:foodalchemist.verkauf.detail-panel :recipe-id="$recipeId" />
        </x-ui-page-sidebar>
    </x-slot>

    {{-- M6-04: VK-Editor + geteilter Zutaten-Editor (P-2: innerhalb x-ui-page) --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    <livewire:foodalchemist.verkauf.vk-generator-modal />
    <livewire:foodalchemist.recipes.ingredient-editor />
    <livewire:foodalchemist.recipes.aroma-netz-modal />
    {{-- R7-Fix: Sprung-Ziele des Zutaten-Editors als Modals (GP + Basisrezept) --}}
    <livewire:foodalchemist.gps.gp-modal />
    <livewire:foodalchemist.recipes.recipe-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between -mb-2">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('vk-modal.oeffnen')" class="{{ $btnPrimary }}" data-vk-anlegen>+ Neues Verkaufsrezept</button>
                <button type="button" wire:click="$dispatch('vk-generator-modal.oeffnen')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-vk-generator>✨ KI-Rezept</button>
            </div>
            <p class="text-[11px] text-gray-400">Speisen mit VK-Preis. Zutaten = Grundprodukte und/oder Basisrezepte. Live-Marge aus EK × Aufschlagsklasse.</p>
        </div>
        <div class="relative overflow-hidden {{ $card }}" data-vk-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Verkaufsrezepte</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ number_format($rezepte->total(), 0, ',', '.') }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-[11px] uppercase tracking-wider text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <div class="overflow-x-auto">{{-- R13: schmaler Mittelteil scrollt statt abzuschneiden --}}
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    {{-- R13 (Jarvis-Dichte): Name flexibel, Geld/Zahlen rechtsbündig --}}
                    @foreach([['Name', 'w-full'], ['Hauptgruppe', ''], ['Klasse', ''], ['Geschmack', ''], ['Status', ''], ['VK netto', 'text-right'], ['EK', 'text-right'], ['Zutaten', 'text-right'], ['Allergen-Konf.', '']] as [$head, $align])
                        <th class="{{ $th }} {{ $align }}">{{ $head }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($rezepte as $r)
                        <tr wire:key="vk-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity', 'open', true)"
                            class="{{ $tr }} cursor-pointer {{ $recipeId === $r->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}"
                            data-vk-zeile="{{ $r->id }}">
                            {{-- R6: Namens-Klick öffnet direkt den VK-Editor --}}
                            <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }} — Klick: bearbeiten">
                                <span class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 hover:underline cursor-pointer" data-vk-name>{{ $r->name }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $r->speisenKlasse?->hauptgruppe?->code ?? '—' }}</td>
                            <td class="{{ $td }} text-[11px] italic text-gray-500 truncate max-w-[10rem] whitespace-nowrap">{{ $r->speisenKlasse?->bezeichnung ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $r->geschmacksrichtung ?? '—' }}</td>
                            <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }}">{{ $r->status->label() }}</span></td>
                            <td class="{{ $td }} text-gray-900 dark:text-gray-100 whitespace-nowrap text-right tabular-nums">{{ $r->vk_netto !== null ? number_format((float) $r->vk_netto, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap text-right tabular-nums">{{ $r->ek_total_eur !== null ? number_format((float) $r->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-500 text-right tabular-nums">{{ $r->n_zutaten_total }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergene_konfidenz] ?? $variantPill['secondary'] }}">{{ $r->allergene_konfidenz }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-5 py-10 text-center text-gray-400">Keine Verkaufsrezepte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
