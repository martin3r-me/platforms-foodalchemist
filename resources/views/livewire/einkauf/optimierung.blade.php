{{-- Einkauf E4 — Wareneinsatz-Optimierung: Ist vs. Optimal (Listenpreis / inkl. Rückvergütung). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €')

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Einkauf · Wareneinsatz-Optimierung" icon="heroicon-o-sparkles" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einkauf · Wareneinsatz-Optimierung'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($r['n_articles'] === 0)
            <div class="{{ $sectionCard }} text-center py-12">
                <div class="text-3xl mb-2">📊</div>
                <p class="text-sm text-gray-900 font-medium">Noch keine Einkäufe im Journal</p>
                <p class="text-xs text-gray-500 mt-1">Sobald FA-Bestellungen geliefert oder Necta-Einkäufe importiert sind, vergleicht diese Seite den Ist-Wareneinsatz mit dem optimalen Bezug (günstigster Lieferant, ± Rückvergütung).</p>
            </div>
        @else
            {{-- KPI-Karten: Ist vs. zwei Optima --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="{{ $sectionCard }}">
                    <p class="{{ $label }}">Ist-Wareneinsatz</p>
                    <p class="text-2xl font-semibold text-gray-900 tabular-nums mt-1">{{ $eur($r['ist_total']) }}</p>
                    <p class="text-[11px] text-gray-500 mt-1">tatsächlich bezahlt ({{ $r['n_articles'] }} Artikel)</p>
                </div>
                <div class="{{ $sectionCard }} !bg-emerald-500/[0.04] !border-emerald-500/20">
                    <p class="{{ $label }}">Optimal · Listenpreis</p>
                    <p class="text-2xl font-semibold text-emerald-700 tabular-nums mt-1">{{ $eur($r['optimal_list_total']) }}</p>
                    <p class="text-[11px] text-emerald-700 mt-1">−{{ $eur($r['saving_list']) }}@if($r['saving_list_pct'] !== null) · {{ number_format($r['saving_list_pct'], 1, ',', '.') }} %@endif günstigster Lieferant</p>
                </div>
                <div class="{{ $sectionCard }} !bg-violet-500/[0.04] !border-violet-500/20">
                    <p class="{{ $label }}">Optimal · inkl. Rückvergütung</p>
                    <p class="text-2xl font-semibold text-violet-700 tabular-nums mt-1">{{ $eur($r['optimal_rebate_total']) }}</p>
                    <p class="text-[11px] text-violet-700 mt-1">−{{ $eur($r['saving_rebate']) }}@if($r['saving_rebate_pct'] !== null) · {{ number_format($r['saving_rebate_pct'], 1, ',', '.') }} %@endif effektiver Netto-Preis</p>
                </div>
            </div>

            @if($r['n_skipped'] > 0)
                <p class="text-[11px] text-amber-700">{{ $r['n_skipped'] }} Position(en) ohne vergleichbaren Alternativpreis — nicht in die Summen einbezogen.</p>
            @endif

            {{-- Was-wäre-wenn: Lieferant ausklammern --}}
            <div class="{{ $sectionCard }}">
                <p class="{{ $label }} mb-2">Lieferant ausklammern (Was-wäre-wenn)</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-1">
                    @foreach($lieferanten as $l)
                        <label class="inline-flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model.live="excludeSupplierIds" value="{{ $l->id }}" />
                            <span class="truncate" title="{{ $l->name }}">{{ $l->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Top-Einsparpotenziale --}}
            <div class="{{ $sectionCard }} !p-0 overflow-x-auto">
                <table class="w-full text-xs" data-optimierung-top>
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-black/10">
                            <th class="px-3 py-2 font-medium">Grundprodukt</th>
                            <th class="px-3 py-2 font-medium text-right">Menge</th>
                            <th class="px-3 py-2 font-medium text-right">Ist</th>
                            <th class="px-3 py-2 font-medium text-right">Optimal (RV)</th>
                            <th class="px-3 py-2 font-medium text-right">Ersparnis</th>
                            <th class="px-3 py-2 font-medium">Günstigster (RV)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($r['top'] as $z)
                            <tr wire:key="opt-{{ $z['gp_id'] }}" class="border-b border-black/5">
                                <td class="px-3 py-2 text-gray-900">{{ $z['name'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ number_format($z['qty'], 2, ',', '.') }} {{ $z['unit'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $eur($z['ist']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-violet-700">{{ $eur($z['optimal_rebate']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-medium {{ $z['saving_rebate'] > 0 ? 'text-emerald-700' : 'text-gray-400' }}">{{ $eur($z['saving_rebate']) }}</td>
                                <td class="px-3 py-2"><span class="{{ $pill }} {{ $variantPill['success'] }}">{{ $z['cheapest_rebate_supplier'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </x-ui-page-container>
</x-ui-page>
