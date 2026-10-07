{{-- Spec 32 · C3 — Erlösseite: Verkaufs-Ist einlesen → offene Zeilen zuordnen → Matrix lesen.
     Die drei Blöcke stehen in dieser Reihenfolge, weil sie eine Kette sind.

     fa-pass 2026-10-05: Tokens + Bausteine. Drei Abschnitte (x-fa::section plain), Probelauf vor
     Übernahme mit genau einer Hauptaktion, Quadranten als Kennzahl-Leiste und Zustandsfarben,
     Tabellen als fa-table. Reihenfolge, wire:-Bindungen und data-Marker unverändert. --}}
@php
    $eur = fn ($v) => $v === null ? '–' : number_format((float) $v, 2, ',', '.') . ' €';
    $quadLabel = ['star' => 'Star', 'renner' => 'Renner', 'schlaefer' => 'Schläfer', 'penner' => 'Penner'];
    $quadTon = ['star' => 'ok', 'renner' => 'info', 'schlaefer' => 'warn', 'penner' => 'crit'];
    $quadSatz = [
        'star' => 'beliebt und ertragreich',
        'renner' => 'beliebt, wenig Ertrag',
        'schlaefer' => 'ertragreich, selten bestellt',
        'penner' => 'selten bestellt, wenig Ertrag',
    ];
    $feldLabel = ['bezeichnung' => 'Bezeichnung', 'menge' => 'Menge', 'umsatz' => 'Umsatz', 'datum' => 'Datum', 'bereich' => 'Bereich'];
    $pflichtFelder = ['bezeichnung', 'umsatz', 'datum'];
@endphp

