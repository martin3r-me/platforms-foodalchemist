{{-- Signal-Verlauf: Bestand je Metrik gegen den vorigen Detektor-Lauf.

     `SignalTrendService::uebersicht()` war gebaut, hatte aber bis Spec 32 KEINE Fläche — die
     Zeitreihe lag nur hinter dem MCP-Tool. Genau das ist der Unterschied zwischen Momentaufnahme
     und Controlling: nicht „wie viele offene Befunde", sondern „wird es besser oder schlechter".

     Die Snapshots schreibt der nächtliche Detektor (03:20). Ohne zwei Läufe gibt es kein Delta —
     das wird ausgewiesen statt mit einer 0 überspielt. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(($verlauf['measured_at'] ?? null) === null)
    <p class="text-xs text-gray-500">
        Noch keine Messreihe — der Verlauf entsteht mit dem nächtlichen Signal-Detektor.
    </p>
@else
    <div data-ctrl-verlauf>
        <p class="text-[11px] text-gray-500 mb-3">
            Stand {{ $verlauf['measured_at'] }}@if($verlauf['previous_at']) gegen {{ $verlauf['previous_at'] }}@endif.
            @if(! $verlauf['previous_at'])
                Erster Lauf — ein Delta gibt es ab der zweiten Messung.
            @endif
        </p>

        {{-- Kurzform-`@php(...)` statt Block: ein Block-@php darf in dieser Datei nur ÜBER allen
             Kurzformen stehen (Raw-Block-Falle, BladeCompilesTest). Oben steht schon eine.

             Der Dienst liefert JEDE gemessene Metrik, auch die dauerhaft auf null stehenden —
             im Dev-Bestand über 20 Zeilen, die Hälfte leer. Gezeigt wird, was einen Bestand hat
             oder sich bewegt hat; der Rest nur als Zahl. --}}
        @php($sichtbar = array_values(array_filter($verlauf['metriken'], fn ($m) => $m['count'] > 0 || ($m['delta'] ?? 0) != 0)))
        @php(usort($sichtbar, fn ($a, $b) => abs($b['delta'] ?? 0) <=> abs($a['delta'] ?? 0) ?: $b['count'] <=> $a['count']))
        @php($ruhig = count($verlauf['metriken']) - count($sichtbar))
        {{-- Mehrere Metriken teilen sich ein Label (z. B. drei Ausprägungen von „EK-Kette
             unvollständig"). Ohne den technischen Schlüssel daneben stehen identische Zeilen
             mit verschiedenen Zahlen — unlesbar. --}}
        @php($mehrfach = array_keys(array_filter(array_count_values(array_column($sichtbar, 'label')), fn ($n) => $n > 1)))

        @if($sichtbar === [])
            <p class="text-xs text-gray-500">
                Alle {{ count($verlauf['metriken']) }} gemessenen Metriken stehen auf null — nichts offen.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} text-left">Metrik</th>
                            <th class="{{ $th }} text-right">Bestand</th>
                            <th class="{{ $th }} text-right">Vorher</th>
                            <th class="{{ $th }} text-right">Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sichtbar as $m)
                            @php($d = $m['delta'])
                            <tr class="{{ $tr }}">
                                <td class="{{ $td }} text-gray-900">
                                    {{ $m['label'] }}
                                    @if(in_array($m['label'], $mehrfach, true))
                                        <span class="text-[10px] text-gray-500">· {{ $m['metric_key'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-900">{{ number_format($m['count'], 0, ',', '.') }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $m['previous'] === null ? '—' : number_format($m['previous'], 0, ',', '.') }}</td>
                                {{-- Weniger offene Befunde = besser, deshalb ist ein negatives Delta grün. --}}
                                <td class="{{ $td }} text-right tabular-nums font-medium {{ $d === null ? 'text-gray-400' : ($d < 0 ? 'text-emerald-700' : ($d > 0 ? 'text-amber-700' : 'text-gray-500')) }}">
                                    {{ $d === null ? '—' : ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') }}@if($m['pct'] !== null)<span class="text-[10px] text-gray-400 ml-1">({{ ($m['pct'] > 0 ? '+' : '') . number_format($m['pct'], 1, ',', '.') }} %)</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($ruhig > 0)
            <p class="text-[10px] text-gray-500 mt-1">{{ $ruhig }} weitere Metriken stehen auf null.</p>
        @endif

        <a href="{{ route('foodalchemist.review') }}" class="{{ $btnGhostXs }} mt-3" wire:navigate>
            Alle Signale ansehen →
        </a>
    </div>
@endif
