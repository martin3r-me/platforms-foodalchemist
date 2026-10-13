{{-- Spec 82 §13 D/C1 — Plan und Nutzung (lesend, nur Gebühren und Mengen). --}}
<div class="flex flex-col gap-3" data-settings-plan-nutzung>
    @if($fehler)
        <x-fa::notice tone="crit" data-plan-nutzung-fehler>{{ $fehler }}</x-fa::notice>
    @elseif($daten)
        <x-fa::section title="{{ $daten['name'] }} · {{ \Carbon\Carbon::parse($daten['monat'].'-01')->locale('de')->translatedFormat('F Y') }}" icon="heroicon-o-receipt-percent" data-plan-nutzung-monat
            description="Was im Food Alchemist monatlich berechnet wird. Preise netto. Unter-Teams zahlen nicht selbst, ihre Gebühr läuft über dieses Haupt-Team.">
            <div class="flex items-center gap-2 pb-3">
                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-chevron-left" wire:click="vorher" data-plan-nutzung-vorher>Vormonat</x-fa::button>
                <x-fa::button size="sm" variant="ghost" wire:click="nachher" data-plan-nutzung-nachher>Folgemonat</x-fa::button>
            </div>
            @if($daten['positionen'] === [])
                <x-fa::empty compact icon="heroicon-o-receipt-percent" title="Keine Positionen">In diesem Monat wurde noch nichts gezählt.</x-fa::empty>
            @else
                <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-plan-nutzung-tabelle>
                    <thead><tr><th>Position</th><th class="text-right">Menge</th><th class="text-right">€ / Monat</th><th class="text-right">Tage gebucht</th><th class="text-right">Gebucht bisher</th><th class="text-right">Vorschau Monat</th></tr></thead>
                    <tbody>
                        @foreach($daten['positionen'] as $p)
                            <tr wire:key="pn-{{ $p['baustein'] }}" data-plan-nutzung-zeile="{{ $p['baustein'] }}">
                                <td class="font-medium">{{ $p['label'] }}</td>
                                <td class="text-right tabular-nums">{{ $p['menge'] }}</td>
                                <td class="text-right tabular-nums">{{ $p['betrag_monat'] !== null ? number_format((float) $p['betrag_monat'], 2, ',', '.') : '–' }}</td>
                                <td class="text-right tabular-nums">{{ $p['tage_gebucht'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($p['gebucht_bisher'], 2, ',', '.') }} €</td>
                                <td class="text-right tabular-nums">{{ $p['monatsbetrag_vorschau'] !== null ? number_format($p['monatsbetrag_vorschau'], 2, ',', '.').' €' : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="font-semibold"><td colspan="4">Summe</td>
                        <td class="text-right tabular-nums" data-plan-nutzung-summe-gebucht>{{ number_format($daten['summe_gebucht'], 2, ',', '.') }} €</td>
                        <td class="text-right tabular-nums" data-plan-nutzung-summe-vorschau>{{ number_format($daten['summe_vorschau'], 2, ',', '.') }} €</td></tr></tfoot>
                </table>
                </div>
            @endif
            <p class="pt-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                {{ $daten['user_gesamt'] }} User im Haupt-Team und seinen Unter-Teams, {{ $daten['inklusive_user'] }} sind in der Basis enthalten. {{ $daten['hinweis'] }}
            </p>
        </x-fa::section>
    @endif
</div>
