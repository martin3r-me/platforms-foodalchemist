{{-- Spec 70 · Inhalt eines Etiketts (Variablen aus dokumente/etiketten). --}}
<div class="etikett">
    <div class="kopf">
        @if($o['logo'])<img src="{{ $o['logo'] }}" class="logo" alt="">@endif
        <div class="titel">{{ $d['bezeichnung'] }}</div>
        @if(! empty($d['zusatz']) && ($istStellplatz || collect($felder)->contains('key', 'zusatz')))<div class="zusatz">{{ $d['zusatz'] }}</div>@endif
    </div>
    @if($istStellplatz)
        <div class="z klein">{{ implode(' · ', $d['inhalt']) ?: '–' }}</div>
    @else
        @foreach($felder as $feld)
            @php $k = $feld['key']; $leerModus = ($feld['modus'] ?? null) === 'leer'; @endphp
            @switch($k)
                @case('hergestellt_am') @case('eingefroren_am') @case('geoeffnet_am')
                    @php $wert = $d['datum'][$k] ?? null; @endphp
                    @if($leerModus || $wert !== null)
                        <div class="z"><span class="k">{{ ['hergestellt_am' => 'Hergestellt', 'eingefroren_am' => 'Eingefroren', 'geoeffnet_am' => 'Geöffnet'][$k] }}:</span> {!! $leerModus ? $linie : e($datum($wert)) !!}</div>
                    @endif
                    @break
                @case('verbrauchen_bis')
                    <div class="z bis">Verbrauchen bis: {!! $leerModus || $d['datum']['verbrauchen_bis'] === null ? $linie : e($datum($d['datum']['verbrauchen_bis'])) !!}</div>
                    @break
                @case('lagerung')
                    @if($d['lagerung'])<div class="z klein">{{ \Platform\FoodAlchemist\Services\EtikettService::LAGERUNG[$d['lagerung']] ?? '' }}</div>@endif
                    @break
                @case('menge')
                    <div class="z"><span class="k">Menge:</span> {!! $d['menge'] !== null ? e($d['menge']) : $linie !!}</div>
                    @break
                @case('zutaten')
                    @if($d['zutaten'] !== [])
                        <div class="z klein"><span class="k">Zutaten:</span>
                            @foreach($d['zutaten'] as $i => $zu){!! $zu['allergen'] ? '<b>' . e($zu['name']) . '</b>' : e($zu['name']) !!}@if(! empty($zu['teile'])) ({!! collect($zu['teile'])->map(fn ($t) => $t['allergen'] ? '<b>' . e($t['name']) . '</b>' : e($t['name']))->implode(', ') !!})@endif{{ $i < count($d['zutaten']) - 1 ? ', ' : '' }}@endforeach
                        </div>
                    @endif
                    @break
                @case('allergene')
                    @php $at = $allergenText(); $st = $spurenText(); @endphp
                    <div class="z klein">
                        @if($d['allergene_unbekannt'] && $at === null)
                            <span class="warn">Allergene nicht vollständig erfasst</span>
                        @else
                            <span class="k">Allergene:</span> <b>{{ $at }}</b>@if($d['allergene_unbekannt']) <span class="warn">(unvollständig)</span>@endif
                        @endif
                        @if($st) · <span class="k">Spuren:</span> {{ $st }}@endif
                    </div>
                    @break
                @case('zusatzstoffe')
                    @if($d['zusatzstoffe'] !== [])<div class="z klein"><span class="k">Zusatzstoffe:</span> {{ implode(', ', array_map(fn ($z) => $v->allergen_darstellung === 'kuerzel' ? $z['code'] : $z['label'], $d['zusatzstoffe'])) }}</div>@endif
                    @break
                @case('kuerzel')
                    <div class="z"><span class="k">Kürzel:</span> {!! $d['kuerzel'] !== null ? e($d['kuerzel']) : $linie !!}</div>
                    @break
                @case('charge')
                    <div class="z"><span class="k">Charge:</span> {!! $d['charge'] !== null ? e($d['charge']) : $linie !!}</div>
                    @break
                @case('hersteller')
                    <div class="z klein">{{ $d['hersteller'] }}</div>
                    @break
            @endswitch
        @endforeach
    @endif
    @if($v->fusstext)<div class="fuss">{{ $v->fusstext }}</div>@endif
</div>
