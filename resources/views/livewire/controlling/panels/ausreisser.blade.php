{{-- Spec 32 — Preis-Ausreißer im Einkaufsjournal (Theil-Sen-Trendlinie je Lieferant+Artikel).
     Flaggt zur Prüfung, korrigiert nichts: ein Treffer kann ein echter Preis sein, den der
     Trend-Fit falsch einschätzt.

     fa-pass 2026-10-05: Tokens + fa-table, Faktor als Feld mit Label oben, Methode als lesbarer
     Hinweis, Leerzustand mit nächstem Schritt. wire:-Bindungen und data-Marker unverändert. --}}
@php
    $eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
@endphp

<div class="flex flex-col gap-3" data-ctrl-ausreisser>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
            Buchungen, deren Preis um mindestens den Faktor vom eigenen Preisverlauf abweicht, nach oben wie nach unten.
            Fehlbuchungen verzerren Wareneinsatz und Einsparpotenzial, deshalb stehen sie neben den Zahlen, die sie verfälschen.
        </p>
        <x-fa::field label="Faktor" for="ctrl-ausreisser-faktor" hint="Ab 1,5. Höher heißt weniger Treffer.">
            <x-fa::input type="number" id="ctrl-ausreisser-faktor" numeric step="0.5" min="1.5"
                         wire:model.live.debounce.400ms="faktor" class="w-24" data-ctrl-ausreisser-faktor />
        </x-fa::field>
    </div>

    @if(count($treffer) === 0)
        <x-fa::empty compact icon="heroicon-o-check-badge" title="Keine auffälligen Buchungen">
            Bei Faktor {{ number_format((float) $faktor, 1, ',', '.') }} weicht keine Buchung auffällig ab. Ohne Einkaufsjournal bleibt die Liste ebenfalls leer.
            Für mehr Treffer den Faktor senken.
        </x-fa::empty>
    @else
        <div class="overflow-x-auto">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Lieferant</th>
                        <th>Datum</th>
                        <th class="num">Gebucht</th>
                        <th class="num">Erwartet</th>
                        <th class="num">Faktor</th>
                        <th>Grundlage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($treffer as $t)
                        <tr wire:key="anom-{{ $t['transaction_id'] }}">
                            <td>{{ $t['designation'] }}</td>
                            <td class="text-[var(--fa-ink-2)]">{{ $lieferanten[$t['supplier_id']] ?? '–' }}</td>
                            <td class="text-[var(--fa-ink-2)] tabular-nums whitespace-nowrap">{{ $t['purchased_at'] ? \Illuminate\Support\Carbon::parse($t['purchased_at'])->format('d.m.Y') : '–' }}</td>
                            <td class="num font-semibold text-[var(--fa-crit)]">{{ $eur($t['actual']) }}</td>
                            <td class="num text-[var(--fa-ink-2)]">{{ $eur($t['expected']) }}</td>
                            <td class="num"><x-fa::badge tone="warn">{{ number_format((float) $t['factor'], 1, ',', '.') }}×</x-fa::badge></td>
                            {{-- Ehrlichkeit über die Methode: bei wenigen Datenpunkten fällt der Dienst
                                 auf den flachen Median zurück; das ist eine schwächere Aussage. --}}
                            <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] whitespace-nowrap">
                                {{ $t['method'] === 'theil_sen' ? 'Preisverlauf' : 'Median aller Preise (wenige Daten)' }} · {{ $t['n_points'] }} Buchungen
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Korrigiert wird an der Quelle, in der Bestellung oder im Import. Hier wird nur markiert.
        </p>
    @endif
</div>