<div class="flex flex-col gap-4" data-ctrl-erfolg>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
        Verkaufszahlen aus der Kasse einlesen, offene Zeilen einem Gericht zuordnen und dann sehen,
        welche Gerichte sich lohnen.
    </p>

    {{-- ── 1. Import ───────────────────────────────────────────────────────── --}}
    <x-fa::section variant="plain" title="Verkaufszahlen einlesen" icon="heroicon-o-arrow-up-tray"
                   description="CSV aus Kasse oder Abrechnung. Die Spalten ordnest du selbst zu, weil jede Kasse anders exportiert. Übernommen wird erst nach einem Probelauf."
                   data-ctrl-sales-import>
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Datei hochladen" for="ctrl-sales-datei">
                <input type="file" id="ctrl-sales-datei" wire:model="datei" accept=".csv,.tsv,.txt"
                       class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] file:mr-3 file:h-9 file:px-3.5 file:rounded-[var(--fa-radius-control)] file:border file:border-solid file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:text-[length:var(--fa-text-md)] file:font-medium hover:file:bg-[var(--fa-hover)]"
                       data-ctrl-sales-datei />
            </x-fa::field>
            <x-fa::button icon="heroicon-o-inbox-arrow-down" wire:click="hochladen" :disabled="! $datei">Datei ablegen</x-fa::button>

            @if(count($dateien))
                <x-fa::field label="Oder abgelegte Datei wählen" for="ctrl-sales-dateiname">
                    <x-fa::select id="ctrl-sales-dateiname" wire:model.live="dateiname" wire:change="kopfLesen" class="w-64 max-w-full" placeholder="Datei wählen">
                        @foreach($dateien as $d)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
            @endif
        </div>

        @error('datei')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
        @if($hinweis)
            <x-fa::signal tone="ok" data-ctrl-sales-hinweis>{{ $hinweis }}</x-fa::signal>
        @endif
        @if($fehler)
            <x-fa::signal tone="crit" data-ctrl-sales-fehler>{{ $fehler }}</x-fa::signal>
        @endif

        @if($kopf)
            <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]">
                <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Spalten zuordnen</p>
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,11rem),1fr))] gap-3">
                    @foreach($felder as $feld)
                        <x-fa::field :label="$feldLabel[$feld] ?? \Illuminate\Support\Str::ucfirst($feld)" :for="'ctrl-map-' . $feld" :required="in_array($feld, $pflichtFelder, true)">
                            <x-fa::select id="ctrl-map-{{ $feld }}" wire:model="mapping.{{ $feld }}" data-ctrl-map="{{ $feld }}" placeholder="Keine Spalte">
                                @foreach($kopf['spalten'] as $i => $sp)
                                    <option value="{{ $i }}">{{ $sp !== '' ? $sp : 'Spalte ' . ($i + 1) }}</option>
                                @endforeach
                            </x-fa::select>
                        </x-fa::field>
                    @endforeach
                </div>

                @if(count($kopf['beispiel']))
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">
                        Erste Zeile der Datei: {{ implode(' · ', array_map(fn ($v) => (string) $v, $kopf['beispiel'][0])) }}
                    </p>
                @endif

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <x-fa::button icon="heroicon-o-eye" wire:click="trockenlauf" data-ctrl-sales-dry>Probelauf starten</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-o-check" wire:click="scharf"
                                  wire:confirm="Verkaufszahlen jetzt übernehmen?"
                                  data-ctrl-sales-apply :disabled="! $bericht">Verkaufszahlen übernehmen</x-fa::button>
                </div>
            </div>
        @endif

        @if($bericht)
            <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-3 py-2.5" data-ctrl-sales-bericht>
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                    <span class="font-semibold">{{ $bericht['apply'] ? 'Übernommen' : 'Probelauf' }}:</span>
                    {{ number_format($bericht['gelesen'], 0, ',', '.') }} Zeilen gelesen, {{ $bericht['neu'] }} neu,
                    {{ $bericht['aktualisiert'] }} aktualisiert, {{ $bericht['uebersprungen'] }} übersprungen.
                    Zugeordnet: {{ $bericht['gematcht'] }}, offen: <strong class="font-semibold">{{ $bericht['ungematcht'] }}</strong>.
                    Umsatz: {{ $eur($bericht['umsatz']) }}.
                </p>
                @if(count($bericht['fehler']))
                    <ul class="mt-1.5 flex flex-col gap-0.5">
                        @foreach($bericht['fehler'] as $f)
                            <li><x-fa::signal tone="warn">{{ $f }}</x-fa::signal></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-fa::section>

    {{-- ── 2. Offene Zuordnungen ───────────────────────────────────────────── --}}
    @if($offen->count())
        <x-fa::section variant="plain" title="Verkaufszeilen ohne Gericht" icon="heroicon-o-link"
                       :meta="$offen->count() . ' offen'"
                       description="Diese Umsätze sind erfasst, hängen aber an keinem Gericht und fehlen darum in der Auswertung unten. Eine Zuordnung von Hand bleibt bei jedem neuen Einlesen erhalten."
                       data-ctrl-sales-offen>
            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th>Bezeichnung aus der Datei</th>
                            <th class="num">Zeilen</th>
                            <th class="num">Umsatz</th>
                            <th class="num">Gericht</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($offen as $o)
                            <tr wire:key="offen-{{ $o->id }}">
                                <td>{{ $o->raw_label }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $o->n }}</td>
                                <td class="num">{{ $eur($o->umsatz) }}</td>
                                <td class="num">
                                    @if($zuordnenId === (int) $o->id)
                                        <div class="flex items-center gap-1.5 justify-end">
                                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="zuordnenSuche"
                                                         placeholder="Gericht suchen" aria-label="Gericht suchen" class="w-52" />
                                            <x-fa::icon-button size="sm" icon="heroicon-o-x-mark" label="Zuordnen abbrechen" wire:click="zuordnenAbbrechen" />
                                        </div>
                                        @if(count($treffer))
                                            <div class="mt-1.5 flex flex-wrap gap-1 justify-end">
                                                @foreach($treffer as $t)
                                                    <x-fa::button size="sm" variant="ai" wire:click="zuordnen({{ $t['id'] }})">{{ $t['name'] }}</x-fa::button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @else
                                        <x-fa::button size="sm" icon="heroicon-o-link" wire:click="zuordnenOeffnen({{ $o->id }})"
                                                      data-ctrl-zuordnen="{{ $o->id }}">Gericht zuordnen</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fa::section>
    @endif

    {{-- ── 3. Menu-Engineering ─────────────────────────────────────────────── --}}
    <x-fa::section variant="plain" title="Menu-Engineering" icon="heroicon-o-chart-bar"
                   description="Jedes Gericht gegen den Durchschnitt des Portfolios: wie oft es verkauft wird und was es je Portion einbringt."
                   data-ctrl-matrix>
        <x-slot:actions>
            <div class="flex items-end gap-2">
                <x-fa::field label="Von" for="ctrl-me-von">
                    <x-fa::input type="date" id="ctrl-me-von" size="sm" wire:model.live="von" class="w-36" />
                </x-fa::field>
                <x-fa::field label="Bis" for="ctrl-me-bis">
                    <x-fa::input type="date" id="ctrl-me-bis" size="sm" wire:model.live="bis" class="w-36" />
                </x-fa::field>
            </div>
        </x-slot:actions>

        @if($matrix && $matrix['quelle'] === 'feedback')
            <x-fa::notice tone="warn" title="Beliebtheit aus dem Feedback">
                Es sind noch keine Verkaufszahlen eingelesen. Die Beliebtheit kommt aus dem Praxis-Feedback, das ist Zustimmung, nicht Absatz.
                Sobald Verkaufszahlen eingelesen sind, zählen die.
            </x-fa::notice>
        @endif

        @if($matrix === null || $matrix['n'] === 0)
            <x-fa::empty compact icon="heroicon-o-chart-bar" title="Noch keine Auswertung">
                Dafür braucht es Gerichte mit Verkaufspreis und mit Verkaufszahlen oder Praxis-Feedback im gewählten Zeitraum.
                Oben Verkaufszahlen einlesen oder den Zeitraum erweitern.
            </x-fa::empty>
        @else
            <x-fa::kpis :items="collect($quadLabel)->map(fn ($lab, $key) => [
                'label' => $lab,
                'title' => \Illuminate\Support\Str::ucfirst($quadSatz[$key]),
                'value' => number_format((int) $matrix['quadranten'][$key], 0, ',', '.'),
                'tone' => $quadTon[$key] === 'info' ? null : $quadTon[$key],
            ])->values()->all()" />

            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                {{ collect($quadLabel)->map(fn ($lab, $key) => $lab . ': ' . $quadSatz[$key])->implode(' · ') }}
            </p>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                {{ $matrix['n'] }} Gerichte · Ø Deckungsbeitrag {{ $eur($matrix['avg_db']) }} ·
                Ø Beliebtheit {{ number_format((float) $matrix['avg_pop'], 2, ',', '.') }}
                @if($matrix['quelle'] === 'sales') · Umsatz im Zeitraum {{ $eur($matrix['umsatz']) }} @endif
            </p>

            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th>Gericht</th>
                            <th>Einordnung</th>
                            <th class="num">Beliebtheit</th>
                            <th class="num">Verkaufspreis</th>
                            <th class="num">Deckungsbeitrag</th>
                            <th class="num">Wareneinsatz</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matrix['zeilen'] as $z)
                            <tr wire:key="me-{{ $z['recipe_id'] }}">
                                <td>{{ $z['name'] }}</td>
                                <td><x-fa::badge :tone="$quadTon[$z['quadrant']] ?? 'neutral'">{{ $quadLabel[$z['quadrant']] ?? $z['quadrant'] }}</x-fa::badge></td>
                                <td class="num text-[var(--fa-ink-2)]">{{ number_format((float) $z['popularitaet'], 2, ',', '.') }}</td>
                                <td class="num">
                                    @if($z['sales_net'] === null)
                                        <x-fa::money :value="null" />
                                    @else
                                        {{ $eur($z['sales_net']) }}
                                    @endif
                                </td>
                                <td class="num font-semibold">{{ $eur($z['db_eur']) }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $z['wareneinsatz_pct'] !== null ? number_format((float) $z['wareneinsatz_pct'], 1, ',', '.') . ' %' : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
</div>
