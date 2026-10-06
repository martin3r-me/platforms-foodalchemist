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
     + ek_per_person + price_per_person für die WARENEINSATZ-JE-POSITION-Tabelle.

     fa-pass (2026-10-05): Tokens + Bausteine. Zahlen rechtsbündig in fa-table-Zellen (num),
     Zwischen- und Endsummen fett mit Linie darüber, Kennzahlen als x-fa::kpis ohne eigene
     Hauptzahl (die Hauptzahl der Ansicht ist die Angebotssumme im Kopf). --}}
@php
    $sim = $auftragsKalkulation ?? null;
    $zkEuro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
@endphp
@if($sim)
    @php
        $simPax = max(1, (int) ($sim['pax'] ?? 0));
        $simZielPp = (float) ($sim['target_price_per_person'] ?? 0);
        $simCatalogPp = (float) ($sim['catalog_price_per_person'] ?? 0);
        $simAbweichungPp = $simCatalogPp - $simZielPp;
        $simDb = (float) ($sim['contribution_margin'] ?? 0);
        $simDbPp = $simDb / $simPax;
        $simDbPct = ($sim['contribution_margin_pct'] ?? null) !== null ? number_format((float) $sim['contribution_margin_pct'], 1, ',', '.') . ' %' : null;
        $simMinuten = (float) ($sim['active_person_minutes'] ?? 0);
    @endphp
    <x-fa::section title="Vollkosten-Kalkulation" icon="heroicon-o-calculator"
        :meta="number_format((int) ($sim['pax'] ?? 0), 0, ',', '.') . ' Gäste'"
        description="Alle Kosten des Auftrags über das ganze Angebot. Prüft den Angebotspreis, ohne Stammdaten zu verändern."
        data-angebot-zuschlagskalkulation>

        <x-fa::kpis data-auftrag-preisempfehlung :items="[
            ['label' => 'Angebotspreis je Gast', 'value' => $zkEuro($simCatalogPp)],
            ['label' => 'Preisempfehlung je Gast', 'value' => $zkEuro($simZielPp), 'title' => 'Zielpreis aus den Vollkosten und dem Zuschlag des Teams'],
            ['label' => 'Abweichung je Gast', 'value' => ($simAbweichungPp > 0 ? '+' : '') . $zkEuro($simAbweichungPp),
             'tone' => $simAbweichungPp < 0 ? 'warn' : 'ok', 'title' => 'Angebotspreis je Gast minus Preisempfehlung je Gast'],
            ['label' => 'Deckungsbeitrag je Gast', 'value' => $zkEuro($simDbPp), 'tone' => $simDb < 0 ? 'crit' : 'ok',
             'title' => 'Gesamt ' . $zkEuro($simDb) . ($simDbPct !== null ? ', ' . $simDbPct : '')],
            ['label' => 'Wareneinsatz je Gast', 'value' => $zkEuro((float) ($sim['mek'] ?? 0) / $simPax), 'title' => 'Materialeinzelkosten des Auftrags je Gast'],
            ['label' => 'Fertigung je Gast', 'value' => $zkEuro((float) ($sim['fek'] ?? 0) / $simPax), 'title' => 'Fertigungseinzelkosten des Auftrags je Gast'],
            ['label' => 'Selbstkosten je Gast', 'value' => $zkEuro((float) ($sim['hk2'] ?? 0) / $simPax), 'title' => 'Herstellkosten inklusive Verwaltung, Vertrieb und Logistik'],
            ['label' => 'Mindestpreis gesamt', 'value' => $zkEuro($sim['minimum_price'] ?? 0)],
            ['label' => 'Zielpreis gesamt', 'value' => $zkEuro($sim['target_price'] ?? 0)],
            ['label' => 'Aktive Arbeitszeit', 'value' => number_format($simMinuten / 60, 2, ',', '.') . ' h'],
        ]" />

        @if($sim['unprofitable'] ?? false)
            <x-fa::notice tone="warn" title="Angebotspreis unter dem Zielpreis">
                Der Angebotspreis liegt {{ $zkEuro($sim['target_gap'] ?? 0) }} unter dem Zielpreis. Der Preis wurde nicht automatisch erhöht.
            </x-fa::notice>
        @endif
        @unless($sim['complete'] ?? false)
            <x-fa::notice tone="warn" title="Preisempfehlung noch nicht belastbar">
                Die Auftragsdaten sind unvollständig. Gerechnet wird mindestens mit dem ausgewiesenen Wareneinsatz aus dem Katalog.
            </x-fa::notice>
        @endunless
        @if(count($sim['warnings'] ?? []))
            <ul class="flex flex-col gap-1">
                @foreach($sim['warnings'] as $warnung)
                    <li><x-fa::signal tone="warn">{{ $warnung }}</x-fa::signal></li>
                @endforeach
            </ul>
        @endif

        {{-- AUFTRAGSKOSTEN-Wasserfall: MEK → FEK → Schwund → MGK → FGK → HK → V&V → Logistik → HK2 → Preisempfehlung --}}
        @if(count($sim['cost_breakdown'] ?? []))
            <div class="overflow-x-auto" data-auftragskosten-wasserfall>
                <table class="fa-table fa-table--compact">
                    <thead>
                        <tr>
                            <th class="w-full">Auftragskosten</th>
                            <th class="num">je Gast</th>
                            <th class="num">gesamt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sim['cost_breakdown'] as $kosten)
                            @php
                                $kostenStufe = $kosten['stage'] ?? 'cost';
                                $istSumme = in_array($kostenStufe, ['subtotal', 'total'], true);
                            @endphp
                            <tr class="{{ $istSumme ? 'font-semibold text-[var(--fa-ink)] [&>td]:border-t [&>td]:border-[var(--fa-line-strong)]' : 'text-[var(--fa-ink-2)]' }}">
                                <td>
                                    @if($kostenStufe === 'surcharge')<span class="text-[var(--fa-ink-3)]" aria-hidden="true">+&nbsp;</span>@endif{{ $kosten['label'] }}
                                </td>
                                <td class="num">{{ $zkEuro((float) ($kosten['amount'] ?? 0) / $simPax) }}</td>
                                <td class="num">{{ $zkEuro($kosten['amount'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold text-[var(--fa-accent)] [&>td]:border-t-2 [&>td]:border-[var(--fa-accent-line)]">
                            <td>Preisempfehlung</td>
                            <td class="num">{{ $zkEuro($simZielPp) }}</td>
                            <td class="num">{{ $zkEuro($sim['target_price'] ?? 0) }}</td>
                        </tr>
                        <tr class="{{ $simDb < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">
                            <td>Deckungsbeitrag beim Angebotspreis @if($simDbPct !== null)<span class="text-[var(--fa-ink-3)]">({{ $simDbPct }})</span>@endif</td>
                            <td class="num">{{ $zkEuro($simDbPp) }}</td>
                            <td class="num">{{ $zkEuro($simDb) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        {{-- Zeitaufschlüsselung je Rezept (aktive Produktionszeit) --}}
        @if(count($sim['time_breakdown'] ?? []))
            <details class="group" data-zeitaufschluesselung>
                <summary class="inline-flex items-center gap-1 cursor-pointer select-none text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)]">
                    @svg('heroicon-m-chevron-right', 'w-4 h-4 transition-transform group-open:rotate-90')
                    Arbeitszeit je Rezept: {{ number_format($simMinuten / 60, 2, ',', '.') }} Stunden
                    <span class="font-normal text-[var(--fa-ink-3)]">({{ number_format($simMinuten, 1, ',', '.') }} Minuten)</span>
                </summary>
                <div class="overflow-x-auto pt-2">
                    <table class="fa-table fa-table--compact min-w-[720px]">
                        <thead>
                            <tr>
                                <th class="w-full">Rezept</th>
                                <th class="num">Ansätze</th>
                                <th class="num">Arbeitsgänge</th>
                                <th class="num">Rüsten</th>
                                <th class="num">je Ansatz</th>
                                <th class="num">je Menge</th>
                                <th class="num">Aktiv gesamt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sim['time_breakdown'] as $zeit)
                                <tr>
                                    <td>{{ $zeit['recipe'] ?? '–' }}</td>
                                    <td class="num">{{ number_format((float) ($zeit['production_batches'] ?? 0), 2, ',', '.') }}</td>
                                    <td class="num">{{ (int) ($zeit['operations'] ?? 0) }}</td>
                                    <td class="num">{{ number_format((float) ($zeit['setup_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                    <td class="num">{{ number_format((float) ($zeit['batch_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                    <td class="num">{{ number_format((float) ($zeit['variable_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                    <td class="num font-medium">{{ number_format((float) ($zeit['active_person_minutes'] ?? 0), 1, ',', '.') }} min</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </x-fa::section>

    {{-- WARENEINSATZ JE POSITION — woraus sich die Kosten zusammensetzen (wie die Zutatenliste beim Gericht).
         Angebot-Spezifikum: $sim['positionen'] = aggregierte Komposition-Zeilen (role,label,ek,price je Person). --}}
    @if(count($sim['positionen'] ?? []))
        @php
            $sumEkPp = (float) ($sim['ek_per_person'] ?? 0);
            $sumVkPp = (float) ($sim['price_per_person'] ?? $simCatalogPp);
        @endphp
        <x-fa::section title="Wareneinsatz je Position" icon="heroicon-o-list-bullet" meta="je Gast" data-angebot-wareneinsatz-positionen>
            <div class="overflow-x-auto">
                <table class="fa-table fa-table--compact">
                    <thead>
                        <tr>
                            <th class="w-full">Position</th>
                            <th class="num">Wareneinsatz</th>
                            <th class="num">Verkaufspreis</th>
                            <th class="num">Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sim['positionen'] as $z)
                            @php
                                $zEk = $z['ek'] ?? null;
                                $zVk = $z['price'] ?? null;
                                $zw = ($zVk !== null && (float) $zVk > 0 && $zEk !== null) ? (float) $zEk / (float) $zVk * 100 : null;
                            @endphp
                            <tr>
                                <td>@if(!empty($z['role']))<span class="text-[var(--fa-ink-3)]">{{ $z['role'] }}:</span> @endif{{ $z['label'] ?? '–' }}</td>
                                <td class="num"><x-fa::money :value="$zEk" /></td>
                                <td class="num text-[var(--fa-ink-2)]"><x-fa::money :value="$zVk" /></td>
                                <td class="num">{{ $zw !== null ? number_format($zw, 1, ',', '.') . ' %' : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold text-[var(--fa-ink)] [&>td]:border-t [&>td]:border-[var(--fa-line-strong)]">
                            <td>Summe je Gast</td>
                            <td class="num">{{ $zkEuro($sumEkPp) }}</td>
                            <td class="num">{{ $zkEuro($sumVkPp) }}</td>
                            <td class="num">{{ $sumVkPp > 0 ? number_format($sumEkPp / $sumVkPp * 100, 1, ',', '.') . ' %' : '–' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-fa::section>
    @endif
@endif
