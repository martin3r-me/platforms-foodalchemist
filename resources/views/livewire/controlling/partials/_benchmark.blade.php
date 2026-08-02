{{-- R2.7 Portfolio-Benchmark: eigenes Team gegen den anonymisierten Peer-Median derselben
     Root-Kette. Spec 32: vom Dashboard hierher gezogen — ein Vergleich der eigenen Wirtschaft-
     lichkeit gehört ins Controlling, nicht in die Bestandsübersicht.

     Datenschutz-Grenze (hart, aus BenchmarkService): nur Aggregate, keine Peer-Namen, keine
     Fremd-Gericht-Details, nur innerhalb einer Root-Kette. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if($benchmark === null || ($benchmark['team_kpis']['n_dishes'] ?? 0) === 0)
    <p class="text-xs text-gray-500">
        Noch kein Portfolio — der Benchmark braucht mindestens ein Verkaufsgericht im eigenen Team.
    </p>
@else
    <div data-ctrl-benchmark>
        <p class="text-[11px] text-gray-500 mb-3">
            {{ $benchmark['n_peers'] > 0
                ? 'Vergleich gegen den Median von ' . $benchmark['n_peers'] . ' anonymen Peer-Team(s) derselben Gruppe.'
                : 'Kein Peer-Team mit Portfolio — der Vergleich greift erst ab zwei Teams mit Gerichten.' }}
        </p>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2">
            @foreach($benchmark['kennzahlen'] as $key => $meta)
                @php($eigen = $benchmark['team_kpis'][$key])
                @php($peer = $benchmark['peer_median'][$key])
                @php($besserHoch = $meta['besser'] === 'hoch')
                {{-- Nur einfärben, wenn beide Seiten einen Wert haben — sonst behauptet eine grüne
                     Zahl einen Vorsprung, den niemand gemessen hat. --}}
                @php($vgl = ($eigen !== null && $peer !== null) ? ($besserHoch ? $eigen <=> $peer : $peer <=> $eigen) : 0)
                @php($nk = $meta['unit'] === '' ? 0 : 1)
                <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                    <div class="{{ $label }}">{{ $meta['label'] }}</div>
                    <div class="text-lg font-semibold tabular-nums {{ $vgl > 0 ? 'text-emerald-600' : ($vgl < 0 ? 'text-amber-600' : 'text-gray-900') }}">
                        {{ $eigen !== null ? number_format((float) $eigen, $nk, ',', '.') . $meta['unit'] : '—' }}
                    </div>
                    <div class="text-[10px] text-gray-500">Peer-Median: {{ $peer !== null ? number_format((float) $peer, $nk, ',', '.') . $meta['unit'] : '—' }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif
