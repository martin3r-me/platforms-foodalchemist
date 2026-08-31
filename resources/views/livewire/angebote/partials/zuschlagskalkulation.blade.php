{{-- B3 — Zuschlagskalkulation fürs GANZE Angebot × Pax.
     1:1-Fork der Concepter-Kalkulation (resources/views/livewire/concepter/editor.blade.php)
     + dokumente/partials/report-order-simulation.blade.php.
     Datenquelle: $auftragsKalkulation = OrderCostingService::costConcept-Shape, aggregiert
     über alle Concept-Einheiten der Angebot-Komposition × Pax (geliefert von B1/Integration).
     Feldnamen identisch zu costConcept: pax / catalog_price_per_person / mek / fek / hk / hk2 /
     minimum_price / target_price / target_price_per_person / contribution_margin /
     contribution_margin_pct / target_gap / unprofitable / complete / active_person_minutes /
     cost_breakdown[] (key,label,amount,stage) / time_breakdown[] / warnings[].
     Optional (Angebot-Spezifikum, von B1 ergänzt): positionen[] (role,label,ek,price = je Person)
     + ek_per_person + price_per_person für die WARENEINSATZ-JE-POSITION-Tabelle. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($sim = $auftragsKalkulation ?? null)
@if($sim)
    @php($simPax = max(1, (int) ($sim['pax'] ?? 0)))
    @php($simZielPp = (float) ($sim['target_price_per_person'] ?? 0))
    @php($simCatalogPp = (float) ($sim['catalog_price_per_person'] ?? 0))
    @php($simAbweichungPp = $simCatalogPp - $simZielPp)
    @php($simDbPp = (float) ($sim['contribution_margin'] ?? 0) / $simPax)
    <div class="rounded-xl border border-black/5 p-3 space-y-2" data-angebot-zuschlagskalkulation>
        <p class="{{ $label }}">Zuschlagskalkulation · {{ number_format((int) ($sim['pax'] ?? 0), 0, ',', '.') }} Pax</p>
        <p class="text-[11px] text-gray-500">Vollkosten-Kalkulation über das gesamte Angebot (alle Menüs × Pax). Prüft den Katalogpreis, ohne Stammdaten zu verändern.</p>

        {{-- Kopfzeile: Katalog/Person · MEK · FEK · HK2 · Preisempfehlung · Abweichung · Zielpreis · aktive Zeit --}}
        <div class="grid grid-cols-2 md:grid-cols-5 xl:grid-cols-10 gap-2 text-xs" data-auftrag-preisempfehlung>
            <div><span class="block text-[10px] text-gray-500">Katalog / Person</span><span class="font-medium tabular-nums">{{ number_format($simCatalogPp, 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500">MEK Auftrag / Person</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['mek'] ?? 0) / $simPax, 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500">FEK Auftrag / Person</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['fek'] ?? 0) / $simPax, 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500">HK2 / Person</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['hk2'] ?? 0) / $simPax, 2, ',', '.') }} €</span></div>
            <div class="rounded-md bg-violet-500/10 px-2 py-1.5"><span class="block text-[10px] text-violet-500">Preisempfehlung / Person</span><span class="font-semibold text-violet-700 tabular-nums">{{ number_format($simZielPp, 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500" title="Katalogpreis pro Person minus Preisempfehlung pro Person">Abweichung Katalog − Ziel</span><span class="font-medium tabular-nums {{ $simAbweichungPp < 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $simAbweichungPp > 0 ? '+' : '' }}{{ number_format($simAbweichungPp, 2, ',', '.') }} €/P</span></div>
            <div><span class="block text-[10px] text-gray-500">Mindestpreis gesamt</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['minimum_price'] ?? 0), 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500">Zielpreis gesamt</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['target_price'] ?? 0), 2, ',', '.') }} €</span></div>
            <div><span class="block text-[10px] text-gray-500">Deckungsbeitrag Auftrag</span><span class="font-medium tabular-nums {{ ($sim['contribution_margin'] ?? 0) < 0 ? 'text-rose-500' : 'text-emerald-600' }}">{{ number_format($simDbPp, 2, ',', '.') }} €/P <span class="block text-[9px]">{{ number_format((float) ($sim['contribution_margin'] ?? 0), 2, ',', '.') }} € · {{ ($sim['contribution_margin_pct'] ?? null) !== null ? number_format((float) $sim['contribution_margin_pct'], 1, ',', '.') . ' %' : '—' }}</span></span></div>
            <div><span class="block text-[10px] text-gray-500">Aktive Personenzeit</span><span class="font-medium tabular-nums">{{ number_format((float) ($sim['active_person_minutes'] ?? 0) / 60, 2, ',', '.') }} h</span></div>
        </div>

        @if($sim['unprofitable'] ?? false)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Der Katalogpreis liegt {{ number_format((float) ($sim['target_gap'] ?? 0), 2, ',', '.') }} € unter dem Zielpreis. Der Preis wurde nicht automatisch erhöht.
            </div>
        @endif
        @unless($sim['complete'] ?? false)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                Preisempfehlung nicht belastbar: Die Auftragsdaten sind noch unvollständig. Für die Berechnung wird mindestens der ausgewiesene Katalog-MEK verwendet.
            </div>
        @endunless
        @if(count($sim['warnings'] ?? []))
            <p class="text-[10px] text-amber-700">{{ implode(' · ', $sim['warnings']) }}</p>
        @endif

        {{-- AUFTRAGSKOSTEN-Wasserfall: MEK → FEK → Schwund → MGK → FGK → HK → V&V → Logistik → HK2 → Preisempfehlung --}}
        @if(count($sim['cost_breakdown'] ?? []))
            <div class="border-t border-black/5 pt-2" data-auftragskosten-wasserfall>
                <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 pb-1 text-[10px] uppercase tracking-wider text-gray-500">
                    <span>Auftragskosten</span><span class="text-right">je Person</span><span class="text-right">gesamt</span>
                </div>
                @foreach($sim['cost_breakdown'] as $kosten)
                    @php($kostenStufe = $kosten['stage'] ?? 'cost')
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-0.5 text-xs {{ in_array($kostenStufe, ['subtotal', 'total'], true) ? 'mt-1 border-t border-black/5 pt-1 font-semibold text-gray-900' : 'text-gray-600' }} {{ $kostenStufe === 'total' ? 'text-violet-700' : '' }}">
                        <span>{{ $kostenStufe === 'surcharge' ? '+ ' : '' }}{{ $kosten['label'] }}</span>
                        <span class="text-right tabular-nums">{{ number_format((float) ($kosten['amount'] ?? 0) / $simPax, 2, ',', '.') }} €</span>
                        <span class="text-right tabular-nums">{{ number_format((float) ($kosten['amount'] ?? 0), 2, ',', '.') }} €</span>
                    </div>
                @endforeach
                <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 mt-1 border-t border-black/5 pt-1 text-xs font-semibold text-violet-700">
                    <span>Preisempfehlung</span>
                    <span class="text-right tabular-nums">{{ number_format($simZielPp, 2, ',', '.') }} €</span>
                    <span class="text-right tabular-nums">{{ number_format((float) ($sim['target_price'] ?? 0), 2, ',', '.') }} €</span>
                </div>
                <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-0.5 text-xs {{ ($sim['contribution_margin'] ?? 0) < 0 ? 'text-rose-500' : 'text-emerald-600' }}">
                    <span>Deckungsbeitrag beim Katalog-VK</span>
                    <span class="text-right tabular-nums">{{ number_format($simDbPp, 2, ',', '.') }} €</span>
                    <span class="text-right tabular-nums">{{ number_format((float) ($sim['contribution_margin'] ?? 0), 2, ',', '.') }} €</span>
                </div>
            </div>
        @endif

        {{-- Zeitaufschlüsselung je Rezept (aktive Produktionszeit) --}}
        @if(count($sim['time_breakdown'] ?? []))
            <details class="pt-1" data-zeitaufschluesselung>
                <summary class="cursor-pointer text-[11px] font-medium text-gray-600">Zeitaufschlüsselung: {{ number_format((float) ($sim['active_person_minutes'] ?? 0) / 60, 2, ',', '.') }} Personenstunden <span class="font-normal text-gray-500">({{ number_format((float) ($sim['active_person_minutes'] ?? 0), 1, ',', '.') }} Personenminuten)</span></summary>
                <div class="overflow-x-auto pt-2">
                    <table class="w-full min-w-[760px] text-[11px]">
                        <thead><tr class="text-gray-500">
                            <th class="py-1 text-left font-medium">Rezept</th><th class="text-right font-medium">Ansätze</th><th class="text-right font-medium">Vorgänge</th><th class="text-right font-medium">Rüsten</th><th class="text-right font-medium">Vorgangszeit</th><th class="text-right font-medium">Variabel</th><th class="text-right font-medium">Aktiv gesamt</th>
                        </tr></thead>
                        <tbody>
                        @foreach($sim['time_breakdown'] as $zeit)
                            <tr class="border-t border-black/5">
                                <td class="py-1 pr-3">{{ $zeit['recipe'] ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ number_format((float) ($zeit['production_batches'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-right tabular-nums">{{ (int) ($zeit['operations'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ number_format((float) ($zeit['setup_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                <td class="text-right tabular-nums">{{ number_format((float) ($zeit['batch_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                <td class="text-right tabular-nums">{{ number_format((float) ($zeit['variable_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                <td class="text-right font-medium tabular-nums">{{ number_format((float) ($zeit['active_person_minutes'] ?? 0), 1, ',', '.') }} min</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </div>

    {{-- WARENEINSATZ JE POSITION — woraus sich die Kosten zusammensetzen (wie die Zutatenliste beim Gericht).
         Angebot-Spezifikum: $sim['positionen'] = aggregierte Komposition-Zeilen (role,label,ek,price je Person). --}}
    @if(count($sim['positionen'] ?? []))
        @php($sumEkPp = (float) ($sim['ek_per_person'] ?? 0))
        @php($sumVkPp = (float) ($sim['price_per_person'] ?? $simCatalogPp))
        <div class="rounded-xl border border-black/5 p-3">
            <p class="{{ $label }} mb-1.5">Wareneinsatz je Position / Person</p>
            <table class="w-full text-xs">
                <thead><tr class="text-gray-500 text-[10px] uppercase tracking-wider">
                    <th class="text-left font-medium py-1">Position</th>
                    <th class="text-right font-medium">Wareneinsatz</th>
                    <th class="text-right font-medium">VK</th>
                    <th class="text-right font-medium">W-%</th>
                </tr></thead>
                <tbody>
                @foreach($sim['positionen'] as $z)
                    @php($zEk = $z['ek'] ?? null)
                    @php($zVk = $z['price'] ?? null)
                    @php($zw = ($zVk !== null && (float) $zVk > 0 && $zEk !== null) ? (float) $zEk / (float) $zVk * 100 : null)
                    <tr class="border-t border-black/5">
                        <td class="py-1">@if(!empty($z['role']))<span class="text-gray-500">{{ $z['role'] }}:</span> @endif{{ $z['label'] ?? '—' }}</td>
                        <td class="text-right tabular-nums">{{ $zEk !== null ? number_format((float) $zEk, 2, ',', '.') . ' €' : '—' }}</td>
                        <td class="text-right tabular-nums text-gray-600">{{ $zVk !== null ? number_format((float) $zVk, 2, ',', '.') . ' €' : '—' }}</td>
                        <td class="text-right tabular-nums">{{ $zw !== null ? number_format($zw, 1, ',', '.') . ' %' : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-black/10 font-semibold text-gray-900">
                        <td class="py-1">Summe / Person</td>
                        <td class="text-right tabular-nums">{{ number_format($sumEkPp, 2, ',', '.') }} €</td>
                        <td class="text-right tabular-nums text-gray-600">{{ number_format($sumVkPp, 2, ',', '.') }} €</td>
                        <td class="text-right tabular-nums">{{ $sumVkPp > 0 ? number_format($sumEkPp / $sumVkPp * 100, 1, ',', '.') . ' %' : '—' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endif
