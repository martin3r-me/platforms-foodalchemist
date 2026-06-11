{{-- M2-01/02/03: Lieferanten-Browser (P-7) — Liste links (Page-Sidebar, Platzierungs-Entscheid), dichte Artikel-Tabelle --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Lieferanten" icon="heroicon-o-truck" />
    </x-slot:navbar>

    {{-- Zone links: Lieferanten-Liste mit P-7-Zählern --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Lieferanten" width="w-96" storeKey="faSuppliersOpen">
            <div class="p-3 space-y-2" data-supplier-liste>
                <input type="search" wire:model.live.debounce.300ms="q"
                       placeholder="Artikel-Suche über ALLE Lieferanten …" class="{{ $input }}" data-global-suche />
                <input type="search" wire:model.live.debounce.300ms="supplierSuche"
                       placeholder="Lieferant filtern …" class="{{ $input }}" />
                <label class="flex items-center gap-2 {{ $label }} cursor-pointer px-1">
                    <input type="checkbox" wire:model.live="includeInactive" class="rounded border-gray-300" /> Inaktive zeigen
                </label>
                <div class="space-y-0.5 -mx-1">
                    @foreach($lieferanten as $l)
                        <button type="button" wire:key="sup-{{ $l->id }}" wire:click="waehleLieferant({{ $l->id }})"
                                class="w-full px-2 py-1.5 rounded-lg text-left transition-all duration-150 {{ !$globaleSuche && $supplierId === $l->id
                                    ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10'
                                    : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                            <span class="block text-sm {{ $l->is_inactive ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-200' }} truncate">{{ $l->name }}</span>
                            <span class="block text-xs text-gray-400">{{ number_format($l->item_count, 0, ',', '.') }} Artikel ·
                                <span class="text-emerald-600 dark:text-emerald-400">{{ number_format($l->mapped_count, 0, ',', '.') }} gemapped</span></span>
                        </button>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- Kopf-Aktionen (P-7) --}}
        <div class="flex items-center justify-between gap-3 pt-1">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 min-w-0 truncate">
                @if($globaleSuche) Suche „{{ $q }}" über alle Lieferanten
                @else {{ $aktiverLieferant?->name ?? '—' }}
                @endif
            </h3>
            <div class="flex items-center gap-2 shrink-0">
                <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded border-gray-300" /> Nur aktive
                </label>
                <button type="button" disabled title="Kommt mit M3-11 (Bulk-Match je Lieferant)" class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed">Bulk-Match</button>
                <button type="button" disabled title="Kommt mit M2-12" class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed" data-anomalien-btn>Preis-Anomalien</button>
                <button type="button" disabled title="Kommt mit M2-11" class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed" data-neuer-artikel-btn>+ Neuer Artikel</button>
            </div>
        </div>

        {{-- Artikel-Tabelle (M2-02) --}}
        <div class="relative overflow-hidden {{ $card }}" data-artikel-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Artikel</h3>
                <span class="{{ $label }}">{{ $artikel ? number_format($artikel->total(), 0, ',', '.') : 0 }} Treffer</span>
            </div>
            <table class="{{ $table }}">
                <thead>
                    <tr class="text-left">
                        @if($globaleSuche)<th class="{{ $th }}">Lieferant</th>@endif
                        @foreach(['ArtNr', 'Bezeichnung', 'Gebinde', 'Status', 'EK', 'Vergleichspreis', 'Grundprodukt'] as $head)
                            <th class="{{ $th }}">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($artikel ?? [] as $item)
                        <tr wire:key="item-{{ $item->id }}" class="{{ $tr }}">
                            @if($globaleSuche)
                                <td class="{{ $td }} text-gray-500">{{ $item->supplier?->name ?? '—' }}</td>
                            @endif
                            <td class="{{ $td }} font-mono text-xs text-gray-500">{{ $item->article_number ?? '—' }}</td>
                            <td class="{{ $td }} font-medium max-w-md truncate" title="{{ $item->designation }}">
                                <button type="button" wire:click="$dispatch('item-modal.oeffnen', { id: {{ $item->id }} })"
                                        class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150 truncate max-w-full text-left">{{ $item->designation }}</button>
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">
                                {{ $item->qty !== null ? rtrim(rtrim((string) $item->qty, '0'), '.') : '—' }} {{ $item->unit_code ?? $item->ordering_unit }}
                                @if($item->qty === null)<span class="ml-1 {{ $pill }} {{ $variantPill['warning'] }}" title="Gebinde-Menge fehlt (GL-03 A-2)">qty?</span>@endif
                            </td>
                            <td class="{{ $td }}">
                                @if($item->is_discontinued)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">ausgelistet</span>
                                @else<span class="{{ $pill }} {{ $variantPill['success'] }}">aktiv</span>@endif
                            </td>
                            <td class="{{ $td }} text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                {{ $item->aktiver_preis !== null ? number_format((float) $item->aktiver_preis, 2, ',', '.') . ' €' : '—' }}
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap" data-vergleichspreis>
                                {{ $item->vergleichspreis !== null ? number_format($item->vergleichspreis['wert'], 2, ',', '.') . ' ' . $item->vergleichspreis['einheit'] : '—' }}
                            </td>
                            <td class="{{ $td }}">
                                @if($item->structure?->gp)
                                    <a href="{{ route('foodalchemist.gps.show', $item->structure->gp_id) }}"
                                       class="text-violet-600 dark:text-violet-400 hover:underline">{{ $item->structure->gp->name }}</a>
                                @else
                                    <span class="text-gray-400">— nicht gemappt —</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-5 py-10 text-center text-gray-400">Keine Artikel gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($artikel)
                <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $artikel->links() }}</div>
            @endif
        </div>
        {{-- LA-Editor-Modal (M2-06/07/08) — innerhalb x-ui-page (Template-Regel) --}}
        <livewire:foodalchemist.suppliers.item-modal />
    </x-ui-page-container>
</x-ui-page>
