@php
    $sim = $simulation;
    $pax = max(1, (int) ($sim['pax'] ?? 0));
    $money = fn ($v, $dec = 2) => number_format((float) $v, $dec, ',', '.') . ' €';
    $targetPp = (float) ($sim['target_price_per_person'] ?? 0);
    $catalogPp = (float) ($sim['catalog_price_per_person'] ?? 0);
    $gapPp = $catalogPp - $targetPp;
    $dbPp = (float) ($sim['contribution_margin'] ?? 0) / $pax;
@endphp

<section class="order-simulation">
    <h2>Auftragssimulation · {{ number_format($pax, 0, ',', '.') }} Pax</h2>
    <p class="muted">Prüfung des Katalogpreises für die konkrete Menge. Die Simulation verändert keine Stammdaten.</p>

    <div class="grid meta">
        <div><span>Katalog / Person</span>{{ $money($catalogPp) }}</div>
        <div><span>MEK Auftrag / Person</span>{{ $money((float) $sim['mek'] / $pax) }}</div>
        <div><span>FEK Auftrag / Person</span>{{ $money((float) $sim['fek'] / $pax) }}</div>
        <div><span>HK2 / Person</span>{{ $money((float) $sim['hk2'] / $pax) }}</div>
        <div><span>Preisempfehlung / Person</span><strong>{{ $money($targetPp) }}</strong></div>
        <div><span>Abweichung Katalog − Ziel</span>{{ $gapPp > 0 ? '+' : '' }}{{ $money($gapPp) }}</div>
        <div><span>Zielpreis gesamt</span>{{ $money($sim['target_price']) }}</div>
        <div><span>Aktive Personenzeit</span>{{ number_format((float) $sim['active_person_minutes'] / 60, 2, ',', '.') }} h</div>
    </div>

    @if($sim['unprofitable'] ?? false)
        <p class="warn">Der Katalogpreis liegt {{ $money($sim['target_gap']) }} unter dem Zielpreis. Der Katalogpreis wurde nicht verändert.</p>
    @endif
    @unless($sim['complete'] ?? false)
        <p class="warn"><strong>Preisempfehlung nicht belastbar:</strong> Die Auftragsdaten sind noch unvollständig.</p>
    @endunless
    @if(count($sim['warnings'] ?? []))
        <p class="warn">{{ implode(' · ', $sim['warnings']) }}</p>
    @endif

    @if(count($sim['cost_breakdown'] ?? []))
        <h3>Auftragskosten</h3>
        <table class="cost-waterfall">
            <thead><tr><th>Kostenstufe</th><th style="text-align:right">Je Person</th><th style="text-align:right">Gesamt</th></tr></thead>
            <tbody>
                @foreach($sim['cost_breakdown'] as $row)
                    @php($stage = $row['stage'] ?? 'cost')
                    <tr class="{{ in_array($stage, ['subtotal', 'total'], true) ? 'sum-row' : '' }}">
                        <td>{{ $stage === 'surcharge' ? '+ ' : '' }}{{ $row['label'] }}</td>
                        <td style="text-align:right">{{ $money((float) $row['amount'] / $pax) }}</td>
                        <td style="text-align:right">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sum-row accent-row">
                    <td>Preisempfehlung</td><td style="text-align:right">{{ $money($targetPp) }}</td><td style="text-align:right">{{ $money($sim['target_price']) }}</td>
                </tr>
                <tr>
                    <td>Deckungsbeitrag beim Katalog-VK</td><td style="text-align:right">{{ $money($dbPp) }}</td><td style="text-align:right">{{ $money($sim['contribution_margin']) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if(count($sim['time_breakdown'] ?? []))
        <h3>Zeitaufschlüsselung</h3>
        <p class="muted">{{ number_format((float) $sim['active_person_minutes'], 1, ',', '.') }} aktive Personenminuten insgesamt.</p>
        <table>
            <thead><tr><th>Rezept</th><th>Ansätze</th><th>Vorgänge</th><th>Rüsten</th><th>Vorgangszeit</th><th>Variabel</th><th>Aktiv gesamt</th></tr></thead>
            <tbody>
                @foreach($sim['time_breakdown'] as $row)
                    <tr>
                        <td>{{ $row['recipe'] }}</td>
                        <td style="text-align:right">{{ number_format((float) $row['production_batches'], 2, ',', '.') }}</td>
                        <td style="text-align:right">{{ $row['operations'] }}</td>
                        <td style="text-align:right">{{ number_format((float) $row['setup_minutes'], 1, ',', '.') }} min</td>
                        <td style="text-align:right">{{ number_format((float) $row['batch_minutes'], 1, ',', '.') }} min</td>
                        <td style="text-align:right">{{ number_format((float) $row['variable_minutes'], 1, ',', '.') }} min</td>
                        <td style="text-align:right"><strong>{{ number_format((float) $row['active_person_minutes'], 1, ',', '.') }} min</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
