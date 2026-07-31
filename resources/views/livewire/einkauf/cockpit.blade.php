{{-- Einkauf E3 — Einkaufs-Cockpit: Cross-Lieferanten-Preisvergleich (Such-first). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Einkauf · Preisvergleich" icon="heroicon-o-scale" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einkauf · Preisvergleich'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- Filterleiste --}}
        <div class="{{ $sectionCard }}">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <label class="block {{ $label }} mb-1">Suche (Grundprodukt)</label>
                    <input type="search" wire:model.live.debounce.300ms="q" placeholder="z. B. Kartoffel, Rind …" class="{{ $input }}" data-einkauf-suche />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Warengruppe</label>
                    <select wire:model.live="wgCode" class="{{ $input }}">
                        <option value="">— alle —</option>
                        @foreach($warengruppen as $wg)
                            <option value="{{ $wg->code }}">{{ $wg->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Lieferant</label>
                    <select wire:model.live="supplierId" class="{{ $input }}">
                        <option value="">— alle —</option>
                        @foreach($lieferanten as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-xs text-gray-700 pb-2">
                        <input type="checkbox" wire:model.live="mitRabatt" data-einkauf-rabatt /> inkl. Rückvergütung
                    </label>
                </div>
            </div>
            @if($mitRabatt)
                <p class="text-[11px] text-gray-500 mt-2">Preise als <strong>effektiver Netto-Preis</strong> nach Rückvergütung (rückwirkender Jahresbonus) — nicht der gebuchte Bestellpreis.</p>
            @endif
        </div>

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700" data-einkauf-hinweis>✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700" data-einkauf-fehler>{{ $fehler }}</div>@endif

        @if(! $aktiv)
            {{-- Leerzustand (Such-first) --}}
            <div class="{{ $sectionCard }} text-center py-12">
                <div class="text-3xl mb-2">@svg('heroicon-o-calculator', 'w-3.5 h-3.5 inline-block align-middle')</div>
                <p class="text-sm text-gray-900 font-medium">Preise über alle Lieferanten vergleichen</p>
                <p class="text-xs text-gray-500 mt-1">Suche ein Grundprodukt oder filtere nach Warengruppe/Lieferant — je Produkt siehst du den günstigsten und teuersten Lieferanten samt Spanne.</p>
            </div>
        @elseif(count($zeilen) === 0)
            <div class="{{ $sectionCard }} text-center py-10">
                <p class="text-sm text-gray-900 font-medium">Keine bepreisten Treffer</p>
                <p class="text-xs text-gray-500 mt-1">Für die aktuelle Auswahl gibt es keine Grundprodukte mit Vergleichspreis.</p>
            </div>
        @else
            <div class="{{ $sectionCard }} !p-0 overflow-x-auto">
                <table class="w-full text-xs" data-einkauf-tabelle>
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-black/10">
                            <th class="px-3 py-2 font-medium">Grundprodukt</th>
                            <th class="px-3 py-2 font-medium">WG</th>
                            <th class="px-3 py-2 font-medium">Günstigster</th>
                            <th class="px-3 py-2 font-medium text-right">€ / Einheit</th>
                            <th class="px-3 py-2 font-medium">Teuerster</th>
                            <th class="px-3 py-2 font-medium text-right">€ / Einheit</th>
                            <th class="px-3 py-2 font-medium text-right">Spanne</th>
                            <th class="px-3 py-2 font-medium text-right">Lief.</th>
                            @if($supplierId)<th class="px-3 py-2 font-medium text-right">gefilt. Lief.</th>@endif
                            <th class="px-3 py-2 font-medium text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($zeilen as $z)
                            <tr wire:key="einkauf-{{ $z['gp_id'] }}" class="border-b border-black/5 hover:bg-black/[0.02]">
                                <td class="px-3 py-2 text-gray-900">{{ $z['name'] }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $z['wg'] ?: '—' }}</td>
                                <td class="px-3 py-2"><span class="{{ $pill }} {{ $variantPill['success'] }}">{{ $z['guenstigster_supplier'] }}</span></td>
                                <td class="px-3 py-2 text-right tabular-nums font-medium text-emerald-700">{{ number_format($z['guenstigster_preis'], 2, ',', '.') }} €</td>
                                <td class="px-3 py-2"><span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $z['teuerster_supplier'] }}</span></td>
                                <td class="px-3 py-2 text-right tabular-nums text-rose-700">{{ number_format($z['teuerster_preis'], 2, ',', '.') }} €</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $z['spanne_pct'] !== null ? '+' . number_format($z['spanne_pct'], 0, ',', '.') . ' %' : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $z['n'] }}</td>
                                @if($supplierId)
                                    <td class="px-3 py-2 text-right tabular-nums {{ $z['filter_supplier_ist_guenstigster'] ? 'text-emerald-700 font-medium' : 'text-gray-700' }}">
                                        {{ $z['filter_supplier_preis'] !== null ? number_format($z['filter_supplier_preis'], 2, ',', '.') . ' €' : '—' }}
                                        @if($z['filter_supplier_ist_guenstigster']) ★@endif
                                    </td>
                                @endif
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button type="button" wire:click="uebernehmen({{ $z['guenstigster_la_id'] }})"
                                            class="{{ $btnGhostXs }} text-violet-600" title="Günstigsten Lieferantenartikel in die Bestellschiene übernehmen">→ Schiene</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($gekappt)
                <p class="text-[11px] text-amber-700">Nur die ersten {{ $max }} Treffer gezeigt — enger filtern für den Rest.</p>
            @endif
        @endif

    </x-ui-page-container>
</x-ui-page>
