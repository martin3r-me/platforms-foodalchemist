{{-- Spec 94 · Konfigurator-Editor, Reiter Kalkulation: Regeln und Preisvorschau Konzept × Paket mit EK, VK, DB und der
     Randkontrolle an Unter- und Obergrenze. Zahlen aus KonfiguratorPricingService::matrix. --}}
@php $prozent = fn ($w) => $w === null ? '–' : number_format((float) $w, 1, ',', '.') . ' %'; @endphp

<x-fa::section title="Regeln" icon="heroicon-o-adjustments-vertical">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,11rem),1fr))] gap-3">
        <x-fa::field label="Einsatz Lunch (h)" for="kf-sl"><x-fa::input id="kf-sl" wire:model="form.stunden_lunch" numeric /></x-fa::field>
        <x-fa::field label="Einsatz Dinner (h)" for="kf-sd"><x-fa::input id="kf-sd" wire:model="form.stunden_dinner" numeric /></x-fa::field>
        <x-fa::field label="Day-into-Night (h)" for="kf-sdn"><x-fa::input id="kf-sdn" wire:model="form.stunden_day_night" numeric /></x-fa::field>
        <x-fa::field label="Kombi-Rabatt %" for="kf-kombi" hint="Day-into-Night, nur auf den Konzeptanteil"><x-fa::input id="kf-kombi" wire:model="form.kombi_rabatt_pct" numeric /></x-fa::field>
        <x-fa::field label="Paket-Toleranz %" for="kf-tol"><x-fa::input id="kf-tol" wire:model="form.paket_toleranz_pct" numeric /></x-fa::field>
        <x-fa::field label="Rundung" for="kf-rund"><x-fa::select id="kf-rund" wire:model="form.rundung" :options="['0.01' => '0,01 €', '0.10' => '0,10 €', '0.50' => '0,50 €', '1.00' => '1,00 €']" /></x-fa::field>
    </div>
    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="form.tageszeit_aktiv" class="accent-[var(--fa-accent)]" /> Kunde wählt im Link die Tageszeit (Lunch, Dinner, Day-into-Night)</label>
    <p class="{{ $leise }}">Regeln wirken nach „Speichern“ auf die Preisvorschau.</p>
</x-fa::section>

@if($matrix['warnungen'] !== [])
    <x-fa::notice tone="warn" title="Warnungen" data-kf-warnungen>
        <ul class="list-disc pl-5">@foreach($matrix['warnungen'] as $w)<li>{{ $w }}</li>@endforeach</ul>
    </x-fa::notice>
@endif

@foreach($matrix['pakete'] as $p)
    <x-fa::section :title="$p['name']" icon="heroicon-o-currency-euro" :meta="'Referenz ' . $p['referenz_pax'] . ' Gäste'" wire:key="kfmx-{{ $p['paket_id'] }}" data-kf-preise="{{ $p['paket_id'] }}">
        <div class="overflow-x-auto">
            <table class="fa-table fa-table--compact">
                <thead><tr>
                    <th>Konzept</th><th class="num">All-in / Person</th><th class="num">davon Speisen</th><th class="num">davon Leistungen</th><th class="num">EK</th><th class="num">DB</th>
                    <th class="num">bei {{ $p['von_pax'] }} Gästen</th><th class="num">bei {{ $p['bis_pax'] }} Gästen</th>
                </tr></thead>
                <tbody>
                    @foreach($p['faelle'] as $i => $f)
                        <tr wire:key="kfmx-{{ $p['paket_id'] }}-{{ $i }}">
                            <td>{{ $f['name'] }}</td>
                            <td class="num font-semibold">{{ $euro($f['preis_pp']) }}</td>
                            <td class="num">{{ $euro($f['speisen_vk']) }}</td>
                            <td class="num">{{ $euro($f['leistungen_vk_pp']) }}</td>
                            <td class="num">{{ $euro($f['ek_pp']) }}</td>
                            <td class="num {{ $f['db_pp'] < 0 ? 'text-[var(--fa-crit)]' : '' }}">{{ $euro($f['db_pp']) }}</td>
                            @foreach(['von', 'bis'] as $seite)
                                @php $r = $f['rand'][$seite]; @endphp
                                <td class="num">
                                    <span title="Genauer Preis bei {{ $r['pax'] }} Gästen">{{ $euro($r['genau']) }}</span>
                                    <div class="{{ $leise }}">{{ $prozent($r['abweichung_pct']) }} · DB <span class="{{ $r['db'] < 0 ? 'text-[var(--fa-crit)]' : '' }}">{{ $euro($r['db']) }}</span></div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fa::section>
@endforeach
