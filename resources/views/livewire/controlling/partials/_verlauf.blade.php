{{-- Signal-Verlauf: Bestand je Metrik gegen den vorigen Detektor-Lauf.

     `SignalTrendService::uebersicht()` war gebaut, hatte aber bis Spec 32 KEINE Fläche; die
     Zeitreihe lag nur hinter dem MCP-Tool. Genau das ist der Unterschied zwischen Momentaufnahme
     und Controlling: nicht „wie viele offene Befunde", sondern „wird es besser oder schlechter".

     Die Snapshots schreibt der nächtliche Detektor (03:20). Ohne zwei Läufe gibt es kein Delta;
     das wird ausgewiesen statt mit einer 0 überspielt.

     fa-pass 2026-10-05: Tokens + fa-table, Veränderung als Zustandsfarbe mit Pfeil, technischer
     Schlüssel bei gleichlautenden Zeilen lesbar gemacht. --}}
@if(($verlauf['measured_at'] ?? null) === null)
    <x-fa::empty compact icon="heroicon-o-arrow-trending-down" title="Noch keine Messreihe">
        Der Verlauf entsteht mit der nächtlichen Prüfung. Nach zwei Läufen zeigt er, ob die offenen Befunde mehr oder weniger werden.
    </x-fa::empty>
@else
    <div data-ctrl-verlauf class="flex flex-col gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Offene Befunde je Art, Stand {{ \Illuminate\Support\Carbon::parse($verlauf['measured_at'])->format('d.m.Y H:i') }}@if($verlauf['previous_at']) gegen {{ \Illuminate\Support\Carbon::parse($verlauf['previous_at'])->format('d.m.Y H:i') }}@endif.
            Weniger ist besser.
            @if(! $verlauf['previous_at'])
                Erster Lauf: eine Veränderung gibt es ab der zweiten Messung.
            @endif
        </p>

        {{-- Kurzform-`@php(...)` statt Block: ein Block-@php darf in dieser Datei nur ÜBER allen
             Kurzformen stehen (Raw-Block-Falle, BladeCompilesTest).

             Der Dienst liefert JEDE gemessene Metrik, auch die dauerhaft auf null stehenden;
             im Dev-Bestand über 20 Zeilen, die Hälfte leer. Gezeigt wird, was einen Bestand hat
             oder sich bewegt hat; der Rest nur als Zahl. --}}
        @php($sichtbar = array_values(array_filter($verlauf['metriken'], fn ($m) => $m['count'] > 0 || ($m['delta'] ?? 0) != 0)))
        @php(usort($sichtbar, fn ($a, $b) => abs($b['delta'] ?? 0) <=> abs($a['delta'] ?? 0) ?: $b['count'] <=> $a['count']))
        @php($ruhig = count($verlauf['metriken']) - count($sichtbar))
        {{-- Mehrere Metriken teilen sich ein Label (z. B. drei Ausprägungen von „EK-Kette
             unvollständig"). Ohne Unterscheidung daneben stehen identische Zeilen mit
             verschiedenen Zahlen; der Schlüssel wird lesbar gemacht statt roh gezeigt. --}}
        @php($mehrfach = array_keys(array_filter(array_count_values(array_column($sichtbar, 'label')), fn ($n) => $n > 1)))

        @if($sichtbar === [])
            <x-fa::empty compact icon="heroicon-o-check-badge" title="Nichts offen">
                Alle {{ count($verlauf['metriken']) }} gemessenen Befund-Arten stehen auf null.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th>Befund</th>
                            <th class="num">Offen jetzt</th>
                            <th class="num">Offen vorher</th>
                            <th class="num">Veränderung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sichtbar as $m)
                            @php($d = $m['delta'])
                            <tr wire:key="vl-{{ $m['metric_key'] }}">
                                <td class="min-w-[16rem]">
                                    {{ $m['label'] }}
                                    @if(in_array($m['label'], $mehrfach, true))
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">({{ \Illuminate\Support\Str::ucfirst(trim(str_replace(['_', '.', ':'], ' ', $m['metric_key']))) }})</span>
                                    @endif
                                </td>
                                <td class="num font-semibold">{{ number_format($m['count'], 0, ',', '.') }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $m['previous'] === null ? '–' : number_format($m['previous'], 0, ',', '.') }}</td>
                                {{-- Weniger offene Befunde = besser, deshalb ist eine negative Veränderung grün. --}}
                                <td class="num font-medium {{ $d === null ? 'text-[var(--fa-ink-3)]' : ($d < 0 ? 'text-[var(--fa-ok)]' : ($d > 0 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]')) }}">
                                    @if($d === null)
                                        –
                                    @else
                                        <span class="inline-flex items-center gap-1">
                                            @if($d < 0)@svg('heroicon-m-arrow-down', 'w-3.5 h-3.5')@elseif($d > 0)@svg('heroicon-m-arrow-up', 'w-3.5 h-3.5')@endif
                                            {{ ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') }}
                                        </span>
                                        @if($m['pct'] !== null)<span class="ml-1 text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)]">({{ ($m['pct'] > 0 ? '+' : '') . number_format($m['pct'], 1, ',', '.') }} %)</span>@endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2">
            @if($ruhig > 0)
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $ruhig }} weitere Befund-Arten stehen auf null.</p>
            @else
                <span></span>
            @endif
            <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-right" :href="route('foodalchemist.review')" wire:navigate>
                Alle Signale ansehen
            </x-fa::button>
        </div>
    </div>
@endif
