@php
    $opt = $optionen ?? [];
    $depth = (int) ($node['tiefe'] ?? 0);
    $istKopf = ($istDokumentKopf ?? false) && $depth === 0;
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
    /* Komponenten-Anteile eines Amuse liegen bei Zehntelcent — auf 2 Stellen gerundet
       stünde in der ganzen Spalte „0,00 €". Unter 1 € deshalb 3 Nachkommastellen. */
    $geld = fn ($v) => $v === null || $v === '' ? '—'
        : number_format((float) $v, abs((float) $v) < 1 ? 3 : 2, ',', '.') . ' €';
    $num = fn ($v, $dec = 2, $suffix = '') => $v !== null && $v !== '' ? rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',') . $suffix : '—';
    $simulationRequirements = $simulationRequirements ?? [];
    $simulationRequirement = $simulationRequirements[(int) ($node['id'] ?? 0)] ?? null;

    $istGericht = (bool) ($node['is_sales_recipe'] ?? false);
    $adresse = (string) ($node['adresse'] ?? '');
    $eltern = $node['eltern'] ?? null;
    $typLabel = $istGericht ? 'Gericht' : ($depth > 0 ? 'Komponente · Basisrezept' : 'Basisrezept');

    /* Meta-Kachel nur rendern, wenn sie etwas sagt: die „—"-Kacheln (Posten, Rüstzeit,
       Batchdeckel …) füllten vorher halbe Seiten mit Leerwerten. */
    $kachel = function (string $label, $wert, string $klasse = '') {
        if ($wert === null || $wert === '' || $wert === '—') {
            return '';
        }

        return '<div' . ($klasse ? ' class="' . $klasse . '"' : '') . '><span>'
            . e($label) . '</span>' . e($wert) . '</div>';
    };
    $gitter = function (array $kacheln) {
        $html = implode('', array_filter($kacheln));

        return $html === '' ? '' : '<div class="grid meta">' . $html . '</div>';
    };

    $preiseAn = ($opt['preise'] ?? false) && ($opt['ek'] ?? true);
    $ekSumme = collect($node['ingredients'] ?? [])->sum(fn ($z) => (float) ($z['ek_anteil_eur'] ?? 0));
    $ekLuecken = collect($node['ingredients'] ?? [])->filter(fn ($z) => ($z['ek_anteil_eur'] ?? null) === null)->count();
@endphp

