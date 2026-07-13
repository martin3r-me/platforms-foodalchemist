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
                {{-- Geschmacks-Pills (13_REFERENZ) --}}
                <div class="flex gap-1.5" data-geschmack-pills>
                    @foreach(['suess' => 'Süß', 'herzhaft' => 'Herzhaft', 'neutral' => 'Neutral'] as $wert => $lbl)
                        <button type="button" wire:click="waehleGeschmack('{{ $wert }}')"
                                class="{{ $pill }} transition-colors {{ $geschmack === $wert ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                    @endforeach
                </div>
                {{-- Baum-Ansicht (2026-07-06, User-Wunsch — Parität zum Basisrezept-Browser):
                     Diät-Klassen sind die aufklappbare Ebene unter dem AKTIVEN Knoten.
                     „Alle Hauptgruppen" offen → globale Klassen-Counts; HG offen → auf die HG gescoped. --}}
                <button type="button" wire:click="waehleHauptgruppe(null)"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === null
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Hauptgruppen</span>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ number_format(array_sum($hgCounts), 0, ',', '.') }}</span>
                </button>
                @if($hauptgruppe === null)
                    <div class="ml-4 -mt-1 space-y-0.5" data-vk-klassen-ast>
                        @foreach($klassen as $k)
                            <button type="button" wire:key="vkk-alle-{{ $k->id }}" wire:click="waehleKlasse({{ $k->id }})"
                                    class="w-full flex items-center justify-between px-2 py-0.5 rounded text-[11px] transition-all duration-150 {{ $klasse === $k->id
                                        ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate">{{ $k->label }}</span>
                                <span class="text-gray-500 dark:text-gray-400 shrink-0 ml-2">{{ $klassenCounts[$k->id] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-0.5 -mx-1" data-vk-hg-liste>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="vkhg-{{ $hg->id }}">
                            <button type="button" wire:click="waehleHauptgruppe({{ $hg->id }})"
                                    class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppe === $hg->id
                                        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate"><span class="font-mono text-[10px] text-gray-500 dark:text-gray-400 mr-1">[{{ $hg->code }}]</span>{{ $hg->label }}</span>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400 shrink-0 ml-2">{{ $hgCounts[$hg->id] ?? 0 }}</span>
                            </button>
                            @if($hauptgruppe === $hg->id)
                                <div class="ml-4 mt-0.5 space-y-0.5" data-vk-klassen-ast>
                                    @foreach($klassen as $k)
                                        @if(($klassenCounts[$k->id] ?? 0) > 0 || $klasse === $k->id)
                                            <button type="button" wire:key="vkk-{{ $hg->id }}-{{ $k->id }}" wire:click="waehleKlasse({{ $k->id }})"
                                                    class="w-full flex items-center justify-between px-2 py-0.5 rounded text-[11px] transition-all duration-150 {{ $klasse === $k->id
                                                        ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                        : 'text-gray-600 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                                <span class="min-w-0 truncate">{{ $k->label }}</span>
                                                <span class="text-gray-500 dark:text-gray-400 shrink-0 ml-2">{{ $klassenCounts[$k->id] ?? 0 }}</span>
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
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('vk-modal.oeffnen')" class="{{ $btnPrimary }}" data-vk-anlegen>+ Neues Gericht</button>
                <button type="button" wire:click="$dispatch('vk-generator-modal.oeffnen')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-vk-generator>✨ KI-Rezept</button>
            </div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">Speisen mit VK-Preis. Zutaten = Grundprodukte und/oder Basisrezepte. Live-Marge aus EK × Aufschlagsklasse.</p>
        </div>
        <div class="relative overflow-hidden {{ $card }}" data-vk-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Gerichte</h3>
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
                    {{-- R13 (Jarvis-Dichte): Name flexibel, Geld/Zahlen rechtsbündig --}}
                    @foreach([['Name', 'w-full'], ['Klasse', ''], ['Geschmack', ''], ['Status', ''], ['VK netto', 'text-right'], ['EK', 'text-right'], ['Zutaten', 'text-right'], ['Allergen-Konf.', ''], ['Hauptgruppe', ''], ['Feedback', 'text-right']] as [$head, $align])
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
                            <td class="{{ $td }} font-medium w-full min-w-[24rem] whitespace-normal break-words" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }} — Klick: bearbeiten">
                                <span class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 hover:underline cursor-pointer" data-vk-name>{{ $r->name }}</span>
                            </td>
                            <td class="{{ $td }} text-[11px] italic text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $r->dishClass?->label ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $r->taste_direction ?? '—' }}</td>
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
                            <td class="{{ $td }} text-gray-900 dark:text-gray-100 whitespace-nowrap text-right tabular-nums">{{ $r->sales_net !== null ? number_format((float) $r->sales_net, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap text-right tabular-nums">{{ $r->ek_total_eur !== null ? number_format((float) $r->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 text-right tabular-nums">{{ $r->n_ingredients_total }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']][$r->allergens_confidence] ?? $variantPill['secondary'] }}">{{ $r->allergens_confidence }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $r->dishMainGroup?->code ?? '—' }}</td>
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
                        <tr><td colspan="10" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">Keine Gerichte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $rezepte->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
