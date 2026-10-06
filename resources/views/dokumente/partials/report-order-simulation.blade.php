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

    <div class="grid meta keep">
        <div><span>Katalogpreis / Person</span>{{ $money($catalogPp) }}</div>
        <div><span>Wareneinsatz / Person</span>{{ $money((float) $sim['mek'] / $pax) }}</div>
        <div><span>Produktionslohn / Person</span>{{ $money((float) $sim['fek'] / $pax) }}</div>
        <div><span>Vollkosten / Person</span>{{ $money((float) $sim['hk2'] / $pax) }}</div>
        <div><span>Preisempfehlung / Person</span><strong>{{ $money($targetPp) }}</strong></div>
        <div><span>Abweichung Katalog − Ziel</span>{{ $gapPp > 0 ? '+' : '' }}{{ $money($gapPp) }}</div>
        <div><span>Zielpreis gesamt</span>{{ $money($sim['target_price']) }}</div>
        <div><span>Aktive Produktionszeit</span>{{ number_format((float) $sim['active_person_minutes'] / 60, 2, ',', '.') }} Personenstunden</div>
    </div>

    @if($sim['unprofitable'] ?? false)
        <p class="warn">Der Katalogpreis liegt {{ $money($sim['target_gap']) }} unter dem Zielpreis. Der Katalogpreis wurde nicht verändert.</p>
    @endif
    @unless($sim['complete'] ?? false)
        <p class="warn"><strong>Preisempfehlung nicht belastbar:</strong> Die Auftragsdaten sind noch unvollständig.</p>
    @endunless
    @if(count($sim['warnings'] ?? []))
        {{-- Ein Hinweis je Zeile: als „ · "-Kette war das ein unlesbarer Textblock. --}}
        <p class="warn">@foreach($sim['warnings'] as $hinweis){{ $hinweis }}@unless($loop->last)<br>@endunless @endforeach</p>
    @endif

    @if(count($sim['cost_breakdown'] ?? []))
        <h3>Auftragskosten</h3>
        <table class="cost-waterfall">
            <thead><tr><th width="50%">Kostenstufe</th><th class="num">Je Person</th><th class="num">Gesamt</th></tr></thead>
            <tbody>
                @foreach($sim['cost_breakdown'] as $row)
                    @php($stage = $row['stage'] ?? 'cost')
                    <tr class="{{ in_array($stage, ['subtotal', 'total'], true) ? 'sum-row' : '' }}">
                        <td>{{ $stage === 'surcharge' ? '+ ' : '' }}{{ $row['label'] }}</td>
                        <td class="num">{{ $money((float) $row['amount'] / $pax) }}</td>
                        <td class="num">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sum-row accent-row">
                    <td>Preisempfehlung</td><td class="num">{{ $money($targetPp) }}</td><td class="num">{{ $money($sim['target_price']) }}</td>
                </tr>
                <tr>
                    <td>Deckungsbeitrag beim Katalog-VK</td><td class="num">{{ $money($dbPp) }}</td><td class="num">{{ $money($sim['contribution_margin']) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if(count($sim['time_breakdown'] ?? []))
        <h3>Aktive Produktionszeit je Rezept</h3>
        <p class="muted">{{ number_format((float) $sim['active_person_minutes'], 1, ',', '.') }} aktive Personenminuten insgesamt.</p>
        <table>
            <thead><tr><th width="24%">Rezept</th><th class="num">Ansätze</th><th class="num">Vorgänge</th><th class="num">Rüsten</th><th class="num">Vorgangszeit</th><th class="num">Variabel</th><th class="num">Aktiv gesamt</th></tr></thead>
            <tbody>
                @foreach($sim['time_breakdown'] as $row)
                    <tr>
                        <td>{{ $row['recipe'] }}</td>
                        <td class="num">{{ number_format((float) $row['production_batches'], 2, ',', '.') }}</td>
                        <td class="num">{{ $row['operations'] }}</td>
                        <td class="num">{{ number_format((float) $row['setup_minutes'], 1, ',', '.') }} min</td>
                        <td class="num">{{ number_format((float) $row['batch_minutes'], 1, ',', '.') }} min</td>
                        <td class="num">{{ number_format((float) $row['variable_minutes'], 1, ',', '.') }} min</td>
                        <td class="num"><strong>{{ number_format((float) $row['active_person_minutes'], 1, ',', '.') }} min</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
