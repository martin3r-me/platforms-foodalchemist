{{-- Spec 82 §13 B5/D — Gebühren-Preispflege (Plattform-Admin). Eingebettet in /verwaltung → Freigaben. --}}
<div class="flex flex-col gap-3" data-fa-plan-preise>
    @if(! $admin)
        <x-fa::notice tone="info">Die Gebühren-Preise pflegen nur Plattform-Admins.</x-fa::notice>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-plan-preise-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-plan-preise-meldung>{{ $meldung }}</x-fa::notice>@endif

        <x-fa::section title="Monatspreise je Baustein" icon="heroicon-o-banknotes" data-plan-preise-aktuell
            description="Netto je Monat. Ein neuer Preis ist eine neue Zeile mit „gültig ab“, die Historie bleibt. Bereits berechnete Tage ändern sich nie.">
            <div class="overflow-x-auto -mx-4"><table class="fa-table fa-table--compact">
                <thead><tr><th>Baustein</th><th class="text-right">€ / Monat</th><th>gültig ab</th></tr></thead>
                <tbody>
                    @foreach($aktuell as $z)
                        <tr wire:key="pp-{{ $z['baustein'] }}" data-plan-preis-zeile="{{ $z['baustein'] }}">
                            <td class="font-medium">{{ $z['label'] }}</td>
                            <td class="text-right tabular-nums">{{ $z['betrag_monat'] !== null ? number_format((float) $z['betrag_monat'], 2, ',', '.') : '–' }}</td>
                            <td>{{ $z['gueltig_ab'] ? \Carbon\Carbon::parse($z['gueltig_ab'])->format('d.m.Y') : 'kein Preis' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-fa::section>

        <x-fa::section title="Neuen Preis anlegen" icon="heroicon-m-plus">
            <div class="flex flex-wrap items-end gap-2">
                <x-fa::field label="Baustein" for="pp-baustein" class="w-64 max-w-full">
                    <x-fa::select id="pp-baustein" wire:model="baustein" data-plan-preise-baustein>
                        @foreach($bausteine as $key => $def)<option value="{{ $key }}">{{ $def[0] }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="€ / Monat netto" for="pp-betrag" class="w-36">
                    <x-fa::input id="pp-betrag" wire:model="betrag" inputmode="decimal" data-plan-preise-betrag />
                </x-fa::field>
                <x-fa::field label="Gültig ab" for="pp-ab" class="w-40">
                    <x-fa::input id="pp-ab" type="date" wire:model="gueltigAb" data-plan-preise-ab />
                </x-fa::field>
                <x-fa::field label="Notiz" for="pp-notiz" class="w-64 max-w-full">
                    <x-fa::input id="pp-notiz" wire:model="notiz" data-plan-preise-notiz />
                </x-fa::field>
                <x-fa::button variant="primary" wire:click="pruefen" data-plan-preise-pruefen>Auswirkung prüfen</x-fa::button>
            </div>
        </x-fa::section>

        @if($auswirkung)
            <x-fa::section title="Auswirkung auf aktive Mandanten" icon="heroicon-o-exclamation-triangle" data-plan-preise-auswirkung>
                @if($auswirkung['mandanten'] === [])
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Kein aktiver Mandant nutzt diesen Baustein. Der Preis trifft niemanden.</p>
                @else
                    <div class="overflow-x-auto -mx-4"><table class="fa-table fa-table--compact">
                        <thead><tr><th>Mandant</th><th class="text-right">Menge</th><th class="text-right">Differenz / Monat</th></tr></thead>
                        <tbody>
                            @foreach($auswirkung['mandanten'] as $m)
                                <tr wire:key="pa-{{ $m['team_id'] }}"><td>{{ $m['name'] }}</td><td class="text-right tabular-nums">{{ $m['menge'] }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($m['differenz_monat'], 2, ',', '.') }} €</td></tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <p class="pt-2 text-[length:var(--fa-text-md)]">Summe: <strong>{{ number_format($auswirkung['summe_differenz'], 2, ',', '.') }} € / Monat</strong>. Ob eine Preisänderung für Bestandskunden gilt (Preisschutz), klärt die Geschäftsführung, nicht diese Seite.</p>
                @endif
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" wire:click="anlegen" data-plan-preise-anlegen>Preis anlegen</x-fa::button>
                </div>
            </x-fa::section>
        @endif

        <x-fa::section title="Gebühren je Mandant (laufender Monat)" icon="heroicon-o-building-office" data-plan-preise-mandanten
            description="Nur Mengen und Beträge, keine Inhalte. Gebucht = Tageswerte aus dem täglichen Zähl-Lauf, Vorschau = Menge heute × Monatspreis.">
            @if($mandanten === [])
                <x-fa::empty compact icon="heroicon-o-building-office" title="Keine aktiven Mandanten">Es gibt keinen Mandanten mit Status „aktiv“.</x-fa::empty>
            @else
                <div class="overflow-x-auto -mx-4"><table class="fa-table fa-table--compact">
                    <thead><tr><th>Mandant</th><th class="text-right">User</th><th class="text-right">Gebucht bisher</th><th class="text-right">Vorschau Monat</th></tr></thead>
                    <tbody>
                        @foreach($mandanten as $m)
                            <tr wire:key="pm-{{ $m['team_id'] }}" data-plan-mandant-zeile="{{ $m['team_id'] }}">
                                <td class="font-medium">{{ $m['name'] }}</td><td class="text-right tabular-nums">{{ $m['user_gesamt'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($m['summe_gebucht'], 2, ',', '.') }} €</td>
                                <td class="text-right tabular-nums">{{ number_format($m['summe_vorschau'], 2, ',', '.') }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-fa::section>

        @if($historie !== [])
            <x-fa::section title="Verlauf" icon="heroicon-o-clock" data-plan-preise-historie>
                <div class="overflow-x-auto -mx-4"><table class="fa-table fa-table--compact">
                    <thead><tr><th>Baustein</th><th class="text-right">€ / Monat</th><th>von</th><th>bis</th><th>Notiz</th></tr></thead>
                    <tbody>
                        @foreach($historie as $h)
                            <tr wire:key="ph-{{ $h['id'] }}"><td>{{ $bausteine[$h['baustein']][0] ?? $h['baustein'] }}</td><td class="text-right tabular-nums">{{ number_format((float) $h['betrag_monat'], 2, ',', '.') }}</td>
                                <td>{{ \Carbon\Carbon::parse($h['gueltig_ab'])->format('d.m.Y') }}</td><td>{{ $h['gueltig_bis'] ? \Carbon\Carbon::parse($h['gueltig_bis'])->format('d.m.Y') : '–' }}</td><td>{{ $h['notiz'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </x-fa::section>
        @endif
    @endif
</div>
