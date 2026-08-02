{{-- Einkauf E4 — Wareneinsatz-Optimierung: Ist vs. Optimal (Listenpreis / inkl. Rückvergütung).
     Spec 32: von der eigenen Seite `/einkauf/optimierung` zum Panel im Controlling-Tab
     „Wareneinsatz" — Seiten-Hülle entfällt, Titel trägt jetzt der Tab. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €')

<div class="space-y-4" data-ctrl-wareneinsatz>

        @if($r['n_articles'] === 0)
            <div class="{{ $sectionCard }} text-center py-12">
                <div class="text-3xl mb-2">@svg('heroicon-o-chart-bar', 'w-3.5 h-3.5 inline-block align-middle')</div>
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

            {{-- Spec 32 — die Maßnahme zur Analyse: markierte Positionen dauerhaft auf den
                 günstigsten Lieferanten umstellen. Bewusst zweistufig (erst Vorschau, dann
                 ausführen): eine Umstellung verschiebt den EK jedes Rezepts, in dem das
                 Grundprodukt steckt — das darf niemand aus Versehen auslösen. --}}
            <div class="{{ $sectionCard }}" data-ctrl-batch>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h4 class="text-xs font-medium text-gray-900">Bezugsquellen umstellen</h4>
                        <p class="text-[11px] text-gray-500">
                            {{ count($auswahl) }} von {{ collect($r['top'])->where('lead_ist_optimal', false)->count() }} umstellbaren Positionen markiert.
                            Bereits optimal bezogene Zeilen lassen sich nicht markieren.
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" wire:click="alleWaehlen" class="{{ $btnGhostXs }}">Alle umstellbaren</button>
                        <button type="button" wire:click="auswahlLeeren" class="{{ $btnGhostXs }}" @disabled(count($auswahl) === 0)>Auswahl leeren</button>
                        <button type="button" wire:click="vorschau" class="{{ $btnGhostXs }} text-violet-600"
                                data-ctrl-batch-vorschau @disabled(count($auswahl) === 0)>Vorschau</button>
                    </div>
                </div>

                @if($hinweis)<p class="mt-2 text-[11px] text-emerald-700" data-ctrl-batch-hinweis>{{ $hinweis }}</p>@endif
                @if($fehler)<p class="mt-2 text-[11px] text-rose-700" data-ctrl-batch-fehler>{{ $fehler }}</p>@endif

                @if($vorschau)
                    <div class="mt-3 rounded-lg bg-black/[0.03] px-3 py-2" data-ctrl-batch-vorschau-box>
                        <p class="text-xs text-gray-900">
                            <strong>{{ $vorschau['n_gps'] }}</strong> Bezugsquelle(n) umstellen —
                            betrifft <strong>{{ number_format($vorschau['n_rezepte'], 0, ',', '.') }}</strong> Rezept(e),
                            davon <strong>{{ number_format($vorschau['n_gerichte'], 0, ',', '.') }}</strong> Verkaufsgericht(e).
                            Rechnerische Ersparnis auf die Journal-Menge: <strong>{{ $eur($vorschau['ersparnis']) }}</strong>.
                        </p>
                        <ul class="mt-2 space-y-0.5">
                            @foreach($vorschau['gps'] as $g)
                                <li class="text-[11px] text-gray-600">{{ $g['name'] }} → {{ $g['nach'] }} <span class="text-emerald-700 tabular-nums">{{ $eur($g['ersparnis']) }}</span></li>
                            @endforeach
                            @if($vorschau['gekuerzt'] > 0)
                                <li class="text-[11px] text-gray-500">… und {{ $vorschau['gekuerzt'] }} weitere</li>
                            @endif
                        </ul>
                        <button type="button" wire:click="umstellen" wire:loading.attr="disabled"
                                wire:confirm="{{ $vorschau['n_gps'] }} Bezugsquelle(n) umstellen? Der EK wird für {{ $vorschau['n_rezepte'] }} Rezept(e) neu gerechnet."
                                data-ctrl-batch-umstellen
                                class="{{ $btnPrimary }} mt-3">{{ $vorschau['n_gps'] }} umstellen</button>
                        <p class="text-[10px] text-gray-500 mt-1">
                            Die Ersparnis ist eine Rückrechnung auf die bereits eingekaufte Menge — sie sagt,
                            was der Bezug gekostet hätte, nicht was künftig sicher gespart wird.
                        </p>
                    </div>
                @endif
            </div>

            {{-- Top-Einsparpotenziale --}}
            <div class="{{ $sectionCard }} !p-0 overflow-x-auto">
                <table class="w-full text-xs" data-optimierung-top>
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-black/10">
                            <th class="px-3 py-2 font-medium w-8"></th>
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
                                <td class="px-3 py-2">
                                    {{-- Bereits optimal bezogene Zeilen tragen keinen Haken: sie würden
                                         beim Umstellen nichts ändern und den Batch nur aufblähen. --}}
                                    @if($z['lead_ist_optimal'])
                                        <span class="text-emerald-600" title="Bezug ist bereits optimal">✓</span>
                                    @else
                                        <input type="checkbox" wire:model.live="auswahl" value="{{ $z['gp_id'] }}"
                                               data-ctrl-batch-pick="{{ $z['gp_id'] }}" />
                                    @endif
                                </td>
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

</div>

