{{-- Stammdaten-Import, Stufe 1a — Vorlage, Upload, Prüfliste (lesend). Nichts geht in den Bestand; die Übernahme je Zeile folgt in Stufe 1b.
     Handy: Zeilen als Karten, Tabelle erst ab md (#1003). --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $ton = ['neu' => 'info', 'aendert' => 'accent', 'unveraendert' => 'neutral', 'konflikt' => 'warn', 'fehler' => 'crit'];
    $text = ['neu' => 'Neu', 'aendert' => 'Ändert', 'unveraendert' => 'Unverändert', 'konflikt' => 'Konflikt', 'fehler' => 'Fehler'];
@endphp

<div class="space-y-4" data-stammdaten-import>
    <x-fa::section title="Artikel-Stammdaten" icon="heroicon-o-clipboard-document-list" data-stammdaten-vorlage>
        <p class="{{ $leise }} mb-2">Alle Stammdaten in einer Datei: Gebinde, Preis, Allergene, Zusatzstoffe, Nährwerte, Zutaten, Herkunft, Haltbarkeit, Bio. Neue Artikel und Änderungen landen als Vorschlag in der Prüfliste; nichts geht ungeprüft in den Bestand.</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-document-text" wire:click="vorlageCsv" data-stammdaten-vorlage-csv>Vorlage (CSV)</x-fa::button>
            @if($xlsxVorlage)
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-table-cells" wire:click="vorlageXlsx" data-stammdaten-vorlage-xlsx>Vorlage (Excel)</x-fa::button>
            @else
                <span class="{{ $leise }}" data-stammdaten-vorlage-nur-csv>Die Excel-Vorlage ist auf diesem Server nicht verfügbar, bitte die CSV-Vorlage nehmen.</span>
            @endif
        </div>
        <p class="{{ $leise }} pt-2" data-stammdaten-hinweis>{{ $hinweis }}</p>
    </x-fa::section>

    @if($fehler)<x-fa::notice tone="crit" data-stammdaten-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-stammdaten-meldung>{{ $meldung }}</x-fa::notice>@endif

    @if($darf)
        <x-fa::section title="Stammdaten prüfen" icon="heroicon-o-arrow-up-tray" data-stammdaten-upload>
            <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
                <x-fa::field label="Datei (.xlsx, .ods, .csv — bis 10 MB)" for="sd-datei">
                    <input id="sd-datei" type="file" wire:model="datei" accept=".xlsx,.xlsm,.ods,.csv,.tsv,.txt" class="fa-control h-9 w-full text-[length:var(--fa-text-md)]" data-stammdaten-datei />
                </x-fa::field>
                <x-fa::field label="Lieferant (wenn die Datei keine Lieferant-Spalte hat)" for="sd-lieferant" class="w-full md:w-72">
                    <x-fa::select id="sd-lieferant" wire:model="lieferantId" placeholder="Aus der Datei" data-stammdaten-lieferant>
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::button variant="primary" icon="heroicon-m-magnifying-glass" wire:click="pruefen" wire:loading.attr="disabled" wire:target="datei,pruefen" :disabled="$datei === null" data-stammdaten-pruefen>Prüfen</x-fa::button>
            </div>
            <div wire:loading wire:target="datei" class="{{ $leise }} pt-2">Datei wird hochgeladen …</div>
        </x-fa::section>
    @else
        <x-fa::notice tone="info" data-stammdaten-nur-lesen>Stammdaten importieren darf nur das Haupt-Team des Kunden mit der Rolle Kuratieren. Du siehst die bisherigen Läufe.</x-fa::notice>
    @endif

    @if($laeufe->isNotEmpty())
        <x-fa::section title="Stammdaten-Läufe" icon="heroicon-o-queue-list" :meta="$laeufe->count()" data-stammdaten-laeufe>
            <div class="flex flex-col gap-1.5">
                @foreach($laeufe as $l)
                    @php $c = $l->counts ?? []; @endphp
                    <button type="button" wire:key="sd-lauf-{{ $l->id }}" wire:click="laufWaehlen({{ $l->id }})" data-stammdaten-lauf="{{ $l->id }}"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2 text-left hover:bg-[var(--fa-hover)]">
                        <span class="min-w-0 truncate font-medium text-[var(--fa-ink)]">{{ $l->filename }}</span>
                        <span class="{{ $leise }} tabular-nums">{{ $l->created_at?->format('d.m.Y H:i') }} · {{ $c['zeilen'] ?? 0 }} Zeilen · {{ $c['neu'] ?? 0 }} neu · {{ $c['aendert'] ?? 0 }} ändern · {{ $c['konflikt'] ?? 0 }} Konflikte · {{ $c['fehler'] ?? 0 }} Fehler</span>
                    </button>
                @endforeach
            </div>
        </x-fa::section>
    @endif

    @if($lauf)
        <x-fa::section title="Prüfliste: {{ $lauf->filename }}" icon="heroicon-o-clipboard-document-check" :meta="$pruefliste->count()" data-stammdaten-pruefliste>
            <div class="mb-3 flex flex-wrap gap-1.5" data-stammdaten-filter>
                @foreach(['' => 'Alle', 'neu' => 'Neu', 'aendert' => 'Ändert', 'konflikt' => 'Konflikt', 'fehler' => 'Fehler', 'unveraendert' => 'Unverändert'] as $k => $label)
                    <x-fa::button size="sm" :variant="$filter === $k ? 'primary' : 'ghost'" wire:click="$set('filter', '{{ $k }}')" wire:key="sd-f-{{ $k }}" data-stammdaten-filter-knopf="{{ $k }}">{{ $label }}</x-fa::button>
                @endforeach
            </div>
            @if($pruefliste->isEmpty())
                <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine Zeilen">Für diesen Filter gibt es keine Zeilen.</x-fa::empty>
            @else
                <div class="flex flex-col gap-2">
                    @foreach($pruefliste as $z)
                        <details wire:key="sd-z-{{ $z->id }}" class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2" data-stammdaten-zeile="{{ $z->aktion }}">
                            <summary class="flex cursor-pointer flex-wrap items-center gap-2">
                                <x-fa::badge :tone="$ton[$z->aktion] ?? 'neutral'">{{ $text[$z->aktion] ?? $z->aktion }}</x-fa::badge>
                                <span class="tabular-nums {{ $leise }}">Zeile {{ $z->row_nr }}</span>
                                <span class="min-w-0 flex-1 truncate font-medium text-[var(--fa-ink)]">{{ $z->designation ?? '–' }}</span>
                                <span class="{{ $leise }}">{{ $z->supplier_name }} · {{ $z->article_number ?? $z->ean ?? 'ohne Nr.' }}</span>
                            </summary>
                            <div class="space-y-2 pt-2 text-[length:var(--fa-text-md)]">
                                @if($z->reason)<p class="text-[var(--fa-crit)]">{{ $z->reason }}</p>@endif
                                @foreach((array) $z->hinweise as $h)
                                    <p class="{{ in_array($h['stufe'] ?? '', ['fehler', 'konflikt'], true) ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}" data-stammdaten-hinweis-zeile="{{ $h['code'] ?? '' }}">{{ $h['text'] ?? '' }}</p>
                                @endforeach
                                @foreach((array) $z->diff as $block => $felder)
                                    <div data-stammdaten-diff="{{ $block }}">
                                        <p class="{{ $leise }} font-medium">{{ ['felder' => 'Artikelfelder', 'preis' => 'Preis', 'naehrwerte' => 'Nährwerte', 'allergene' => 'Allergene', 'zusatzstoffe' => 'Zusatzstoffe'][$block] ?? $block }}</p>
                                        @foreach($block === 'preis' ? ['Preis' => $felder] : $felder as $feld => $werte)
                                            <p class="tabular-nums">{{ $feld }}: <span class="{{ $leise }}">{{ $werte['alt'] ?? '–' }}</span> → <span class="font-medium">{{ $werte['neu'] ?? '–' }}</span></p>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif
        </x-fa::section>
    @endif
</div>
