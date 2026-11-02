{{-- Spec 94 · Konfigurator-Editor, Reiter Pax-Pakete: Grenzen anlegen (oder vorschlagen lassen), schnüren, Mengen-Matrix
     Leistung × Paket mit Handwerten. Grundzeilen (gelten für alle Hauptkonzepte); abweichende Konzeptmengen rechnet das System. --}}
@php
    $pakete = $k->pakete;
    $menge = fn ($p, $lid) => $p->mengen->first(fn ($m) => (int) $m->leistung_id === (int) $lid && $m->konfigurator_konzept_id === null);
    $zahl = fn ($w) => rtrim(rtrim(number_format((float) $w, 2, ',', ''), '0'), ',');
@endphp

<x-fa::section title="Pakete" icon="heroicon-o-user-group" :meta="$pakete->count()" description="Die Grenzen legst du fest. Referenz-Pax (Standard: Mitte), Mengen und Preise rechnet das System." data-kf-pakete>
    @if($pakete->isNotEmpty())
        <table class="fa-table fa-table--compact">
            <thead><tr><th>Paket</th><th class="num">Von</th><th class="num">Bis</th><th class="num">Referenz-Pax</th><th>Geschnürt</th><th></th></tr></thead>
            <tbody>
                @foreach($pakete as $p)
                    <tr wire:key="kfp-{{ $p->id }}" data-kf-paket="{{ $p->id }}">
                        <td><x-fa::input size="sm" value="{{ $p->bezeichnung }}" placeholder="{{ $p->von_pax }}–{{ $p->bis_pax }} Gäste" wire:change="paketAendern({{ $p->id }}, 'bezeichnung', $event.target.value)" aria-label="Bezeichnung" /></td>
                        <td class="num"><x-fa::input size="sm" type="number" min="1" class="w-24" value="{{ $p->von_pax }}" wire:change="paketAendern({{ $p->id }}, 'von_pax', $event.target.value)" aria-label="Von" /></td>
                        <td class="num"><x-fa::input size="sm" type="number" min="1" class="w-24" value="{{ $p->bis_pax }}" wire:change="paketAendern({{ $p->id }}, 'bis_pax', $event.target.value)" aria-label="Bis" /></td>
                        <td class="num">
                            <x-fa::input size="sm" type="number" min="1" class="w-24" value="{{ $p->referenz_pax }}" wire:change="paketAendern({{ $p->id }}, 'referenz_pax', $event.target.value)" aria-label="Referenz-Pax" />
                            @if($p->referenz_manuell)<button type="button" class="{{ $leise }} underline" wire:click="paketAendern({{ $p->id }}, 'referenz_pax', '')">zurück auf Mitte</button>@endif
                        </td>
                        <td class="{{ $leise }}">{{ $p->geschnuert_at?->format('d.m. H:i') ?? 'noch nicht' }}</td>
                        <td class="text-right"><x-fa::button size="sm" variant="danger" wire:click="paketEntfernen({{ $p->id }})" wire:confirm="Paket {{ $p->anzeigeName() }} entfernen?">Entfernen</x-fa::button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="flex flex-wrap items-end gap-2">
        <x-fa::field label="Von Gästen" for="kfp-von"><x-fa::input id="kfp-von" type="number" min="1" class="w-28" wire:model="paketNeu.von_pax" /></x-fa::field>
        <x-fa::field label="Bis Gästen" for="kfp-bis"><x-fa::input id="kfp-bis" type="number" min="1" class="w-28" wire:model="paketNeu.bis_pax" /></x-fa::field>
        <x-fa::field label="Bezeichnung" for="kfp-bez" optional><x-fa::input id="kfp-bez" wire:model="paketNeu.bezeichnung" placeholder="leer = „100–199 Gäste“" /></x-fa::field>
        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="paketAnlegen" data-kf-paket-anlegen>Paket anlegen</x-fa::button>
        <x-fa::button variant="ghost" icon="heroicon-m-arrow-path" wire:click="schnueren" data-kf-schnueren>Pakete schnüren</x-fa::button>
    </div>
</x-fa::section>