<section class="recipe-node depth-{{ min($depth, 4) }}">
    <div class="node-head">
        {{-- Kicker (Adresse + Typ + Klassen) ÜBER dem Titel — wie der Dokumentkopf.
             Das Badge darf nicht IM Heading stehen: DomPDF legt inline-block dort
             über den Titeltext. --}}
        <div class="node-kicker">
            @if($adresse !== '')<span class="addr">{{ $adresse }}</span>@endif
            <span class="chip {{ $istGericht ? 'chip-dish' : 'chip-base' }}">{{ $typLabel }}</span>
            <span class="chip">#{{ $node['id'] ?? '—' }}</span>
            @if($node['category'] ?? $node['dish_main_group'] ?? null)<span class="chip">{{ $node['category'] ?? $node['dish_main_group'] }}</span>@endif
            @if($node['status'] ?? null)<span class="chip">{{ $node['status'] }}</span>@endif
        </div>

        @unless($istKopf)
            @php($tag = $depth === 0 ? 'h2' : 'h3')
            <{{ $tag }} class="node-title">{{ $node['name'] ?? 'Rezept' }}</{{ $tag }}>
        @endunless

        @if($eltern)
            <div class="from-line">
                Komponente {{ $eltern['nr'] ?? '?' }} von {{ $eltern['von'] ?? '?' }} in
                <strong>{{ $eltern['name'] ?? '—' }}</strong>@if($eltern['adresse'] ?? '') <span class="muted">({{ $eltern['adresse'] }})</span>@endif
                @if($eltern['einsatz'] ?? null) · Einsatz dort: <strong>{{ $eltern['einsatz'] }}</strong>@endif
            </div>
        @endif
    </div>

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

        @if(($opt['stammdaten'] ?? true) && $depth > 0)
            {{-- Komponenten bekommen eine Kennzahlen-ZEILE statt Kacheln: bei vier
                 Komponenten sparte das im Test rund 6 cm, ohne Information zu verlieren. --}}
            @php($kennzahlen = array_filter([
                $node['yield_kg'] !== null ? 'Yield ' . $num($node['yield_kg'], 3, ' kg') : ($node['yield_pieces'] !== null ? 'Yield ' . $num($node['yield_pieces'], 2, ' Stk.') : null),
                ($opt['ek'] ?? true) && $node['ek_total_eur'] !== null ? 'EK ' . $money($node['ek_total_eur'], 2) . ' je Ansatz' : null,
                ($opt['ek'] ?? true) && $node['ek_per_kg_eur'] !== null ? $money($node['ek_per_kg_eur'], 2) . '/kg' : null,
                ($opt['produktion'] ?? false) && ($node['produktion']['work_time_min'] ?? null) !== null ? 'Arbeitszeit ' . $node['produktion']['work_time_min'] . ' min' : null,
                ($opt['produktion'] ?? false) && ($node['produktion']['temperature'] ?? null) ? $node['produktion']['temperature'] : null,
            ]))
            @if(count($kennzahlen))<div class="kennzahlen">{{ implode(' · ', $kennzahlen) }}</div>@endif

            @if(($node['description'] ?? null) || ($node['plating_text'] ?? null))
                <div class="copy">
                    @if($node['description'] ?? null)<p>{{ $node['description'] }}</p>@endif
                    @if($node['plating_text'] ?? null)<p><strong>Plating:</strong> {{ $node['plating_text'] }}</p>@endif
                </div>
            @endif
        @elseif($opt['stammdaten'] ?? true)
            {!! $gitter([
                $kachel('Yield', $node['yield_kg'] !== null ? $num($node['yield_kg'], 3, ' kg') : ($node['yield_pieces'] !== null ? $num($node['yield_pieces'], 2, ' Stk.') : null)),
                /* #3: Kosten (EK / Food Cost) hinter `ek`-Flag. Default true → bestehende Rezept-Reports
                   unverändert; die Foodbook-/Speisekarte-KUNDENsicht setzt ek=false (nur intern EK). */
                ($opt['ek'] ?? true) ? $kachel('EK gesamt', $node['ek_total_eur'] !== null ? $money($node['ek_total_eur'], 2) : null) : '',
                ($opt['ek'] ?? true) ? $kachel('EK/kg', $node['ek_per_kg_eur'] !== null ? $money($node['ek_per_kg_eur'], 2) . '/kg' : null) : '',
                $istGericht ? $kachel('VK netto', $node['sales_net'] !== null ? $money($node['sales_net'], 2) : null) : '',
                ($istGericht && ($opt['ek'] ?? true)) ? $kachel('Food Cost', $node['food_cost_percent'] !== null ? $num($node['food_cost_percent'], 1, ' %') : null) : '',
                $istGericht ? $kachel('VK-Einheit', $node['sales_unit'] ?? null) : '',
            ]) !!}

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
        {{-- Spec 51: was fuer DIESE Zielmenge gepackt werden muss. Skaliert mit der
             Hochrechnung — 6 kg brauchen andere Behaelter als 60. --}}
        @if(($opt['behaelter'] ?? false) && count($node['behaelter'] ?? []))
            <h4>Behälter</h4>
            <table>
                <thead><tr><th>Zweck</th><th>Bedarf</th></tr></thead>
                <tbody>
                @foreach($node['behaelter'] as $b)
                    <tr><td>{{ ucfirst($b['zweck']) }}</td><td>{{ $b['kurz'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif

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

        {{-- §3.3 ANRICHTEN — bebilderte Schrittfolge (dieselben `recipe_steps` mit
             ebene='anrichten'), Adressat ist der Pass. Der Spiegel-Text bleibt Fallback für
             Gerichte, deren Anrichte-Ebene noch keine Schritte trägt (Altbestand). --}}
        @if($opt['anrichten'] ?? false)
            @if(count($node['anrichte_schritte'] ?? []))
                <h4>Anrichten &amp; Ausgabe</h4>
                @foreach($node['anrichte_schritte'] as $s)
                    <div class="step">
                        <div><span class="step-nr">{{ $s['position'] }}.</span> @if($s['phase'])<span class="step-phase">{{ $s['phase'] }}:</span> @endif{{ $s['text'] }}</div>
                        @if(($opt['bilder'] ?? false) && count($s['photos'] ?? []))
                            <div class="step-photos">
                                @foreach($s['photos'] as $foto)
                                    @if($foto['src'] ?? null)
                                        <span class="step-photo">
                                            <img src="{{ $foto['src'] }}" alt="{{ $foto['caption'] ?? ('Anrichten ' . $s['position']) }}">
                                            @if($foto['caption'] ?? null)<span class="caption">{{ $foto['caption'] }}</span>@endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @elseif($node['plating_text'] ?? null)
                <h4>Anrichten &amp; Ausgabe</h4>
                <div class="copy"><p>{{ $node['plating_text'] }}</p></div>
            @endif
        @endif

        {{-- Produktions-Kacheln im Layout von origin/main (leere Kacheln filtert `$kachel`
             weg). Ergänzt: variable Zeit + Standzeit, die der Editor pflegt, aber nie
             gedruckt wurden. Rüstzeit und Vorlauf sind am GERICHT bewusst leer — beides
             sind Herstellungs-Größen, die dort keine Bedeutung haben (Entscheid
             2026-09-04); als null fallen sie automatisch aus dem Gitter. --}}
        @if(($opt['produktion'] ?? false) && $depth === 0)
            @php($p = $node['produktion'] ?? [])
            @php($produktionsGitter = $gitter([
                $kachel('Fertigung', $p['production_depth'] ?? null),
                $kachel($istGericht ? 'Fertigstellungszeit' : 'Arbeitszeit', $p['work_time_min'] !== null ? $p['work_time_min'] . ' min' : null),
                $kachel('Rüstzeit', $istGericht || ($p['setup_time_min'] ?? null) === null ? null : $p['setup_time_min'] . ' min'),
                $kachel('Temperatur', $p['temperature'] ?? null),
                $kachel($istGericht ? 'Finalisierungs-Posten' : 'Posten', $p['default_station'] ?? null),
                $kachel('Vorlauf', $istGericht || ($p['max_vorlauf_tage'] ?? null) === null ? null : $p['max_vorlauf_tage'] . ' Tage'),
                $kachel('Batchdeckel', $p['batch_max_kg'] !== null ? $num($p['batch_max_kg'], 3, ' kg') : ($p['batch_max_pieces'] !== null ? $num($p['batch_max_pieces'], 2, ' Stk.') : null)),
                $kachel('Variable Zeit', ($p['variable_work_time_min'] ?? null) !== null ? $num($p['variable_work_time_min'], 2, ' min/' . ($p['variable_work_time_basis'] ?? '?')) : null),
                $kachel('Standzeit', ($p['standzeit_min'] ?? null) !== null ? $p['standzeit_min'] . ' min' : null),
                $kachel('Funktion', $p['function'] ?? null),
                $kachel('Equipment', implode(', ', $p['equipment'] ?? []) ?: null, 'full'),
            ]))
            @if($produktionsGitter !== '')
                <h4>Produktion</h4>
                {!! $produktionsGitter !!}
            @endif
        @endif

        @if(($opt['zutaten'] ?? true) && count($node['ingredients'] ?? []))
            <h4>Zutaten / Komponenten</h4>
            <table class="zutaten">
                @php($mitLieferant = (bool) ($opt['lieferanten'] ?? false))
                <thead>
                    <tr>
                        <th style="width: 5%">#</th>
                        <th style="width: {{ $preiseAn ? ($mitLieferant ? '25%' : '40%') : ($mitLieferant ? '32%' : '56%') }}">Name</th>
                        <th style="width: 13%">Einsatz</th>
                        <th style="width: 10%">Typ</th>
                        @if($preiseAn)
                            <th class="num" style="width: 12%">€ / Einheit</th>
                            <th class="num" style="width: 10%">EK-Anteil</th>
                        @endif
                        @if($mitLieferant)<th style="width: {{ $preiseAn ? '25%' : '32%' }}">Lieferant / Artikel</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($node['ingredients'] as $ingredientIndex => $z)
                        @php($simulatedIngredient = $simulationRequirement['zutaten'][$ingredientIndex] ?? null)
                        <tr>
                            <td>{{ $z['nr'] ?? ($ingredientIndex + 1) }}</td>
                            <td>{{ $z['name'] ?? '—' }}</td>
                            <td>
                                @if($simulatedIngredient)
                                    <strong>{{ $num($simulatedIngredient['menge'] ?? null, 3, ($simulatedIngredient['einheit'] ?? null) ? ' ' . $simulatedIngredient['einheit'] : '') }}</strong>
                                    <span class="muted">für Auftrag</span>
                                @else
                                    {{ $z['menge'] ?? '—' }}
                                @endif
                            </td>
                            <td>
                                {{-- Verweis statt Typwort: „→ K3" zeigt auf den Komponenten-Block weiter unten. --}}
                                @if($z['adresse'] ?? null)
                                    <span class="addr">{{ $z['adresse'] }}</span>
                                @elseif(($z['type'] ?? null) === 'basisrezept')
                                    Basisrezept
                                @else
                                    {{ $z['type'] ?? '—' }}
                                @endif
                            </td>
                            @if($preiseAn)
                                <td class="num">
                                    @if(($z['ek_pro_einheit_eur'] ?? null) !== null)
                                        {{ number_format((float) $z['ek_pro_einheit_eur'], 2, ',', '.') }} <span class="muted">{{ $z['ek_pro_einheit_label'] }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="num">{{ $geld($z['ek_anteil_eur'] ?? null) }}</td>
                            @endif
                            @if($opt['lieferanten'] ?? false)
                                <td>
                                    @if($z['gp']['lead_la'] ?? null)
                                        @php($la = $z['gp']['lead_la'])
                                        {{ $la['supplier'] ?? '—' }} · {{ $la['article_number'] ?? '—' }}<br>
                                        <span class="muted">{{ $la['designation'] ?? '—' }}</span>
                                        @if(($la['price'] ?? null) !== null)
                                            <br>{{ $money($la['price'], 2) }} / Kolli{{ ($la['qty'] ?? null) ? ' (' . $num($la['qty'], 3) . ' ' . ($la['unit_code'] ?? '') . ')' : '' }}
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    @if($preiseAn && $ekSumme > 0)
                        <tr class="sum-line">
                            <td colspan="{{ 4 }}">Σ EK-Anteile{{ $ekLuecken > 0 ? ' (ohne ' . $ekLuecken . ' unbepreiste Zeile' . ($ekLuecken === 1 ? '' : 'n') . ')' : '' }}</td>
                            <td class="num"></td>
                            <td class="num">{{ $geld($ekSumme) }}</td>
                            @if($opt['lieferanten'] ?? false)<td></td>@endif
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif

        @if($opt['steps'] ?? false)
            @php($steps = collect($node['steps'] ?? []))
            @php($fotos = ($opt['bilder'] ?? false)
                ? $steps->flatMap(fn ($s) => collect($s['photos'] ?? [])->map(fn ($f) => $f + ['step' => $s['position']]))
                : collect())
            <h4>Anleitung</h4>
            @if($steps->isEmpty())
                <p class="muted">Keine Schritte gepflegt.</p>
            @else
                {{-- Dichte Tabelle statt Kasten je Schritt: der Text liest sich am Stück,
                     die Fotos kommen als eine Reihe darunter. --}}
                <div class="steps">
                    @foreach($steps as $s)
                        <div class="step-row"><span class="step-nr">{{ $s['position'] }}</span>@if($s['phase'])<span class="step-phase">{{ $s['phase'] }}:</span> @endif{{ $s['text'] }}</div>
                    @endforeach
                </div>
                @if($fotos->isNotEmpty())
                    <div class="photo-strip">
                        @foreach($fotos as $foto)
                            <span class="ps-item">
                                @if($foto['src'] ?? null)
                                    <img src="{{ $foto['src'] }}" alt="Schritt {{ $foto['step'] }}">
                                @else
                                    {{-- Kein dataUri lesbar: ehrlicher Platzhalter statt kaputtem Bild-Rahmen. --}}
                                    <span class="photo-missing">Bild nicht verfügbar</span>
                                @endif
                                <span class="ps-cap"><strong>Schritt {{ $foto['step'] }}</strong>@if($foto['caption'] ?? null) · {{ $foto['caption'] }}@endif</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
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
                    @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $z['subrecipe'], 'optionen' => $opt, 'simulationRequirements' => $simulationRequirements, 'istDokumentKopf' => false])
                @endif
            @endforeach
        @endif
    @endif
</section>
