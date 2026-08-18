    <div x-show="tab === 'vorschau'" x-cloak class="pt-4 space-y-4">
    {{-- Küchen-Manager: Diät-/Allergen-Übersicht über die ganze Produktion (Rollup der Rezepte) --}}
    @if($allergenRollup)
        @php($ja = 'px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-700')
        @php($nein = 'px-2 py-0.5 rounded-md bg-black/10 text-gray-500')
        @php($warn = 'px-2 py-0.5 rounded-md bg-amber-500/15 text-amber-700')
        <x-foodalchemist::modal-section title="Diät & Allergene (über die ganze Produktion)">
            <div class="flex flex-wrap gap-1.5 items-center text-[11px]" data-produktion-allergene>
                <span class="{{ $allergenRollup['is_vegan'] ? $ja : $nein }}">{{ $allergenRollup['is_vegan'] ? '✓ ' : '' }}vegan</span>
                <span class="{{ $allergenRollup['is_vegetarian'] ? $ja : $nein }}">{{ $allergenRollup['is_vegetarian'] ? '✓ ' : '' }}vegetarisch</span>
                <span class="{{ $allergenRollup['is_halal'] ? $ja : $nein }}">{{ $allergenRollup['is_halal'] ? '✓ ' : '' }}halal</span>
                <span class="{{ $allergenRollup['is_gluten_free'] ? $ja : $nein }}">{{ $allergenRollup['is_gluten_free'] ? '✓ ' : '' }}glutenfrei</span>
                <span class="{{ $allergenRollup['is_lactose_free'] ? $ja : $nein }}">{{ $allergenRollup['is_lactose_free'] ? '✓ ' : '' }}laktosefrei</span>
                @if($allergenRollup['contains_pork'])<span class="{{ $warn }}">enthält Schwein</span>@endif
                @if($allergenRollup['contains_beef'])<span class="{{ $warn }}">enthält Rind</span>@endif
                <span class="ml-auto text-gray-400">Konfidenz {{ $allergenRollup['confidence'] }} · {{ $allergenRollup['n_gerichte'] }} Rezepte</span>
            </div>
            <p class="text-[10px] text-gray-500 mt-2">„vegan/…/frei" = trifft auf ALLE Rezepte zu · „enthält" = mind. ein Rezept. Rollup aus den Rezept-Spezifikationen (schwächste Konfidenz gewinnt).</p>
        </x-foodalchemist::modal-section>
    @endif
    <x-foodalchemist::modal-section title="Vorschau">
        @if($vorschau === null)
            <p class="text-[12px] text-gray-500">Ziele hinzufügen, um die Ansätze-Vorschau zu sehen.</p>
        @else
            <table class="{{ $table }}">
                <thead><tr>
                    <th class="{{ $th }} text-left">Rezept</th>
                    <th class="{{ $th }} text-right">Ansätze</th>
                    <th class="{{ $th }} text-right">Portionen/kg</th>
                    <th class="{{ $th }} text-right">Arbeitszeit</th>
                </tr></thead>
                <tbody>
                    @foreach($vorschau['rezepte'] as $r)
                        <tr class="border-t border-black/5">
                            <td class="{{ $td }}">{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Basisrezept</span>@endif</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ rtrim(rtrim(number_format($r['ansaetze'], 2, ',', '.'), '0'), ',') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['portionen'] !== null ? $r['portionen'] . ' Port.' : ($r['produzierte_menge_kg'] !== null ? number_format($r['produzierte_menge_kg'], 2, ',', '.') . ' kg' : '—') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['arbeitszeit_min'] !== null ? $r['arbeitszeit_min'] . ' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @foreach($vorschau['warnungen'] as $w)
                <x-foodalchemist::alert tone="warning" class="mt-2">{{ $w }}</x-foodalchemist::alert>
            @endforeach
        @endif
    </x-foodalchemist::modal-section>
    </div>{{-- /Vorschau-Panel --}}
