{{-- Spec 32 — Preis-Ausreißer im Einkaufsjournal (Theil-Sen-Trendlinie je Lieferant+Artikel).
     Flaggt zur Prüfung, korrigiert nichts: ein Treffer kann ein echter Preis sein, den der
     Trend-Fit falsch einschätzt. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €')

<div class="space-y-3" data-ctrl-ausreisser>
    <div class="flex items-end justify-between gap-3 flex-wrap">
        <p class="text-[11px] text-gray-500 max-w-2xl">
            Buchungen, die um mindestens den eingestellten Faktor vom eigenen Preistrend abweichen —
            nach oben wie nach unten. Fehlbuchungen verzerren Ist-Wareneinsatz und Einsparpotenzial,
            deshalb stehen sie hier neben den Zahlen, die sie verfälschen.
        </p>
        <label class="text-[11px] text-gray-600 flex items-center gap-2">
            Faktor
            <input type="number" step="0.5" min="1.5" wire:model.live.debounce.400ms="faktor"
                   class="{{ $input }} !w-20" data-ctrl-ausreisser-faktor />
        </label>
    </div>

    @if(count($treffer) === 0)
        <p class="text-xs text-gray-500">
            Keine auffälligen Buchungen bei Faktor {{ number_format((float) $faktor, 1, ',', '.') }}.
            Ohne Einkaufsjournal bleibt die Liste ebenfalls leer.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="{{ $table }}">
                <thead>
                    <tr>
                        <th class="{{ $th }} text-left">Artikel</th>
                        <th class="{{ $th }} text-left">Lieferant</th>
                        <th class="{{ $th }} text-left">Datum</th>
                        <th class="{{ $th }} text-right">gebucht</th>
                        <th class="{{ $th }} text-right">erwartet</th>
                        <th class="{{ $th }} text-right">Faktor</th>
                        <th class="{{ $th }} text-left">Basis</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($treffer as $t)
                        <tr class="{{ $tr }}" wire:key="anom-{{ $t['transaction_id'] }}">
                            <td class="{{ $td }} text-gray-900">{{ $t['designation'] }}</td>
                            <td class="{{ $td }} text-gray-600">{{ $lieferanten[$t['supplier_id']] ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $t['purchased_at'] ?? '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-rose-700">{{ $eur($t['actual']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-gray-700">{{ $eur($t['expected']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums font-medium">{{ number_format((float) $t['factor'], 1, ',', '.') }}×</td>
                            {{-- Ehrlichkeit über die Methode: bei wenigen Datenpunkten fällt der Dienst
                                 auf den flachen Median zurück — das ist eine schwächere Aussage. --}}
                            <td class="{{ $td }} text-[10px] text-gray-500">
                                {{ $t['method'] === 'theil_sen' ? 'Trend' : 'Median' }} · {{ $t['n_points'] }} Punkte
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[10px] text-gray-500">
            Korrigiert wird an der Quelle (Bestellung bzw. Import) — hier wird nur geflaggt.
        </p>
    @endif
</div>
