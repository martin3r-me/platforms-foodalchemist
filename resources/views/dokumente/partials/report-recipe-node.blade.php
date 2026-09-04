@php
    $opt = $optionen ?? [];
    $depth = (int) ($node['tiefe'] ?? 0);
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
    $num = fn ($v, $dec = 2, $suffix = '') => $v !== null && $v !== '' ? rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',') . $suffix : '—';
    $simulationRequirements = $simulationRequirements ?? [];
    $simulationRequirement = $simulationRequirements[(int) ($node['id'] ?? 0)] ?? null;
@endphp

<section class="recipe-node depth-{{ min($depth, 4) }}">
    @if($depth === 0)
        <h2>
    @else
        <h3>
    @endif
        {{ $node['name'] ?? 'Rezept' }}
        <span class="muted">#{{ $node['id'] ?? '—' }} · {{ ($node['is_sales_recipe'] ?? false) ? 'Gericht' : 'Basisrezept' }}</span>
    @if($depth === 0)
        </h2>
    @else
        </h3>
    @endif

    @if($node['zyklus'] ?? false)
        <p class="warn">Zyklische Referenz erkannt — Kaskade hier gestoppt.</p>
    @else
        @if($simulationRequirement)
            <div class="grid meta" data-simulated-recipe-quantity>
                <div><span>Auftragsbedarf</span>{{ $num($simulationRequirement['benoetigt_ansaetze'] ?? null, 3, ' Ansätze') }}</div>
                <div><span>Produktion</span>{{ $num($simulationRequirement['ansaetze'] ?? null, 3, ' Ansätze') }}</div>
                <div><span>Portionen</span>{{ ($simulationRequirement['portionen'] ?? null) !== null ? number_format((int) $simulationRequirement['portionen'], 0, ',', '.') : '—' }}</div>
                <div><span>Produktionsmenge</span>{{ ($simulationRequirement['produzierte_menge_kg'] ?? null) !== null ? $num($simulationRequirement['produzierte_menge_kg'], 3, ' kg') : '—' }}</div>
            </div>
        @endif
        @if($opt['stammdaten'] ?? true)
            <div class="grid meta">
                <div><span>Yield</span>{{ $node['yield_kg'] !== null ? $num($node['yield_kg'], 3, ' kg') : ($node['yield_pieces'] !== null ? $num($node['yield_pieces'], 2, ' Stk.') : '—') }}</div>
                {{-- #3: Kosten (EK / Food Cost) hinter `ek`-Flag. Default true → bestehende Rezept-Reports
                     unverändert; die Foodbook-/Speisekarte-KUNDENsicht setzt ek=false (nur intern EK). --}}
                @if($opt['ek'] ?? true)
                    <div><span>EK gesamt</span>{{ $money($node['ek_total_eur'], 2) }}</div>
                    <div><span>EK/kg</span>{{ $node['ek_per_kg_eur'] !== null ? $money($node['ek_per_kg_eur'], 2) . '/kg' : '—' }}</div>
                @endif
                @if($node['is_sales_recipe'] ?? false)
                    <div><span>VK netto</span>{{ $money($node['sales_net'], 2) }}</div>
                    @if($opt['ek'] ?? true)
                        <div><span>Food Cost</span>{{ $node['food_cost_percent'] !== null ? $num($node['food_cost_percent'], 1, ' %') : '—' }}</div>
                    @endif
                    <div><span>VK-Einheit</span>{{ $node['sales_unit'] ?? '—' }}</div>
                @endif
                <div><span>Kategorie</span>{{ $node['category'] ?? $node['dish_main_group'] ?? '—' }}</div>
                <div><span>Status</span>{{ $node['status'] ?? '—' }}</div>
            </div>

            @if(($node['description'] ?? null) || ($node['sales_wording_standard'] ?? null))
                <div class="copy">
                    @if($node['sales_wording_standard'] ?? null)<p><strong>Wording:</strong> {{ $node['sales_wording_standard'] }}</p>@endif
                    @if($node['description'] ?? null)<p>{{ $node['description'] }}</p>@endif
                </div>
            @endif
        @endif

        {{-- §3.2 REGENERATION — eigene Ebene, eigener Schalter. Adressat ist die Küche vor
             Ort (Satellit), nicht die Produktionsküche. Stand bis 2026-09-04 in KEINEM
             Druckstück, obwohl im Editor gepflegt. --}}
        @if(($opt['regeneration'] ?? false) && count($node['regenerationen'] ?? []))
            <h4>Regeneration</h4>
            <table>
                <thead><tr><th>Komponente</th><th>Gerät</th><th style="text-align:right">°C</th><th style="text-align:right">min</th><th style="text-align:right">Kern °C</th><th>Hinweis</th></tr></thead>
                <tbody>
                @foreach($node['regenerationen'] as $reg)
                    <tr>
                        <td>{{ $reg['komponente'] ?? '—' }}</td>
                        <td>{{ $reg['geraet'] ?? '—' }}</td>
                        <td style="text-align:right">{{ $reg['temp_c'] ?? '—' }}</td>
                        <td style="text-align:right">{{ $reg['duration_min'] ?? '—' }}</td>
                        <td style="text-align:right">{{ $reg['core_temp_c'] ?? '—' }}</td>
                        <td>{{ $reg['note'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- §3.3 ANRICHTEN — Adressat ist der Pass. Hing vorher am `stammdaten`-Flag und
             kam damit auf jedem Blatt mit, auch auf dem Kalkulations-Auszug. --}}
        @if(($opt['anrichten'] ?? false) && ($node['plating_text'] ?? null))
            <h4>Anrichten &amp; Ausgabe</h4>
            <div class="copy"><p>{{ $node['plating_text'] }}</p></div>
        @endif

        @if($opt['produktion'] ?? false)
            @php($p = $node['produktion'] ?? [])
            <h4>Produktion</h4>
            <div class="grid meta">
                <div><span>Fertigung</span>{{ $p['production_depth'] ?? '—' }}</div>
                <div><span>Arbeitszeit</span>{{ $p['work_time_min'] !== null ? $p['work_time_min'] . ' min' : '—' }}</div>
                <div><span>Temperatur</span>{{ $p['temperature'] ?? '—' }}</div>
                <div><span>Funktion</span>{{ $p['function'] ?? '—' }}</div>
                <div><span>Posten</span>{{ $p['default_station'] ?? '—' }}</div>
                <div><span>Rüstzeit</span>{{ $p['setup_time_min'] !== null ? $p['setup_time_min'] . ' min' : '—' }}</div>
                <div><span>Vorlauf</span>{{ $p['max_vorlauf_tage'] !== null ? $p['max_vorlauf_tage'] . ' Tage' : '—' }}</div>
                <div><span>Batchdeckel</span>{{ $p['batch_max_kg'] !== null ? $num($p['batch_max_kg'], 3, ' kg') : ($p['batch_max_pieces'] !== null ? $num($p['batch_max_pieces'], 2, ' Stk.') : '—') }}</div>
                <div><span>Variable Zeit</span>{{ $p['variable_work_time_min'] !== null ? $num($p['variable_work_time_min'], 2, ' min/' . ($p['variable_work_time_basis'] ?? '?')) : '—' }}</div>
                <div><span>Standzeit</span>{{ $p['standzeit_min'] !== null ? $p['standzeit_min'] . ' min' : '—' }}</div>
                <div class="wide"><span>Equipment</span>{{ implode(', ', $p['equipment'] ?? []) ?: '—' }}</div>
            </div>
        @endif

        @if(($opt['zutaten'] ?? true) && count($node['ingredients'] ?? []))
            <h4>Zutaten / Komponenten</h4>
            <table>
                <thead>
                    <tr>
                        <th>Pos.</th>
                        <th>Name</th>
                        <th>Menge</th>
                        <th>Typ</th>
                        @if($opt['preise'] ?? false)<th>Preis</th>@endif
                        @if($opt['lieferanten'] ?? false)<th>Lieferant / Artikel</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($node['ingredients'] as $ingredientIndex => $z)
                        @php($simulatedIngredient = $simulationRequirement['zutaten'][$ingredientIndex] ?? null)
                        <tr>
                            <td>{{ $z['position'] }}</td>
                            <td>{{ $z['name'] ?? '—' }}</td>
                            <td>
                                @if($simulatedIngredient)
                                    <strong>{{ $num($simulatedIngredient['menge'] ?? null, 3, ($simulatedIngredient['einheit'] ?? null) ? ' ' . $simulatedIngredient['einheit'] : '') }}</strong>
                                    <span class="muted">für Auftrag</span>
                                @else
                                    {{ $z['menge'] ?? '—' }}
                                @endif
                            </td>
                            <td>{{ $z['type'] ?? '—' }}</td>
                            @if($opt['preise'] ?? false)
                                <td>
                                    @if(($z['type'] ?? null) === 'basisrezept' && ($z['subrecipe']['ek_total_eur'] ?? null) !== null)
                                        {{ $money($z['subrecipe']['ek_total_eur'], 2) }}
                                    @else
                                        {{ $money($z['gp']['lead_la']['price'] ?? null, 2) }}
                                    @endif
                                </td>
                            @endif
                            @if($opt['lieferanten'] ?? false)
                                <td>
                                    @if($z['gp']['lead_la'] ?? null)
                                        {{ $z['gp']['lead_la']['supplier'] ?? '—' }} · {{ $z['gp']['lead_la']['article_number'] ?? '—' }} · {{ $z['gp']['lead_la']['designation'] ?? '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if($opt['steps'] ?? false)
            <h4>Anleitung</h4>
            @forelse($node['steps'] ?? [] as $s)
                <div class="step">
                    <div><span class="step-nr">{{ $s['position'] }}.</span> @if($s['phase'])<span class="step-phase">{{ $s['phase'] }}:</span> @endif{{ $s['text'] }}</div>
                    @if(($opt['bilder'] ?? false) && count($s['photos'] ?? []))
                        <div class="step-photos">
                            @foreach($s['photos'] as $foto)
                                @if($foto['src'] ?? null)
                                    <span class="step-photo">
                                        <img src="{{ $foto['src'] }}" alt="{{ $foto['caption'] ?? ('Schritt ' . $s['position']) }}">
                                        @if($foto['caption'] ?? null)<span class="caption">{{ $foto['caption'] }}</span>@endif
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="muted">Keine Schritte gepflegt.</p>
            @endforelse
        @endif

        @if($opt['sensorik'] ?? false)
            @php($sensorik = $node['sensorik'] ?? null)
            <h4>Sensorik</h4>
            @if(! $sensorik || ($sensorik['leer'] ?? false))
                <p class="muted">Keine Sensorikdaten.</p>
            @else
                @include('foodalchemist::dokumente.partials.report-sensory-radar', [
                    'geschmack' => $sensorik['geschmack'] ?? [],
                    'dominant' => $sensorik['dominant'] ?? [],
                    'luecken' => $sensorik['luecken'] ?? [],
                ])
                @if(count($sensorik['textur'] ?? []) || ($sensorik['monotonie'] ?? null))
                    <div class="grid meta">
                        <div class="wide"><span>Textur</span>{{ collect($sensorik['textur'] ?? [])->pluck('label')->implode(', ') ?: '—' }}</div>
                        @if($sensorik['monotonie'] ?? null)<div class="wide"><span>Hinweis</span>{{ $sensorik['monotonie'] }}</div>@endif
                    </div>
                @endif
            @endif
        @endif

        @if($opt['deklaration'] ?? false)
            @include('foodalchemist::dokumente.partials.report-declaration', ['deklaration' => $node['deklaration'] ?? []])
        @endif

        @if($opt['naehrwerte'] ?? false)
            @include('foodalchemist::dokumente.partials.report-nutrition', ['naehrwerte' => $node['naehrwerte'] ?? []])
        @endif

        @if(($opt['notizen'] ?? false) && ($node['notes_manual'] ?? null))
            <h4>Interne Notizen</h4>
            <p class="copy">{{ $node['notes_manual'] }}</p>
        @endif

        @if(($opt['kaskade'] ?? false) && count($node['ingredients'] ?? []))
            @foreach($node['ingredients'] as $z)
                @if($z['subrecipe'] ?? null)
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $z['subrecipe'], 'optionen' => $opt, 'simulationRequirements' => $simulationRequirements])
                @endif
            @endforeach
        @endif
    @endif
</section>
