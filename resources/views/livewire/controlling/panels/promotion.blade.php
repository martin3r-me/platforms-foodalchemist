{{-- Spec 33 P6 — Umsatz je laufender Ausgabe. Die Vorbehalte stehen gleichrangig neben den
     Zahlen, nicht als Fußnote: sonst liest sich die Liste genauer, als sie ist.

     fa-pass 2026-10-05: Tokens + Bausteine, Kennzahlen als x-fa::kpis (Hauptzahl: Umsatz gesamt),
     Tabelle als fa-table, Vorbehalt als Hinweisfläche, Leerzustand mit nächstem Schritt. --}}
@php
    $eur = fn ($v) => $v === null ? '–' : number_format((float) $v, 2, ',', '.') . ' €';
@endphp

<div class="flex flex-col gap-3" data-ctrl-promotion>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
            Umsatz je laufender Speisekarte, Speiseplan oder Angebot, jeweils im eigenen Gültigkeitszeitraum.
            Grundlage sind die eingelesenen Verkaufszahlen.
        </p>
        <div class="flex items-end gap-2">
            <x-fa::field label="Stichtag" for="ctrl-promo-stichtag">
                <x-fa::input type="date" id="ctrl-promo-stichtag" wire:model.live="stichtag" class="w-40" data-ctrl-promo-stichtag />
            </x-fa::field>
            <x-fa::button variant="ghost" wire:click="heute">Heute</x-fa::button>
        </div>
    </div>

    @if($p === null)
        <x-fa::empty compact icon="heroicon-o-user-group" title="Kein Team zugeordnet">
            Ohne Team gibt es keine Verkaufszahlen. Wähle oben ein Team aus.
        </x-fa::empty>
    @else
        <x-fa::kpis :items="[
            ['label' => 'Umsatz gesamt', 'value' => $eur($p['umsatz_gesamt']), 'primary' => true],
            ['label' => 'Davon einem Gericht zugeordnet',
             'value' => $eur($p['umsatz_zugeordnet']) . ($p['abdeckung_pct'] === null ? '' : ' (' . number_format($p['abdeckung_pct'], 1, ',', '.') . ' %)'),
             'title' => 'Anteil des Umsatzes, der an einem Gericht hängt'],
            ['label' => 'Laufende Ausgaben', 'value' => number_format(count($p['zeilen']), 0, ',', '.')],
            ['label' => 'Stand', 'value' => \Illuminate\Support\Carbon::parse($p['stichtag'])->format('d.m.Y')],
        ]" />

        @if($p['hinweis'])
            <x-fa::notice tone="warn" data-ctrl-promo-hinweis>{{ $p['hinweis'] }}</x-fa::notice>
        @endif

        @if(count($p['zeilen']))
            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th>Ausgabe</th>
                            <th>Art</th>
                            <th>Betrieb oder Kunde</th>
                            <th class="num">Gerichte</th>
                            <th class="num">Menge</th>
                            <th class="num">Umsatz</th>
                            <th class="num">davon exklusiv</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($p['zeilen'] as $z)
                            <tr wire:key="promo-{{ $z['art'] }}-{{ $z['id'] }}">
                                <td><a href="{{ $z['route'] }}" wire:navigate class="text-[var(--fa-accent)] hover:underline">{{ $z['name'] }}</a></td>
                                <td class="text-[var(--fa-ink-2)]">{{ $z['art_label'] }}</td>
                                <td class="text-[var(--fa-ink-2)]">{{ $z['outlet_name'] ?? $z['kunde'] ?? '–' }}</td>
                                <td class="num text-[var(--fa-ink-2)]">
                                    {{ $z['n_gerichte'] }}
                                    @if($z['n_gerichte_exklusiv'] < $z['n_gerichte'])
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" title="Der Rest steckt auch in einer anderen laufenden Ausgabe">({{ $z['n_gerichte_exklusiv'] }} nur hier)</span>
                                    @endif
                                </td>
                                <td class="num text-[var(--fa-ink-2)]">{{ number_format((float) $z['menge'], 0, ',', '.') }}</td>
                                <td class="num font-semibold">{{ $eur($z['umsatz']) }}</td>
                                {{-- Der exklusive Anteil sagt, wie belastbar die Zahl links ist. --}}
                                <td class="num {{ ($z['exklusiv_pct'] ?? 100) < 100 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}">
                                    {{ $eur($z['umsatz_exklusiv']) }}
                                    @if($z['exklusiv_pct'] !== null)
                                        <span class="text-[length:var(--fa-text-sm)]">({{ number_format($z['exklusiv_pct'], 0, ',', '.') }} %)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-fa::notice tone="info" title="So ist die Spalte Umsatz zu lesen">
                Ein Gericht kann in mehreren laufenden Ausgaben stehen, sein Umsatz zählt dann bei beiden.
                Die Summe der Spalte ist deshalb größer als der Gesamtumsatz oben. Das ist kein Rechenfehler.
                Die Spalte „davon exklusiv" nennt den Teil, der eindeutig dieser Ausgabe gehört.
            </x-fa::notice>
        @elseif(! $p['hinweis'])
            <x-fa::empty compact icon="heroicon-o-banknotes" title="Keine laufende Ausgabe mit Umsatz">
                Am Stichtag läuft keine Ausgabe mit Verkaufszahlen. Anderen Stichtag wählen oder unten Verkaufszahlen einlesen.
            </x-fa::empty>
        @endif
    @endif
</div>
