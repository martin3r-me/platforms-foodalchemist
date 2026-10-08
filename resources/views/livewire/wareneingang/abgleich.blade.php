{{-- Spec 75b · Reiter Abgleich: Triple Match je Bestellzeile, schwerste und teuerste Fälle oben. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($v) => $v === null ? '–' : (rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0');
    $ton = ['ok' => 'ok', 'offen' => 'neutral', 'menge' => 'crit', 'preis' => 'warn', 'nicht_geliefert' => 'crit', 'nicht_berechnet' => 'warn', 'zu_wenig' => 'warn', 'zu_viel' => 'info'];
@endphp
<div class="flex flex-col gap-4" data-we-abgleich>
    <x-fa::kpis :items="$kpis" />
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($hinweis)<x-fa::notice tone="ok" data-ab-hinweis>{{ $hinweis }}</x-fa::notice>@endif

    <x-fa::section title="Bestellt · geliefert · berechnet" icon="heroicon-o-scale" :description="'Preis-Toleranz ±' . $zahl($toleranz['pct']) . ' % oder ±' . number_format($toleranz['eur'], 2, ',', '.') . ' € je Gebinde — das Großzügigere gilt. Unterlieferung mit passender Rechnung gilt als erledigt.'">
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Lieferant" for="ab-l" class="w-56">
                <x-fa::select id="ab-l" wire:model.live="fLieferant" placeholder="Alle">@foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</x-fa::select>
            </x-fa::field>
            <x-fa::field label="Zeitraum" for="ab-t" class="w-40">
                <x-fa::select id="ab-t" wire:model.live="tage" :options="[30 => 'letzte 30 Tage', 90 => 'letzte 90 Tage', 365 => 'letztes Jahr']" />
            </x-fa::field>
            <label class="inline-flex items-center gap-2 pb-2 text-[length:var(--fa-text-sm)]"><input type="checkbox" wire:model.live="nurAbweichung"> nur Abweichungen</label>
            @if($istAdmin)
                <span class="flex-1"></span>
                <div class="flex items-end gap-2" data-ab-toleranz>
                    <x-fa::field label="Toleranz %" for="ab-tp" class="w-24"><x-fa::input id="ab-tp" size="sm" numeric wire:model="tolPct" :placeholder="$zahl($toleranz['pct'])" /></x-fa::field>
                    <x-fa::field label="Toleranz €/Geb." for="ab-te" class="w-28"><x-fa::input id="ab-te" size="sm" numeric wire:model="tolEur" :placeholder="number_format($toleranz['eur'], 2, ',', '.')" /></x-fa::field>
                    <x-fa::button size="sm" wire:click="toleranzSpeichern">Speichern</x-fa::button>
                </div>
            @endif
        </div>
        @if($zeilen === [])
            <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine Abweichungen">Alles, was geliefert und berechnet wurde, passt zur Bestellung.</x-fa::empty>
        @else
            <div class="overflow-x-auto">
                <table class="fa-table fa-table--compact min-w-[900px]">
                    <thead><tr><th>Befund</th><th>Lieferant / Bestellung</th><th>Artikel</th><th class="text-right">bestellt</th><th class="text-right">geliefert</th><th class="text-right">berechnet</th><th class="text-right">Preis Best.</th><th class="text-right">Preis RE</th><th class="text-right">Δ €</th><th><span class="sr-only">Aktion</span></th></tr></thead>
                    <tbody>
                        @foreach($zeilen as $z)
                            <tr wire:key="ab-{{ $z['order_line_id'] }}" data-ab-zeile="{{ $z['order_line_id'] }}" data-ab-status="{{ $z['status'] }}">
                                <td><x-fa::badge :tone="$ton[$z['status']] ?? 'neutral'">{{ $z['label'] }}</x-fa::badge></td>
                                <td>{{ $z['lieferant'] }}<span class="{{ $leise }}"> · {{ $z['nummer'] }}@if($z['rechnungen'] !== []) · RE {{ implode(', ', $z['rechnungen']) }}@endif</span></td>
                                <td class="font-medium">{{ $z['designation'] }}</td>
                                <td class="text-right tabular-nums">{{ $zahl($z['bestellt']) }}</td>
                                <td class="text-right tabular-nums {{ $z['ls'] !== 'ok' && $z['ls'] !== 'offen' ? 'text-[var(--fa-warn)]' : '' }}">{{ $zahl($z['geliefert']) }}</td>
                                <td class="text-right tabular-nums {{ in_array($z['re'], ['menge', 'nicht_geliefert'], true) ? 'text-[var(--fa-crit)] font-medium' : '' }}">{{ $zahl($z['berechnet']) }}</td>
                                <td class="text-right tabular-nums">{{ $z['preis_bestellt'] !== null ? number_format($z['preis_bestellt'], 2, ',', '.') : '–' }}</td>
                                <td class="text-right tabular-nums {{ $z['re'] === 'preis' ? 'text-[var(--fa-warn)] font-medium' : '' }}">{{ $z['preis_rechnung'] !== null ? number_format($z['preis_rechnung'], 2, ',', '.') : '–' }}</td>
                                <td class="text-right tabular-nums {{ $z['delta_eur'] > 0.009 ? 'text-[var(--fa-crit)]' : '' }}">{{ abs($z['delta_eur']) >= 0.01 ? number_format($z['delta_eur'], 2, ',', '.') : '–' }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if($z['claim_status'])
                                        <x-fa::badge tone="info">reklamiert</x-fa::badge>
                                    @elseif($darf && ! in_array($z['status'], ['ok', 'offen'], true))
                                        <x-fa::button size="sm" wire:click="reklamieren({{ $z['order_line_id'] }})" wire:confirm="Reklamation für {{ $z['designation'] }} anlegen?">Reklamieren</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
</div>
