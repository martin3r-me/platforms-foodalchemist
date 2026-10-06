{{-- R2.2: Was-wäre-wenn-Preissimulation. Nur lesend: hypothetisches Preisszenario
     (Warengruppe | Grundprodukt | Artikel | Lieferant, ± X %) → Marge-Veränderung übers Portfolio
     + die am stärksten betroffenen Gerichte. Spiegelt das MCP-Tool foodalchemist.simulation.POST.
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt. Sitzt im Controlling-Reiter
     „Was wäre wenn" (dessen Abschnitt trägt Titel und Rahmen), daher hier keine eigene Karte mehr.
     Anordnung unverändert (Szenario · Kennzahlen · Gerichte · Ersatz). wire:-Bindungen unverändert.
     #502: KEIN overflow-hidden um die Grundprodukt-Suche, sonst schneidet es die Trefferliste ab. --}}
@php
    $ebenen = ['warengruppe' => 'Warengruppe', 'gp' => 'Grundprodukt', 'artikel' => 'Lieferantenartikel', 'lieferant' => 'Lieferant (ganzes Sortiment)'];
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<div class="flex flex-col gap-4 min-w-0" wire:key="sim-panel" data-simulation-panel>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[70ch]">
            Einen Preissprung durchspielen und sofort sehen, wie sich die Marge über alle Gerichte verschiebt.
            Es werden keine Preise verändert.
        </p>
        <x-fa::badge tone="info" icon="heroicon-m-eye" title="Diese Simulation verändert keine Daten.">Nur Berechnung</x-fa::badge>
    </div>

    {{-- Szenario --}}
    <div class="flex flex-col gap-3">
        {{-- Spec 32: „Lieferant kündigt 5 % an" ist die praxisnächste Frage im Einkauf. --}}
        <x-fa::choice name="scope" label="Preisänderung bei" idPrefix="sim" :options="$ebenen" />

        <div class="grid gap-3 items-end grid-cols-1 md:grid-cols-[minmax(0,1fr)_9rem_auto]">
            <x-fa::field :label="$ebenen[$scope] ?? 'Bezug'" for="sim-bezug" error="ref">
                @if($scope === 'warengruppe')
                    <x-fa::select id="sim-bezug" wire:model="ref">
                        <option value="">Warengruppe wählen</option>
                        @foreach($warengruppen as $wg)
                            <option value="{{ $wg->code }}">{{ $wg->code }} · {{ $wg->name }}</option>
                        @endforeach
                    </x-fa::select>

                @elseif($scope === 'gp')
                    @if($ref !== '' && $refLabel !== '')
                        <div class="flex items-center gap-2 h-9">
                            <x-fa::badge tone="accent">{{ $refLabel }}</x-fa::badge>
                            <x-fa::button size="sm" variant="ghost" wire:click="zuruecksetzen">Anderes wählen</x-fa::button>
                        </div>
                    @else
                        <div class="relative">
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input id="sim-bezug" type="search" wire:model.live.debounce.300ms="gpQuery" class="pl-8" placeholder="Grundprodukt suchen (ab 2 Zeichen)" />
                            @if(count($gpTreffer))
                                <div class="absolute z-20 mt-1 w-full fa-surface shadow-lg max-h-64 overflow-auto py-1" role="listbox">
                                    @foreach($gpTreffer as $t)
                                        <button type="button" role="option" wire:click="waehleGp({{ $t['id'] }}, @js($t['name']))"
                                                class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                            <span class="min-w-0 break-words">{{ $t['name'] }}</span>
                                            @unless($t['hat_lead'])
                                                <x-fa::badge tone="warn" title="Ohne bevorzugten Lieferantenartikel: kein Preis, der sich ändern könnte">ohne Artikel</x-fa::badge>
                                            @endunless
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                @elseif($scope === 'lieferant')
                    <x-fa::select id="sim-bezug" wire:model="ref">
                        <option value="">Lieferant wählen</option>
                        @foreach($lieferanten as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </x-fa::select>

                @else
                    <x-fa::input id="sim-bezug" type="number" numeric wire:model="ref" placeholder="Nummer des Lieferantenartikels" />
                @endif
            </x-fa::field>

            <x-fa::field label="Änderung (%)" for="sim-delta" error="deltaPct" hint="Minus für günstiger.">
                <x-fa::input id="sim-delta" type="number" step="1" numeric wire:model="deltaPct" />
            </x-fa::field>

            <div class="md:pb-6">
                <x-fa::button variant="primary" icon="heroicon-m-play" wire:click="simuliere" wire:loading.attr="disabled" class="w-full md:w-auto">
                    <span wire:loading.remove wire:target="simuliere">Simulieren</span>
                    <span wire:loading wire:target="simuliere">Rechnet …</span>
                </x-fa::button>
            </div>
        </div>
    </div>

    {{-- Ergebnis --}}
    @if($result !== null)
        @php
            $teurer = (float) $deltaPct > 0;
            $md = (float) ($result['marge_delta_eur'] ?? 0);
            $mdTon = $md < 0 ? 'crit' : ($md > 0 ? 'ok' : null);
            $bezugText = match ($result['scope'] ?? $scope) {
                'gp' => $refLabel !== '' ? $refLabel : (string) $result['ref'],
                'warengruppe' => optional(collect($warengruppen)->firstWhere('code', $result['ref']))->name ?? (string) $result['ref'],
                'lieferant' => optional(collect($lieferanten)->firstWhere('id', (int) $result['ref']))->name ?? (string) $result['ref'],
                default => 'Artikel ' . $result['ref'],
            };
        @endphp

        <div class="flex flex-col gap-3 pt-4 border-t border-[var(--fa-line)]" data-simulation-ergebnis>
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                {{ $ebenen[$result['scope'] ?? $scope] ?? 'Bezug' }} <span class="font-medium text-[var(--fa-ink)]">{{ $bezugText }}</span>
                <span class="tabular-nums">{{ $teurer ? '+' : '' }}{{ number_format((float) $deltaPct, 1, ',', '.') }} %</span>:
                {{ $teurer ? 'die Marge sinkt' : 'die Marge steigt' }} in den betroffenen Gerichten.
            </p>

            <x-fa::kpis :items="[
                ['label' => 'Marge-Veränderung', 'value' => ($md > 0 ? '+' : '') . number_format($md, 2, ',', '.') . ' €', 'primary' => true, 'tone' => $mdTon, 'kpi' => 'marge-delta'],
                ['label' => 'Betroffene Gerichte', 'value' => number_format((int) ($result['n_gerichte'] ?? 0), 0, ',', '.'), 'kpi' => 'gerichte'],
                ['label' => 'Konzepte', 'value' => number_format((int) ($result['n_concepts'] ?? 0), 0, ',', '.'), 'kpi' => 'konzepte'],
                ['label' => 'Grundprodukte im Szenario', 'value' => number_format((int) ($result['n_gps'] ?? 0), 0, ',', '.'), 'kpi' => 'gps'],
                ['label' => 'Preisfaktor', 'value' => '× ' . number_format((float) ($result['ratio'] ?? 1), 3, ',', '.'), 'kpi' => 'faktor'],
            ]" />

            @if(count($result['top'] ?? []))
                <div class="fa-surface min-w-0">
                    <p class="px-4 pt-3 pb-1 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Am stärksten betroffene Gerichte</p>
                    <div class="overflow-x-auto">
                        <table class="fa-table">
                            <thead>
                                <tr>
                                    <th class="w-full">Gericht</th>
                                    <th class="num">Marge heute → danach</th>
                                    <th class="num">Veränderung</th>
                                    <th class="num">Wareneinsatz danach</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($result['top'] as $r)
                                    @php
                                        $rd = (float) ($r['marge_delta_eur'] ?? 0);
                                    @endphp
                                    <tr wire:key="sim-top-{{ $r['recipe_id'] }}">
                                        <td class="min-w-[12rem]">
                                            <a href="{{ route('foodalchemist.verkauf.index', ['rezept' => $r['recipe_id']]) }}" class="font-medium text-[var(--fa-accent)] hover:underline" wire:navigate>{{ $r['name'] }}</a>
                                        </td>
                                        <td class="num">{{ number_format((float) $r['marge_pct_ist'], 1, ',', '.') }} → {{ number_format((float) $r['marge_pct_hypo'], 1, ',', '.') }} %</td>
                                        <td class="num font-medium {{ $rd < 0 ? 'text-[var(--fa-crit)]' : ($rd > 0 ? 'text-[var(--fa-ok)]' : '') }}">{{ ($rd > 0 ? '+' : '') . number_format($rd, 2, ',', '.') }} €</td>
                                        <td class="num">{{ number_format((float) $r['wareneinsatz_pct_hypo'], 1, ',', '.') }} %</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <x-fa::notice tone="info">Keine Gerichte mit Verkaufspreis betroffen. In die Marge-Veränderung fließen nur Gerichte mit hinterlegtem Verkaufspreis ein.</x-fa::notice>
            @endif

            @if(count($result['substitutions'] ?? []))
                <div class="flex flex-col gap-2">
                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Mögliche Ausweichprodukte</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($result['substitutions'] as $s)
                            <x-fa::badge icon="heroicon-m-arrows-right-left">{{ $s['alt_name'] }}</x-fa::badge>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
