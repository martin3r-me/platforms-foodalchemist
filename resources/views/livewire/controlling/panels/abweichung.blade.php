{{-- Spec 32 · C4 — die echte Wareneinsatzquote und die Abweichung zur Rezeptur.
     Überall sonst im Modul steht der KALKULIERTE Wareneinsatz; hier steht der gemessene.

     fa-pass 2026-10-05: Tokens + Kennzahl-Leiste (fa-kpis-Optik, Hauptzahl: Wareneinsatz Ist; die
     Ampel steht in der Zeile „gegen Ziel", nie zwei Alarmfarben). Zeitraum mit Labels oben,
     fehlende Werte als „nicht belastbar" statt 0. data-Marker und wire:-Bindungen unverändert. --}}
@php
    $eur = fn ($v) => $v === null ? null : number_format((float) $v, 2, ',', '.') . ' €';
    $pct = fn ($v) => $v === null ? null : number_format((float) $v, 1, ',', '.') . ' %';
    $pp = fn ($v) => ($v > 0 ? '+' : '') . number_format((float) $v, 1, ',', '.') . ' Prozentpunkte';
@endphp

<div class="flex flex-col gap-3" data-ctrl-abweichung>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
            Einkauf gegen Verkauf im gewählten Zeitraum. Die einzige Stelle, an der der Wareneinsatz
            <strong class="font-semibold text-[var(--fa-ink-2)]">gemessen</strong> und nicht aus Rezepturen gerechnet wird.
        </p>
        <div class="flex items-end gap-2">
            <x-fa::field label="Von" for="ctrl-abw-von">
                <x-fa::input type="date" id="ctrl-abw-von" wire:model.live="von" class="w-36" data-ctrl-abw-von />
            </x-fa::field>
            <x-fa::field label="Bis" for="ctrl-abw-bis">
                <x-fa::input type="date" id="ctrl-abw-bis" wire:model.live="bis" class="w-36" />
            </x-fa::field>
            <x-fa::button variant="ghost" icon="heroicon-o-calendar" wire:click="vormonat">Vormonat</x-fa::button>
        </div>
    </div>

    @if($a === null)
        <x-fa::empty compact icon="heroicon-o-user-group" title="Kein Team zugeordnet">
            Ohne Team gibt es weder Einkauf noch Verkauf. Wähle oben ein Team aus.
        </x-fa::empty>
    @else
        @php
            $delta = $a['ist_delta_pp'];
            $deltaTon = $delta === null ? null : ($delta > 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]');
            $werte = [
                'ist' => $pct($a['ist_pct']),
                'umsatz' => $eur($a['umsatz']),
                'einkauf' => $eur($a['einkauf']),
                'theoretisch' => $a['theoretisch'] > 0 ? $eur($a['theoretisch']) : null,
                'abweichung' => $a['abweichung_eur'] === null ? null : ($a['abweichung_eur'] > 0 ? '+' : '') . $eur($a['abweichung_eur']),
                'abdeckung' => $pct($a['abdeckung_pct']),
            ];
        @endphp
        <dl class="fa-kpis">
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">Wareneinsatz Ist</dt>
                {{-- Der Leitwert: Ist gegen Ziel. Nur hier eine Ampel, nie zwei nebeneinander. --}}
                <dd class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight leading-tight tabular-nums text-[var(--fa-accent)]">@if($werte['ist'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['ist'] }}@endif</dd>
                <dd class="text-[length:var(--fa-text-sm)] tabular-nums {{ $deltaTon ?? 'text-[var(--fa-ink-3)]' }}">
                    Ziel {{ $pct($a['ziel_pct']) ?? 'nicht gesetzt' }}@if($delta !== null) · {{ $pp($delta) }}@endif
                </dd>
            </div>
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">Umsatz</dt>
                <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]">@if($werte['umsatz'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['umsatz'] }}@endif</dd>
            </div>
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">{{ $a['mit_bestand'] ? 'Verbrauch' : 'Einkauf' }}</dt>
                @if($a['mit_bestand'])
                    {{-- Spec 66 §5: Inventur an beiden Rändern → Verbrauch statt Einkauf --}}
                    <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]" data-ctrl-abw-verbrauch>{{ $eur($a['verbrauch']) }}</dd>
                    <dd class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">AB {{ $eur($a['bestand_anfang']) }} + Einkauf {{ $werte['einkauf'] }} − EB {{ $eur($a['bestand_ende']) }}</dd>
                @else
                    <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]">@if($werte['einkauf'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['einkauf'] }}@endif</dd>
                @endif
            </div>
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">Laut Rezeptur</dt>
                {{-- 0,00 € sähe aus wie ein Messwert. Ohne belastbare Datenlage steht hier
                     nichts; dieselbe Zurückhaltung wie bei der Abweichung daneben. --}}
                <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]">@if($werte['theoretisch'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['theoretisch'] }}@endif</dd>
            </div>
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">Abweichung</dt>
                <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]" data-ctrl-abw-wert>
                    @if($werte['abweichung'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['abweichung'] }}@endif
                </dd>
                @if($a['abweichung_pp'] !== null)
                    <dd class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $pp($a['abweichung_pp']) }} vom Umsatz</dd>
                @endif
            </div>
            <div class="fa-kpi">
                <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">Zugeordneter Umsatz</dt>
                <dd class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums text-[var(--fa-ink)]">@if($werte['abdeckung'] === null)<span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-3)]">nicht belastbar</span>@else{{ $werte['abdeckung'] }}@endif</dd>
                <dd class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">hängt an einem Gericht</dd>
            </div>
        </dl>

        @php $ab = $a['abgaenge'] ?? null; $benannt = $ab !== null ? $ab['schwund'] + $ab['personal'] + $ab['probe'] : 0; @endphp
        @if($ab !== null && $benannt > 0)
            {{-- Spec 67: gebuchte Abgänge mit Grund — der benannte Teil der Abweichung --}}
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-ctrl-abw-abgaenge>
                <span class="font-medium">Gebuchte Abgänge im Zeitraum:</span>
                @if($ab['schwund'] > 0)<span>Verderb, Bruch, Schwund <strong class="tabular-nums">{{ $eur($ab['schwund']) }}</strong></span>@endif
                @if($ab['personal'] > 0)<span>Personalessen <strong class="tabular-nums">{{ $eur($ab['personal']) }}</strong></span>@endif
                @if($ab['probe'] > 0)<span>Probe, Verkostung <strong class="tabular-nums">{{ $eur($ab['probe']) }}</strong></span>@endif
                @if($a['abweichung_eur'] !== null && $a['abweichung_eur'] > 0)
                    <span class="text-[var(--fa-ink-3)]">= {{ number_format(min(100, $benannt / $a['abweichung_eur'] * 100), 0, ',', '.') }} % der Abweichung erklärt</span>
                @endif
            </div>
        @endif

        @if($a['hinweis'])
            <x-fa::notice tone="warn" data-ctrl-abw-hinweis>{{ $a['hinweis'] }}</x-fa::notice>
        @elseif($a['abweichung_eur'] !== null)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
                @if($a['mit_bestand'])
                    @if($a['abweichung_eur'] > 0)
                        Es wurde mehr verbraucht, als die Rezepturen für den verkauften Absatz hergeben.
                        Übliche Ursachen sind Verschnitt, Verderb, Überproduktion oder zu knapp kalkulierte Rezeptmengen.
                    @else
                        Es wurde weniger verbraucht als rechnerisch nötig, meist durch zu hoch angesetzte Rezeptmengen.
                    @endif
                    Verbrauch aus den Inventuren vom {{ \Illuminate\Support\Carbon::parse($a['inventur_anfang'])->format('d.m.Y') }}
                    und {{ \Illuminate\Support\Carbon::parse($a['inventur_ende'])->format('d.m.Y') }}, Lagerauf- und -abbau sind herausgerechnet.
                @else
                    @if($a['abweichung_eur'] > 0)
                        Es wurde mehr eingekauft, als die Rezepturen für den verkauften Absatz hergeben.
                        Übliche Ursachen sind Verschnitt, Verderb, Überproduktion oder Lageraufbau.
                    @else
                        Es wurde weniger eingekauft als rechnerisch nötig, meist durch Lagerabbau oder eine
                        zu hoch angesetzte Rezeptmenge.
                    @endif
                    Ohne Inventur am Anfang und Ende des Zeitraums bleibt das eine Rechnung über den Einkauf.
                    Mit gebuchten Inventuren (Lager) rechnet die Analyse mit dem echten Verbrauch.
                @endif
            </p>
        @endif

        @if($jeStandort !== [])
            <x-fa::section title="Je Standort" icon="heroicon-o-building-office-2" data-ctrl-abw-standorte
                description="Oben die Summe aller Standorte, hier jeder Standort mit seinen eigenen Einkaufspreisen und Zielwerten.">
                <div class="overflow-x-auto">
                    <table class="fa-table fa-table--compact">
                        <thead><tr><th>Standort</th><th class="text-right">Umsatz</th><th class="text-right">Einkauf</th><th class="text-right">Wareneinsatz Ist</th><th class="text-right">Ziel</th><th class="text-right">Abweichung</th></tr></thead>
                        <tbody>
                            @foreach($jeStandort as $s)
                                @php $w = $s['wert']; @endphp
                                <tr wire:key="abw-st-{{ $s['team_id'] }}" data-ctrl-abw-standort="{{ $s['team_id'] }}">
                                    <td class="font-medium">{{ $s['standort'] }}</td>
                                    <td class="text-right tabular-nums">{{ $eur($w['umsatz']) ?? '–' }}</td>
                                    <td class="text-right tabular-nums">{{ $eur($w['einkauf']) ?? '–' }}</td>
                                    <td class="text-right tabular-nums">{{ $pct($w['ist_pct']) ?? 'nicht belastbar' }}</td>
                                    <td class="text-right tabular-nums">{{ $pct($w['ziel_pct']) }}</td>
                                    <td class="text-right tabular-nums">{{ $eur($w['abweichung_eur']) ?? '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-fa::section>
        @endif
    @endif
</div>
