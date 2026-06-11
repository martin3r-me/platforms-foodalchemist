{{-- M3-01/02: GP-Browser-Neubau (P-1/Screen 1) — Baum links (Page-Sidebar), dichte Tabelle, Panel rechts (M3-03) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Grundprodukte" icon="heroicon-o-cube" />
    </x-slot:navbar>

    {{-- Zone links: Suche · Status · WG-Baum mit Counts · Sub-Kategorien (Platzierungs-Entscheid) --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Warengruppen" width="w-80">
            <div class="p-3 space-y-2" data-gp-baum>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="GP-Name oder Hauptzutat-Slug …" class="{{ $input }}" data-gp-suche />
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="">Alle Status</option>
                    @foreach($statusFaelle as $fall)
                        <option value="{{ $fall->value }}">{{ $fall->label() }} ({{ $statusCounts[$fall->value] ?? 0 }})</option>
                    @endforeach
                </select>

                <button type="button" wire:click="waehleWg('')"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-all duration-150 {{ $warengruppe === ''
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Warengruppen</span>
                    <span class="text-xs text-gray-400">{{ number_format(array_sum($wgCounts), 0, ',', '.') }}</span>
                </button>

                <div class="space-y-0.5 -mx-1" data-wg-liste>
                    @foreach($warengruppen as $wg)
                        <div wire:key="wg-{{ $wg->code }}">
                            <button type="button" wire:click="waehleWg('{{ $wg->code }}')"
                                    class="w-full flex items-center justify-between px-2 py-1 rounded-lg text-sm transition-all duration-150 {{ $warengruppe === $wg->code
                                        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                        : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                <span class="min-w-0 truncate">{{ $wg->code }} {{ $wg->name }}</span>
                                <span class="text-xs text-gray-400 shrink-0 ml-2">{{ $wgCounts[$wg->code] ?? 0 }}</span>
                            </button>
                            @if($warengruppe === $wg->code && count($subCounts) > 0)
                                <div class="ml-4 mt-0.5 space-y-0.5" data-sub-liste>
                                    @foreach($subCounts as $sub => $n)
                                        <button type="button" wire:key="sub-{{ md5($sub) }}" wire:click="waehleSub('{{ addslashes($sub) }}')"
                                                class="w-full flex items-center justify-between px-2 py-0.5 rounded text-xs transition-all duration-150 {{ $subKategorie === $sub
                                                    ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                    : 'text-gray-500 dark:text-gray-400 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                                            <span class="min-w-0 truncate">{{ $sub }}</span>
                                            <span class="text-gray-400 shrink-0 ml-2">{{ $n }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Zone rechts: DetailPanel (M3-03) --}}
    <x-slot name="activity">
        {{-- storeKey 'activityOpen' = der einzige rechte Store-Scope der UI-Sidebar (eigene Keys kollidieren mit links) --}}
        <x-ui-page-sidebar title="Detail" width="w-96" :maxWidth="760" storeKey="activityOpen" side="right">
            <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" />
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex justify-end -mb-2"><x-foodalchemist::kpi-bar :kpis="$kpis" /></div>

        <div class="relative overflow-hidden {{ $card }}" data-gp-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">
                    Grundprodukte
                    @if($warengruppe !== '')<span class="text-gray-400 font-normal">· {{ $warengruppe }}{{ $subKategorie !== '' ? ' · ' . $subKategorie : '' }}</span>@endif
                </h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ number_format($gps->total(), 0, ',', '.') }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-xs uppercase tracking-wider text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <table class="{{ $table }}">
                <thead><tr class="text-left">
                    @foreach(['Name', 'Warengruppe', 'Status', 'LAs', 'Lead-Preis', 'Rezepte', 'Allergene'] as $head)
                        <th class="{{ $th }}">{{ $head }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($gps as $gp)
                        <tr wire:key="gp-{{ $gp->id }}" wire:click="waehleGp({{ $gp->id }})"
                            class="{{ $tr }} cursor-pointer {{ $gpId === $gp->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}"
                            data-gp-zeile="{{ $gp->id }}">
                            <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100 max-w-sm truncate" title="{{ $gp->name }}">
                                {{ $gp->name }}
                                @if($gp->is_derivat)<span class="ml-1.5 {{ $pill }} {{ $variantPill['info'] }}">Derivat</span>@endif
                            </td>
                            <td class="{{ $td }} text-gray-500">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</td>
                            <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span></td>
                            <td class="{{ $td }}">
                                @if($gp->n_las_total > 0)<span class="text-gray-500">{{ $gp->n_las_total }}</span>
                                @elseif(!$gp->requires_la)<span class="text-gray-400" title="bewusst LA-frei">n/a</span>
                                @else<span class="{{ $pill }} font-medium {{ $variantPill['warning'] }}" title="kein LA verknüpft — EK-/Allergen-Lücke">0</span>@endif
                            </td>
                            <td class="{{ $td }} whitespace-nowrap" data-lead-preis>
                                @if($gp->lead_vergleichspreis)
                                    <span class="text-gray-900 dark:text-gray-100">{{ number_format($gp->lead_vergleichspreis['wert'], 2, ',', '.') }} {{ $gp->lead_vergleichspreis['einheit'] }}</span>
                                @elseif($gp->lead_preis !== null)
                                    <span class="text-gray-500" title="Gebinde-Preis — kein Vergleichspreis (qty fehlt, GL-03 A-2)">{{ number_format((float) $gp->lead_preis, 2, ',', '.') }} €</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-gray-500">{{ $gp->rezepte_count ?? '—' }}</td>
                            <td class="{{ $td }}" data-allergen-badges>
                                {{-- GL-01: vorerst Override-Layer; Effektivwerte (LA-MAX) kommen mit M3-04 --}}
                                @php($badges = collect(\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS)
                                    ->filter(fn ($f) => $gp->getAttribute("allergen_{$f}") === 'enthalten')->take(5))
                                @forelse($badges as $feld)
                                    <span class="{{ $pill }} {{ $variantPill['danger'] }} mr-1" title="{{ $feld }}">{{ ucfirst(explode('_', $feld)[0]) }}</span>
                                @empty
                                    <span class="text-gray-400">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-400">Keine Grundprodukte gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $gps->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
