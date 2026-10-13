{{-- Spec 82 §3 / Schritt 4d, Stufe 2 — Preislisten: hochladen, Spalten zuordnen, abgleichen, Prüfliste.
     Dieser Schirm gleicht nur ab. Preise werden erst von der Schreib-Schicht (Teampreis) geschrieben. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $ton = ['treffer' => 'ok', 'ohne_treffer' => 'warn', 'mehrdeutig' => 'warn', 'lieferant_unbekannt' => 'warn', 'fehler' => 'crit', 'doppelt' => 'neutral'];
    $text = ['treffer' => 'Treffer', 'ohne_treffer' => 'Kein Treffer', 'mehrdeutig' => 'Mehrdeutig', 'lieferant_unbekannt' => 'Lieferant unbekannt', 'fehler' => 'Fehler', 'doppelt' => 'Doppelt'];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Preislisten" icon="heroicon-o-currency-euro" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Preislisten" subtitle="Lade die Preisliste deines Lieferanten hoch (Excel oder CSV). Jede Zeile wird über Artikelnummer und Lieferant, danach über die EAN, mit den Artikeln abgeglichen. Erst nach dem Abgleich übernimmst du die Treffer als deine Preise. Es wird kein Artikel angelegt und nie ein Listenpreis geändert." />

        @if($fehler)<x-fa::notice tone="crit" data-preisliste-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-preisliste-meldung>{{ $meldung }}</x-fa::notice>@endif

        @unless($darf)
            <x-fa::notice tone="info" data-preisliste-nur-lesen>Preislisten hochladen darf nur das Haupt-Team des Kunden mit der Rolle Kuratieren. Du siehst die bisherigen Läufe.</x-fa::notice>
        @else
            <x-fa::section title="Preisliste hochladen" icon="heroicon-o-arrow-up-tray" data-preisliste-upload>
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Datei (.xlsx, .ods, .csv — bis 10 MB)" for="pl-datei">
                        <input id="pl-datei" type="file" wire:model="datei" accept=".xlsx,.xlsm,.ods,.csv,.tsv,.txt" class="fa-control h-9 text-[length:var(--fa-text-md)]" data-preisliste-datei />
                    </x-fa::field>
                    <x-fa::field label="Lieferant (wenn die Datei keine Lieferant-Spalte hat)" for="pl-lieferant" class="w-72 max-w-full">
                        <x-fa::select id="pl-lieferant" wire:model="lieferantId" placeholder="Aus der Datei" data-preisliste-lieferant>
                            @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </div>
                <div wire:loading wire:target="datei" class="{{ $leise }} pt-2">Datei wird gelesen …</div>

                @if($vorschau)
                    <div class="pt-4" data-preisliste-zuordnung>
                        <h4 class="mb-1 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Spalten zuordnen</h4>
                        <p class="{{ $leise }} mb-2">{{ $vorschau['zeilen'] }} Zeilen erkannt. Pflicht sind der Preis und die Artikelnummer oder die EAN.</p>
                        <div class="grid gap-2 grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                            @foreach($felder as $feld => $label)
                                <x-fa::field :label="$label" for="pl-z-{{ $feld }}" wire:key="pl-z-{{ $feld }}">
                                    <x-fa::select id="pl-z-{{ $feld }}" size="sm" wire:model="zuordnung.{{ $feld }}" placeholder="– nicht in der Datei –" data-preisliste-feld="{{ $feld }}">
                                        @foreach($vorschau['kopf'] as $i => $kopf)<option value="{{ $i }}">{{ $kopf !== '' ? $kopf : 'Spalte ' . ($i + 1) }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                            @endforeach
                        </div>
                        @if($vorschau['beispiel'] !== [])
                            <div class="overflow-x-auto mt-3 -mx-4">
                                <table class="fa-table fa-table--compact">
                                    <thead><tr>@foreach($vorschau['kopf'] as $kopf)<th>{{ $kopf }}</th>@endforeach</tr></thead>
                                    <tbody>@foreach($vorschau['beispiel'] as $zeile)<tr>@foreach($vorschau['kopf'] as $i => $_)<td>{{ $zeile[$i] ?? '' }}</td>@endforeach</tr>@endforeach</tbody>
                                </table>
                            </div>
                        @endif
                        <div class="pt-3">
                            <x-fa::button variant="primary" icon="heroicon-m-arrows-right-left" wire:click="abgleichen" wire:loading.attr="disabled" data-preisliste-abgleichen>Abgleichen</x-fa::button>
                        </div>
                    </div>
                @endif
            </x-fa::section>
        @endunless

        <x-fa::section title="Läufe" icon="heroicon-o-queue-list" :meta="$laeufe->count()" data-preisliste-laeufe>
            @if($laeufe->isEmpty())
                <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Läufe">Nach dem ersten Upload steht hier jeder Abgleich mit seinen Zahlen.</x-fa::empty>
            @else
                <table class="fa-table fa-table--compact">
                    <thead><tr><th>Datei</th><th>Datum</th><th class="text-right">Zeilen</th><th class="text-right">Treffer offen</th><th class="text-right">Übernommen</th><th class="text-right">Zu prüfen</th><th></th></tr></thead>
                    <tbody>
                        @foreach($laeufe as $l)
                            @php $c = $l->counts ?? []; @endphp
                            <tr wire:key="pl-lauf-{{ $l->id }}" class="cursor-pointer hover:bg-[var(--fa-hover)]" wire:click="laufWaehlen({{ $l->id }})" data-preisliste-lauf="{{ $l->id }}">
                                <td class="font-medium">{{ $l->filename }}</td>
                                <td class="tabular-nums">{{ $l->created_at?->format('d.m.Y H:i') }}</td>
                                <td class="text-right tabular-nums">{{ $c['zeilen'] ?? 0 }}</td>
                                <td class="text-right tabular-nums">{{ ($c['treffer'] ?? 0) + ($c['zukuenftig'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ ($c['angewendet'] ?? 0) + ($c['unveraendert'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ ($c['zeilen'] ?? 0) - ($c['treffer'] ?? 0) - ($c['zukuenftig'] ?? 0) - ($c['angewendet'] ?? 0) - ($c['unveraendert'] ?? 0) }}</td>
                                <td class="text-right"><x-fa::button size="sm" variant="ghost">Prüfliste</x-fa::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fa::section>

        @if($lauf)
            <x-fa::section title="Prüfliste: {{ $lauf->filename }}" icon="heroicon-o-clipboard-document-check" :meta="$pruefliste->count()" data-preisliste-pruefliste>
                <x-slot:actions>
                    @php $offen = (($lauf->counts['treffer'] ?? 0) + ($lauf->counts['zukuenftig'] ?? 0)); @endphp
                    @if($darf && $offen > 0)
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="anwenden({{ $lauf->id }})"
                            wire:confirm="{{ $offen }} Treffer als deine Preise übernehmen? Bestehende Preise werden nicht überschrieben, der alte Preis bleibt in der Historie." data-preisliste-anwenden>{{ $offen }} Preise übernehmen</x-fa::button>
                    @elseif($lauf->status === 'applied')
                        <x-fa::badge tone="ok" icon="heroicon-m-check-circle">Übernommen</x-fa::badge>
                    @endif
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-down-tray" wire:click="pruefelisteLaden({{ $lauf->id }})" data-preisliste-csv>Als CSV</x-fa::button>
                </x-slot:actions>
                @if($pruefliste->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Alles zugeordnet">Alle Zeilen dieses Laufs haben einen Treffer.</x-fa::empty>
                @else
                    <div class="overflow-x-auto -mx-4">
                        <table class="fa-table fa-table--compact">
                            <thead><tr><th>Zeile</th><th>Status</th><th>Artikelnummer</th><th>Bezeichnung</th><th class="text-right">Preis</th><th>Grund</th></tr></thead>
                            <tbody>
                                @foreach($pruefliste as $z)
                                    <tr wire:key="pl-z-{{ $z->id }}" data-preisliste-zeile="{{ $z->status }}">
                                        <td class="tabular-nums">{{ $z->row_nr }}</td>
                                        <td><x-fa::badge :tone="$ton[$z->status] ?? 'neutral'">{{ $text[$z->status] ?? $z->status }}</x-fa::badge></td>
                                        <td>{{ $z->article_number ?? $z->ean }}</td>
                                        <td>{{ $z->designation }}</td>
                                        <td class="text-right tabular-nums">{{ $z->price !== null ? number_format((float) $z->price, 2, ',', '.') : '' }}</td>
                                        <td class="{{ $leise }}">{{ $z->reason }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($pruefliste->count() >= 500)<p class="{{ $leise }} pt-2">Gezeigt werden die ersten 500 Zeilen. Die CSV enthält alle.</p>@endif
                @endif
            </x-fa::section>
        @endif
    </x-ui-page-container>
</x-ui-page>