<x-fa::section title="Paketgrenzen vorschlagen" icon="heroicon-o-light-bulb" description="Rechnet ohne KI: Pakete so breit wie möglich, solange der Paketpreis höchstens um die Toleranz vom genauen Preis abweicht. Eng bei hohen Fixkosten, breit bei großen Gruppen.">
    <div class="flex flex-wrap items-end gap-2">
        <x-fa::field label="Gäste von" for="kfv-von"><x-fa::input id="kfv-von" type="number" min="1" class="w-24" wire:model="vorschlagParameter.von" /></x-fa::field>
        <x-fa::field label="bis" for="kfv-bis"><x-fa::input id="kfv-bis" type="number" min="1" class="w-24" wire:model="vorschlagParameter.bis" /></x-fa::field>
        <x-fa::field label="Toleranz %" for="kfv-tol" optional><x-fa::input id="kfv-tol" class="w-24" wire:model="vorschlagParameter.toleranz" placeholder="{{ $form['paket_toleranz_pct'] ?? 10 }}" /></x-fa::field>
        <x-fa::field label="Raster" for="kfv-raster"><x-fa::input id="kfv-raster" type="number" min="1" class="w-20" wire:model="vorschlagParameter.raster" /></x-fa::field>
        <x-fa::field label="Mindestbreite" for="kfv-min"><x-fa::input id="kfv-min" type="number" min="1" class="w-20" wire:model="vorschlagParameter.mindestbreite" /></x-fa::field>
        <x-fa::button icon="heroicon-m-calculator" wire:click="vorschlagen" data-kf-vorschlagen>Vorschlagen</x-fa::button>
    </div>
    @if($vorschlag !== null)
        <table class="fa-table fa-table--compact" data-kf-vorschlag>
            <thead><tr><th class="num">Von</th><th class="num">Bis</th><th class="num">Referenz</th><th class="num">Größte Abweichung</th></tr></thead>
            <tbody>
                @foreach($vorschlag as $i => $v)
                    <tr wire:key="kfvs-{{ $i }}"><td class="num">{{ $v['von_pax'] }}</td><td class="num">{{ $v['bis_pax'] }}</td><td class="num">{{ $v['referenz_pax'] }}</td>
                        <td class="num">{{ number_format($v['max_abweichung_pct'], 1, ',', '.') }} %@if(! $v['toleranz_gehalten']) <x-fa::badge tone="warn">über Toleranz</x-fa::badge>@endif</td></tr>
                @endforeach
            </tbody>
        </table>
        <div class="flex justify-end gap-2">
            <x-fa::button variant="ghost" wire:click="vorschlagVerwerfen">Verwerfen</x-fa::button>
            <x-fa::button variant="primary" wire:click="vorschlagUebernehmen" wire:confirm="Der Vorschlag ersetzt alle Pakete. Handwerte in der Mengen-Matrix gehen verloren. Übernehmen?" data-kf-vorschlag-uebernehmen>Übernehmen</x-fa::button>
        </div>
    @endif
</x-fa::section>

@if($pakete->isNotEmpty() && $k->leistungen->isNotEmpty())
    <x-fa::section title="Mengen je Paket" icon="heroicon-o-table-cells" description="Feld ändern = Handwert (bleibt beim Schnüren erhalten), leeren = zurück auf die Regel. Grau: Wert nach Regel." data-kf-matrix>
        <div class="overflow-x-auto">
            <table class="fa-table fa-table--compact">
                <thead><tr><th>Leistung</th>@foreach($pakete as $p)<th class="num">{{ $p->anzeigeName() }}<div class="{{ $leise }} font-normal">r = {{ $p->referenz_pax }}</div></th>@endforeach</tr></thead>
                <tbody>
                    @foreach($k->leistungen->whereNull('konfigurator_konzept_id') as $l)
                        <tr wire:key="kfm-{{ $l->id }}">
                            <td>{{ $l->bezeichnung }}</td>
                            @foreach($pakete as $p)
                                @php $m = $menge($p, $l->id); @endphp
                                <td class="num" wire:key="kfm-{{ $l->id }}-{{ $p->id }}">
                                    <x-fa::input size="sm" class="w-20 text-right {{ $m?->manuell ? 'font-semibold' : '' }}" value="{{ $m?->manuell ? $zahl($m->anzahl) : '' }}"
                                        placeholder="{{ $m ? $zahl($m->regel_anzahl) : '–' }}" wire:change="mengeSetzen({{ $p->id }}, {{ $l->id }}, $event.target.value)"
                                        aria-label="Menge {{ $l->bezeichnung }} in {{ $p->anzeigeName() }}" data-kf-menge="{{ $p->id }}-{{ $l->id }}" />
                                    @if($m?->manuell && (float) $m->anzahl !== (float) $m->regel_anzahl)<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" title="Regel ergibt {{ $zahl($m->regel_anzahl) }}">Regel {{ $zahl($m->regel_anzahl) }}</div>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="{{ $leise }}">Zusatz-Leistungen der Konzepte und Ersetzungen rechnet das System je Konzept; ihre Kosten stehen in der Kalkulation.</p>
    </x-fa::section>
@endif
