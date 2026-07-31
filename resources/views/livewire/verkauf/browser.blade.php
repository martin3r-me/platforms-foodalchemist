{{-- M6-03: VK-Browser (D-6 §4.1) — VK-Hauptgruppen [Codes] links, Marge-Spalten, Panel rechts --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Gerichte" icon="heroicon-o-banknotes" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Gerichte'],
        ]" />
    </x-slot>

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
                {{-- Geschmacks-Pills (13_REFERENZ) — Labels zentral, keine Doppelpflege (MVP-024) --}}
                <div class="flex gap-1.5" data-geschmack-pills>
                    @foreach(['suess', 'herzhaft', 'neutral'] as $wert)
                        <button type="button" wire:click="waehleGeschmack('{{ $wert }}')"
                                class="{{ $pill }} transition-colors {{ $geschmack === $wert ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($wert) }}</button>
                    @endforeach
                </div>
                {{-- Baum-Ansicht (2026-07-06, User-Wunsch — Parität zum Basisrezept-Browser):
                     Diät-Klassen sind die aufklappbare Ebene unter dem AKTIVEN Knoten.
                     „Alle Hauptgruppen" offen → globale Klassen-Counts; HG offen → auf die HG gescoped. --}}
                <x-foodalchemist::filter-row wire:click="waehleHauptgruppe(null)" :active="$hauptgruppe === null"
                    :count="$gesamtCount" data-gesamt-count><span class="font-medium">Alle Hauptgruppen</span></x-foodalchemist::filter-row>
                @if($hauptgruppe === null)
                    <x-foodalchemist::filter-ast data-vk-klassen-ast>
                        @foreach($klassen as $k)
                            <x-foodalchemist::filter-row level="child" wire:key="vkk-alle-{{ $k->id }}"
                                wire:click="waehleKlasse({{ $k->id }})"
                                :active="$klasse === $k->id"
                                :count="$klassenCounts[$k->id] ?? 0">{{ $k->label }}</x-foodalchemist::filter-row>
                        @endforeach
                    </x-foodalchemist::filter-ast>
                @endif

                <div class="space-y-0.5 -mx-1" data-vk-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="vkhg-{{ $hg->id }}">
                            <x-foodalchemist::filter-row wire:click="waehleHauptgruppe({{ $hg->id }})"
                                :active="$hauptgruppe === $hg->id" :child-active="$klasse !== null"
                                :count="$hgCounts[$hg->id] ?? 0"><span class="font-mono text-[10px] text-gray-500 mr-1">[{{ $hg->code }}]</span>{{ $hg->label }}</x-foodalchemist::filter-row>
                            @if($hauptgruppe === $hg->id)
                                <x-foodalchemist::filter-ast data-vk-klassen-ast>
                                    @foreach($klassen as $k)
                                        @if(($klassenCounts[$k->id] ?? 0) > 0 || $klasse === $k->id)
                                            <x-foodalchemist::filter-row level="child" wire:key="vkk-{{ $hg->id }}-{{ $k->id }}"
                                wire:click="waehleKlasse({{ $k->id }})"
                                :active="$klasse === $k->id"
                                :count="$klassenCounts[$k->id] ?? 0">{{ $k->label }}</x-foodalchemist::filter-row>
                                        @endif
                                    @endforeach
                                </x-foodalchemist::filter-ast>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_verkauf" side="right">
            <livewire:foodalchemist.verkauf.detail-panel :recipe-id="$recipeId" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- M6-04: VK-Editor + geteilter Zutaten-Editor (P-2: innerhalb x-ui-page) --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    <livewire:foodalchemist.verkauf.vk-generator-modal />
    <livewire:foodalchemist.recipes.ingredient-editor />
    <livewire:foodalchemist.recipes.pairing-netz-modal />
    {{-- R7-Fix: Sprung-Ziele des Zutaten-Editors als Modals (GP + Basisrezept) --}}
    <livewire:foodalchemist.gps.gp-modal />
    <livewire:foodalchemist.recipes.recipe-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('vk-modal.oeffnen')" class="{{ $btnPrimary }}" data-vk-anlegen>+ Neues Gericht</button>
                <button type="button" wire:click="$dispatch('vk-generator-modal.oeffnen')" class="{{ $btnGhostXs }} text-violet-600" data-vk-generator>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Rezept</button>
            </div>
            <p class="text-[11px] text-gray-500">Speisen mit VK-Preis. Zutaten = Grundprodukte und/oder Basisrezepte. Live-Marge aus EK × Aufschlagsklasse.</p>
        </div>
        <div class="relative overflow-hidden {{ $card }}" data-vk-tabelle>
            <div class="{{ $cardAccent }}"></div>
            {{-- MVP-024: Statuswechsel-Fehler sichtbar statt still verschluckt --}}
            @if($statusFehler !== null)
                <div class="mx-5 mt-4 rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-2 text-xs text-rose-700" data-status-fehler>{{ $statusFehler }}</div>
            @endif
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Gerichte</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ number_format($rezepte->total(), 0, ',', '.') }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-[11px] uppercase tracking-wider text-gray-500 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <div class="overflow-x-auto">{{-- R13: schmaler Mittelteil scrollt statt abzuschneiden --}}
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    {{-- R13 (Jarvis-Dichte): Name flexibel, Geld/Zahlen rechtsbündig --}}
                    @foreach([['Name', 'w-full'], ['Klasse', ''], ['Geschmack', ''], ['Status', ''], ['VK netto', 'text-right'], ['EK', 'text-right'], ['Zutaten', 'text-right'], ['Allergen-Konf.', ''], ['Hauptgruppe', '']] as [$head, $align])
                        <th class="{{ $th }} {{ $align }}">{{ $head }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($rezepte as $r)
                        <x-foodalchemist::table-row :active="$recipeId === $r->id" wire:key="vk-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity_verkauf', 'open', true)"
                            data-vk-zeile="{{ $r->id }}">
                            {{-- R6: Namens-Klick öffnet direkt den VK-Editor --}}
                            <td class="{{ $td }} font-medium w-full min-w-[24rem] whitespace-normal break-words" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }} — Klick: bearbeiten">
                                <span class="text-gray-900 hover:text-violet-600 hover:underline cursor-pointer" data-vk-name>{{ $r->name }}</span>
                            </td>
                            <td class="{{ $td }} text-[11px] italic text-gray-600 whitespace-nowrap">{{ $r->dishClass?->label ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-600 whitespace-nowrap">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($r->taste_direction) }}</td>
                            {{-- Inline-Status-Pflege wie bei GP (Kuratoren; Stub bleibt Badge) --}}
                            <td class="{{ $td }} whitespace-nowrap" wire:click.stop @click.stop>
                                @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $r) && $r->status !== \Platform\FoodAlchemist\Enums\RecipeStatus::Stub)
                                    <select wire:key="vst-{{ $r->id }}-{{ $r->status->value }}" wire:change="statusSetzen({{ $r->id }}, $event.target.value)"
                                            class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6" data-status-select>
                                        @foreach([\Platform\FoodAlchemist\Enums\RecipeStatus::Draft, \Platform\FoodAlchemist\Enums\RecipeStatus::Review, \Platform\FoodAlchemist\Enums\RecipeStatus::Approved, \Platform\FoodAlchemist\Enums\RecipeStatus::Deprecated] as $fall)
                                            <option value="{{ $fall->value }}" @selected($r->status === $fall)>{{ $fall->label() }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="{{ $pill }} font-medium {{ $statusPill[$r->status->value] ?? $variantPill['secondary'] }}">{{ $r->status->label() }}</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-gray-900 whitespace-nowrap text-right tabular-nums">{{ $r->sales_net !== null ? number_format((float) $r->sales_net, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-600 whitespace-nowrap text-right tabular-nums">{{ $r->ek_total_eur !== null ? number_format((float) $r->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-600 text-right tabular-nums">{{ $r->n_ingredients_total }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergens_confidence] ?? $variantPill['secondary'] }}">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($r->allergens_confidence) }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-600 whitespace-nowrap">{{ $r->dishMainGroup?->code ?? '—' }}</td>
                        </x-foodalchemist::table-row>
                    @empty
                        <tr><td colspan="9" class="px-5 py-10 text-center text-gray-500">Keine Gerichte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
