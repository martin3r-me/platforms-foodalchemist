{{-- R2.7 Portfolio-Benchmark: eigenes Team gegen den anonymisierten Peer-Median derselben
     Root-Kette. Spec 32: vom Dashboard hierher gezogen; ein Vergleich der eigenen Wirtschaft-
     lichkeit gehört ins Controlling, nicht in die Bestandsübersicht.

     Datenschutz-Grenze (hart, aus BenchmarkService): nur Aggregate, keine Peer-Namen, keine
     Fremd-Gericht-Details, nur innerhalb einer Root-Kette.

     fa-pass 2026-10-05: Tokens + Bausteine, Vergleich als Tabelle (eigener Wert · Gruppe ·
     Einordnung) statt Kachelwand; Einordnung nur, wenn beide Seiten einen Wert haben. --}}
@if($benchmark === null || ($benchmark['team_kpis']['n_dishes'] ?? 0) === 0)
    <x-fa::empty compact icon="heroicon-o-scale" title="Noch kein Portfolio zum Vergleichen">
        Der Vergleich braucht mindestens ein Verkaufsgericht im eigenen Team. Lege unter Gerichte ein Gericht mit Verkaufspreis an.
    </x-fa::empty>
@else
    <div data-ctrl-benchmark class="flex flex-col gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            {{ $benchmark['n_peers'] > 0
                ? 'Eigene Werte gegen den Median von ' . $benchmark['n_peers'] . ' ' . ($benchmark['n_peers'] === 1 ? 'anderen Team' : 'anderen Teams') . ' derselben Gruppe. Namen und Gerichte der anderen bleiben verborgen.'
                : 'Noch kein anderes Team der Gruppe mit Portfolio. Der Vergleich greift ab zwei Teams mit Gerichten.' }}
        </p>
        <div class="overflow-x-auto">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th>Kennzahl</th>
                        <th class="num">Eigener Wert</th>
                        <th class="num">Median der Gruppe</th>
                        <th>Einordnung</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($benchmark['kennzahlen'] as $key => $meta)
                        @php($eigen = $benchmark['team_kpis'][$key])
                        @php($peer = $benchmark['peer_median'][$key])
                        @php($besserHoch = $meta['besser'] === 'hoch')
                        {{-- Nur einordnen, wenn beide Seiten einen Wert haben: sonst behauptet eine grüne
                             Zahl einen Vorsprung, den niemand gemessen hat. --}}
                        @php($vgl = ($eigen !== null && $peer !== null) ? ($besserHoch ? $eigen <=> $peer : $peer <=> $eigen) : null)
                        @php($nk = $meta['unit'] === '' ? 0 : 1)
                        <tr wire:key="bm-{{ $key }}">
                            <td class="font-medium">{{ $meta['label'] }}</td>
                            <td class="num font-semibold">{{ $eigen !== null ? number_format((float) $eigen, $nk, ',', '.') . ($meta['unit'] !== '' ? ' ' . $meta['unit'] : '') : '–' }}</td>
                            <td class="num text-[var(--fa-ink-2)]">{{ $peer !== null ? number_format((float) $peer, $nk, ',', '.') . ($meta['unit'] !== '' ? ' ' . $meta['unit'] : '') : '–' }}</td>
                            <td>
                                @if($vgl === null)
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kein Vergleich möglich</span>
                                @elseif($vgl > 0)
                                    <x-fa::badge tone="ok">Besser als die Gruppe</x-fa::badge>
                                @elseif($vgl < 0)
                                    <x-fa::badge tone="warn">Schwächer als die Gruppe</x-fa::badge>
                                @else
                                    <x-fa::badge>Gleichauf</x-fa::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
