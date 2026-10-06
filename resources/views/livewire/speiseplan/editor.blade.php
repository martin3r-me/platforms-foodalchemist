{{-- Speiseplan-Editor — fa-pass (2026-10-05), Werkbank-Modus auf Bausteinen <x-fa::…>.
     Häufigste Aufgabe: die Woche belegen (Gericht in eine Zelle setzen, verschieben, Essen eintragen).
     Darum steht der Kalender vorn, die Werkzeugleiste ist auf Mahlzeit + Woche reduziert, und der
     Zell-Picker bzw. das Eintrag-Detail öffnen rechts neben dem Raster (kein Scrollen unter die Matrix).
     Die Kennzahlen-Spalte rechts lässt sich einklappen; bei sieben Öffnungstagen startet sie
     eingeklappt, damit das Raster auf 1440 px ohne Querscrollen passt.
     Kopf: genau eine Hauptaktion (Speichern), eine KI-Aktion (leere Zellen füllen), Woche drucken/kopieren,
     Produktion und Löschen im Menü „Weitere Aktionen". Alle wire:-Bindungen, wire:keys, Event-Namen und
     data-Marker unverändert. --}}
@php
    $tagKurz = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    $monatNamen = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];

    $zahl = fn ($wert, int $stellen = 0) => number_format((float) $wert, $stellen, ',', '.');
    $mehrzahl = fn (int $n, string $eins, string $viele) => number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $eins : $viele);

    // Seitenlokale Klassen — nur Tokens, damit hell und Werkbank stimmen.
    $etikett = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $seg = 'inline-flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]';
    $segKnopf = 'h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors';
    $segAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $karte = 'fa-surface p-3 flex flex-col gap-2';
    $haken = 'rounded accent-[var(--fa-accent)]';

    // Wareneinsatz-Zustand gegen das Zielband (Service liefert ok · unter · ueber · weit_ueber · unbekannt).
    $wesText = ['ok' => 'text-[var(--fa-ok)]', 'unter' => 'text-[var(--fa-info)]', 'ueber' => 'text-[var(--fa-warn)]', 'weit_ueber' => 'text-[var(--fa-crit)]', 'unbekannt' => 'text-[var(--fa-ink-3)]'];
    $wesPunkt = ['ok' => 'bg-[var(--fa-ok)]', 'unter' => 'bg-[var(--fa-info)]', 'ueber' => 'bg-[var(--fa-warn)]', 'weit_ueber' => 'bg-[var(--fa-crit)]', 'unbekannt' => 'bg-[var(--fa-ink-3)]'];
    $ampelTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit'];
    $konfidenzText = ['high' => 'hoch', 'medium' => 'mittel', 'low' => 'niedrig', 'none' => 'keine'];
    $typLabel = ['gericht' => 'Gericht', 'concept' => 'Konzept', 'paket' => 'Paket'];

    $gaesteAusRollen = (bool) ($zk['gaeste_aus_rollen'] ?? false);
    $kwText = 'KW ' . (int) $montagDt->format('W') . ' · ' . ($mahlzeiten[$mahlzeit] ?? '');
@endphp

<x-foodalchemist::modal name="speiseplan-editor" fullscreen dark-canvas title="Speiseplan bearbeiten"
    :title-name="$sp?->name">

    <x-slot:actions>
        @if($sp)
            <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1">
                @if($ausrollenInfo)<x-fa::signal tone="info" data-sp-ausrollen-info>{{ $ausrollenInfo }}</x-fa::signal>@endif
                @if($kaskadeMeldung)<x-fa::signal tone="warn">{{ $kaskadeMeldung }}</x-fa::signal>@endif
                @if($prodHinweis)<x-fa::signal tone="ok" data-sp-prod-hinweis>{{ $prodHinweis }}</x-fa::signal>@endif
                @if($prodFehler)<x-fa::signal tone="crit" data-sp-prod-fehler>{{ $prodFehler }}</x-fa::signal>@endif
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- P5 / Spec 57 · 10.1: erst prüfen (leere Zellen, Umfang), dann im Hinweis oben starten. --}}
                <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="vollKaskadePruefen" wire:loading.attr="disabled" wire:target="vollKaskadePruefen" data-sp-voll-kaskade>
                    <span wire:loading.remove wire:target="vollKaskadePruefen">Leere Zellen mit KI füllen</span>
                    <span wire:loading wire:target="vollKaskadePruefen">Prüfe …</span>
                </x-fa::button>
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                        <a href="{{ route('foodalchemist.speiseplan.dokument', ['id' => $sp->id, 'mahlzeit' => $mahlzeit, 'montag' => $montagDt->format('Y-m-d')]) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                           class="{{ $menuePunkt }}" title="Wochenaushang zum Drucken oder als PDF, mit Legende der Allergene und Zusatzstoffe" data-sp-aushang>
                            @svg('heroicon-o-printer', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Woche drucken
                        </a>
                        @if($ansicht === 'woche')
                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="wocheKopierenOeffnen" class="{{ $menuePunkt }}" data-sp-woche-kopieren-btn>
                                @svg('heroicon-o-document-duplicate', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Woche kopieren
                            </button>
                        @endif
                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="anProduktion"
                                wire:confirm="Diese Woche ({{ $mahlzeiten[$mahlzeit] ?? '' }}) an die Produktion übergeben? Je Werktag mit Belegung wird ein Produktionsauftrag angelegt (Menge = Teilnehmerzahl)."
                                class="{{ $menuePunkt }}" title="Je Werktag mit Belegung ein Produktionsauftrag, Menge = Teilnehmerzahl" data-sp-produktion>
                            @svg('heroicon-o-fire', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Woche an Produktion übergeben
                        </button>
                        <div class="my-1 border-t border-[var(--fa-line)]" role="separator"></div>
                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="loeschen({{ $sp->id }})" wire:confirm="Speiseplan löschen?"
                                class="{{ $menuePunkt }} text-[var(--fa-crit)]" data-sp-loeschen>
                            @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Speiseplan löschen
                        </button>
                    </div>
                </div>
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" wire:loading.attr="disabled" wire:target="speichern" data-sp-speichern>Speichern</x-fa::button>
            </div>
        @endif
    </x-slot:actions>

    @if($sp && $kosten)
        @php
            $kfOk = collect($kostformen)->where('erfuellt', true)->count();
            $kfN = count($kostformen);
            $zkWoche = $zk['woche'] ?? null;
            $wesStatus = $zkWoche['status'] ?? 'unbekannt';
        @endphp
        <x-slot:kpiHeader>
            <x-fa::kpis data-sp-kpis :items="[
                ['kpi' => 'umsatz', 'label' => 'Umsatz der Woche (Prognose)', 'primary' => true,
                 'value' => $zkWoche ? $zahl($zkWoche['umsatz']) . ' €' : '–'],
                ['kpi' => 'wes', 'label' => 'Ø Wareneinsatz',
                 'tone' => $wesStatus === 'ok' ? 'ok' : (in_array($wesStatus, ['ueber', 'weit_ueber'], true) ? 'warn' : null),
                 'value' => ($zkWoche['wes'] ?? null) !== null ? $zahl($zkWoche['wes'], 1) . ' %' : '–'],
                ['kpi' => 'essen', 'label' => $gaesteAusRollen ? 'Gäste der Woche (Hauptgänge)' : 'Portionen der Woche',
                 'value' => $zkWoche ? $zahl($gaesteAusRollen ? $zkWoche['gaeste'] : $zkWoche['portionen']) : '–'],
                ['kpi' => 'kostform', 'label' => 'Kostformen',
                 'tone' => $kfN > 0 && $kfOk === $kfN ? 'ok' : ($kfOk > 0 ? 'warn' : null),
                 'value' => $kfN > 0 ? $kfOk . ' von ' . $kfN . ' abgedeckt' : '–'],
                ['kpi' => 'wdh', 'label' => 'Zu frühe Wiederholungen',
                 'tone' => count($wiederholungen) > 0 ? 'warn' : 'ok',
                 'value' => (string) count($wiederholungen)],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($sp === null)
        <x-fa::empty icon="heroicon-o-calendar-days" title="Kein Plan geladen">Den Editor schließen und in der Liste einen Speiseplan wählen.</x-fa::empty>
    @else
        {{-- Spec 57 · 10.1: Bestätigung vor dem KI-Lauf (kostet Zeit und Rechenleistung). --}}
        @if($kaskadeVorschau)
            <x-fa::notice tone="info" title="Leere Zellen mit KI füllen?" data-sp-kaskade-bestaetigung>
                {{ $mehrzahl((int) $kaskadeVorschau['leer'], 'leere Zelle', 'leere Zellen') }} im {{ $kaskadeVorschau['wochen'] }}-Wochen-Zyklus
                ({{ $mehrzahl((int) $kaskadeVorschau['linien'], 'Linie', 'Linien') }} an den Öffnungstagen, jede Linie in ihrer Mahlzeit).
                Dieser Lauf schlägt {{ $mehrzahl((int) $kaskadeVorschau['dieser_lauf'], 'Gericht', 'Gerichte') }} vor. Jedes entsteht als Entwurf und wird in der Leitstelle freigegeben.
                @if($kaskadeVorschau['gedeckelt']) Der Rest folgt mit dem nächsten Lauf (höchstens 6 Wochen je Lauf). @endif
                <x-slot:actions>
                    <x-fa::button variant="ghost" size="sm" wire:click="vollKaskadeAbbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="ai" size="sm" icon="heroicon-m-sparkles" wire:click="vollKaskadeStarten" wire:loading.attr="disabled" wire:target="vollKaskadeStarten" data-sp-kaskade-start>
                        <span wire:loading.remove wire:target="vollKaskadeStarten">KI-Lauf starten</span>
                        <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
                    </x-fa::button>
                </x-slot:actions>
            </x-fa::notice>
        @endif

        {{-- Zwei Spalten: Reiter (links, breit) + Kontext-Spalte (rechts: Picker/Detail, darunter Kennzahlen).
             -mx-6 hebt das Body-px-6 auf; die Mitte bekommt px-6 zurück, damit die klebende Reiterleiste
             (-mx-6) wieder auf Spaltenbreite spannt. `rail` = Kennzahlen sichtbar (nur Client-Zustand).
             Spec 59: `markiert` = Eintrag-Ids, die ein Chip der Abwechslungs-Karte hervorhebt (rein clientseitig;
             die Zellen im Raster lesen es per :class, siehe partials/zelle). --}}
        <div class="flex gap-4 -mx-6 items-start" x-data="{ rail: {{ count($wochenTage) <= 6 ? 'true' : 'false' }}, markiert: null, markierKey: null }">
            <div class="flex-1 min-w-0 px-6">
                <x-foodalchemist::editor-tabs marker="sp" wire-key="sp-tabs-{{ $sp->id }}" :init="'kalender'"
                    :tabs="[
                        'kalender' => 'Kalender',
                        'mengen' => 'Mengen',
                        'bedarf' => 'Bedarf',
                        'planist' => 'Plan/Ist',
                        'linien' => 'Menü-Linien',
                        'stammdaten' => 'Stammdaten',
                        'praesentation' => 'Druck und Aushang',
                    ]">

                    {{-- ═══ Reiter: KALENDER ═══ --}}
                    <div x-show="tab === 'kalender'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-kalender>
                        {{-- Werkzeugleiste in Demo-Reihenfolge: Woche/Monat · Mahlzeit · Kompakt/Detail, Navigation rechts --}}
                        <div class="flex flex-wrap items-center gap-3">
                            <div role="group" aria-label="Ansicht" class="{{ $seg }}">
                                @foreach(['woche' => 'Woche', 'monat' => 'Monat'] as $av => $al)
                                    <button type="button" wire:click="ansichtSetzen('{{ $av }}')" aria-pressed="{{ $ansicht === $av ? 'true' : 'false' }}"
                                            class="{{ $segKnopf }} {{ $ansicht === $av ? $segAn : $segAus }}">{{ $al }}</button>
                                @endforeach
                            </div>
                            <div role="group" aria-label="Mahlzeit" class="{{ $seg }}">
                                @foreach($mahlzeiten as $mk => $ml)
                                    <button type="button" wire:click="mahlzeitSetzen('{{ $mk }}')" aria-pressed="{{ $mahlzeit === $mk ? 'true' : 'false' }}"
                                            class="{{ $segKnopf }} {{ $mahlzeit === $mk ? $segAn : $segAus }}">{{ $ml }}</button>
                                @endforeach
                            </div>

                            @if($ansicht === 'woche')
                                {{-- Spec 57 · Paket 1: Zell-Dichte --}}
                                <div role="group" aria-label="Zell-Dichte" class="{{ $seg }}" data-sp-dichte>
                                    @foreach(['kompakt' => 'Kompakt', 'detail' => 'Detail'] as $dv => $dl)
                                        <button type="button" wire:click="dichteSetzen('{{ $dv }}')" class="{{ $segKnopf }} {{ $dichte === $dv ? $segAn : $segAus }}" aria-pressed="{{ $dichte === $dv ? 'true' : 'false' }}">{{ $dl }}</button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="ml-auto flex items-center gap-1">
                                @if($ansicht === 'woche')
                                    @php $letzterTag = $wochenTage !== [] ? end($wochenTage) : $montagDt->copy()->addDays(4); @endphp
                                    <x-fa::icon-button icon="heroicon-m-chevron-left" label="Vorherige Woche" size="sm" wire:click="wocheVerschieben(-1)" />
                                    <span class="px-1 text-[length:var(--fa-text-base)] font-semibold tabular-nums text-[var(--fa-ink)] whitespace-nowrap">KW {{ (int) $montagDt->format('W') }}
                                        <span class="font-normal text-[var(--fa-ink-2)]">{{ ($wochenTage[0] ?? $montagDt)->format('d.m.') }} bis {{ $letzterTag->format('d.m.Y') }}</span></span>
                                    <x-fa::icon-button icon="heroicon-m-chevron-right" label="Nächste Woche" size="sm" wire:click="wocheVerschieben(1)" />
                                    <x-fa::button variant="ghost" size="sm" wire:click="heute">Heute</x-fa::button>
                                @else
                                    <x-fa::icon-button icon="heroicon-m-chevron-left" label="Vorheriger Monat" size="sm" wire:click="monatVerschieben(-1)" />
                                    <span class="px-1 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)] whitespace-nowrap">{{ $monatNamen[(int) $monatStart->month] }} {{ $monatStart->year }}</span>
                                    <x-fa::icon-button icon="heroicon-m-chevron-right" label="Nächster Monat" size="sm" wire:click="monatVerschieben(1)" />
                                @endif
                            </div>

                        </div>

                        @if($ansicht === 'woche')
                            @if($umbauHinweis)<x-fa::notice tone="info" data-sp-umbau-hinweis>{{ $umbauHinweis }}</x-fa::notice>@endif

                            {{-- Spec 57 · Paket 5: Woche kopieren (geöffnet über „Weitere Aktionen") --}}
                            @if($wocheKopierenOffen)
                                <x-fa::section title="KW {{ (int) $montagDt->format('W') }} kopieren" meta="alle Mahlzeiten" icon="heroicon-o-document-duplicate" data-sp-woche-kopieren>
                                    <div class="flex flex-wrap items-end gap-4">
                                        <x-fa::field label="Zielwoche" for="sp-kopie-ziel" class="w-72">
                                            <x-fa::select id="sp-kopie-ziel" wire:model="wocheKopierenZiel">
                                                @foreach($zielWochen as $zw)
                                                    <option value="{{ $zw->format('Y-m-d') }}">KW {{ $zw->isoWeek() }} · {{ $zw->format('d.m.') }} bis {{ $zw->copy()->addDays(6)->format('d.m.Y') }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="wocheKopierenMerge" class="{{ $haken }}" /> Mit vorhandenen Einträgen zusammenführen</label>
                                        <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="wocheKopierenPax" class="{{ $haken }}" /> Essen-Zahlen mitnehmen</label>
                                        <div class="ml-auto flex items-center gap-2">
                                            <x-fa::button variant="ghost" wire:click="$set('wocheKopierenOffen', false)">Abbrechen</x-fa::button>
                                            <x-fa::button icon="heroicon-m-document-duplicate" wire:click="wocheKopieren">Woche kopieren</x-fa::button>
                                        </div>
                                    </div>
                                    <p class="{{ $leise }}">Ohne Zusammenführen werden belegte Zellen der Zielwoche ersetzt. Einzelne Einträge kopierst du über ihr Detail.</p>
                                </x-fa::section>
                            @endif

                            {{-- Wochen-Raster: Linien × Öffnungstage (Spec 57 · Paket 1/2/9) --}}
                            @php
                                $zkEintraege = $zk['eintraege'] ?? [];
                                $zkTage = $zk['tage'] ?? [];
                                $zkLinien = $zk['linien'] ?? [];
                                $zeilenLinien = $matrixLinien->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role, 'plu' => $l->plu, 'is_standing' => (bool) $l->is_standing])->values();
                                if (isset($raster[0])) {
                                    $zeilenLinien->push(['id' => 0, 'name' => 'Ohne Linie', 'color' => null, 'role' => null, 'plu' => null, 'is_standing' => false]);
                                }
                            @endphp
                            <section class="fa-surface min-w-0" aria-label="Wochenraster">
                                <div class="overflow-x-auto" data-sp-matrix x-data="{ dragId: null }">
                                    <table class="fa-table table-fixed" style="min-width: {{ 152 + count($wochenTage) * 136 }}px">
                                        <thead><tr>
                                            <th class="w-36">Linie</th>
                                            @foreach($wochenTage as $tag)
                                                <th class="{{ $tag->isToday() ? 'text-[var(--fa-accent)]' : '' }}">
                                                    <span class="font-semibold">{{ $tagKurz[$tag->isoWeekday()] }}</span>
                                                    <span class="font-normal tabular-nums {{ $tag->isToday() ? '' : 'text-[var(--fa-ink-3)]' }}">{{ $tag->format('d.m.') }}</span>
                                                    @if($tag->isToday())<span class="sr-only">(heute)</span>@endif
                                                </th>
                                            @endforeach
                                        </tr></thead>
                                        <tbody>
                                            @foreach($zeilenLinien as $zl)
                                                @php $lk = $zkLinien[$zl['id']] ?? null; @endphp
                                                <tr class="align-top" wire:key="zeile-{{ $zl['id'] }}">
                                                    <td class="align-top" data-sp-linie-kopf="{{ $zl['id'] }}">
                                                        <span class="flex items-start gap-2">
                                                            <span class="mt-1 w-2.5 h-2.5 rounded-full shrink-0 {{ $zl['color'] ? '' : 'bg-[var(--fa-ink-3)]' }}" @if($zl['color']) style="background: {{ $zl['color'] }}" @endif></span>
                                                            <span class="min-w-0 font-semibold break-words {{ $zl['id'] === 0 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $zl['name'] }}</span>
                                                        </span>
                                                        <span class="mt-1 flex flex-col gap-0.5 {{ $leise }}">
                                                            @if($zl['id'] === 0)
                                                                <span>Keiner Linie zugeordnet</span>
                                                            @else
                                                                <span>{{ collect([$rollen[$zl['role']] ?? null, $zl['plu'] ? 'Kasse ' . $zl['plu'] : null])->filter()->implode(' · ') ?: 'Ohne Rolle' }}</span>
                                                                @if($lk)
                                                                    <span class="tabular-nums" title="{{ ($lk['band']['quelle'] ?? '') === 'team' ? 'Kein eigenes Zielband, es gilt das Ziel des Betriebs' : 'Zielband der Linie' }}">
                                                                        Ziel {{ $lk['band']['min'] !== null ? number_format((float) $lk['band']['min'], 0) . ' bis ' : 'bis ' }}{{ number_format((float) $lk['band']['max'], 0) }} %
                                                                    </span>
                                                                @endif
                                                                @if($zl['is_standing'])<span>Dauerangebot</span>@endif
                                                            @endif
                                                        </span>
                                                    </td>
                                                    @foreach($wochenTage as $tag)
                                                        @php
                                                            $ymd = $tag->format('Y-m-d');
                                                            $eintraege = $raster[$zl['id']][$ymd] ?? [];
                                                            $aktiv = $cellDatum === $ymd && $cellLinie === ($zl['id'] ?: null);
                                                        @endphp
                                                        <td class="align-top p-1.5 {{ $aktiv ? 'bg-[var(--fa-accent-soft)]' : '' }}"
                                                            x-on:dragover.prevent
                                                            x-on:drop="if (dragId) { $wire.eintragVerschieben(dragId, '{{ $ymd }}', {{ $zl['id'] }}); dragId = null }"
                                                            data-sp-drop="{{ $zl['id'] }}|{{ $ymd }}">
                                                            <div class="flex flex-col gap-1.5">
                                                                @foreach($eintraege as $e)
                                                                    @include('foodalchemist::livewire.speiseplan.partials.zelle', ['e' => $e, 'k' => $zkEintraege[$e->id] ?? null, 'farbe' => $zl['color'], 'dichte' => $dichte, 'sp' => $sp, 'detailId' => $detailEintragId])
                                                                @endforeach
                                                                @if($zl['id'] !== 0)
                                                                    <button type="button" wire:click="zelleOeffnen('{{ $ymd }}', {{ $zl['id'] }})"
                                                                            aria-label="{{ $zl['name'] }} am {{ $tagKurz[$tag->isoWeekday()] }} {{ $tag->format('d.m.') }} belegen"
                                                                            title="Gericht setzen"
                                                                            class="flex w-full items-center justify-center h-7 rounded-[var(--fa-radius-control)] border border-dashed transition-colors {{ $aktiv ? 'border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'border-[var(--fa-line-strong)] text-[var(--fa-ink-3)] hover:border-[var(--fa-accent)] hover:text-[var(--fa-accent)]' }}">@svg('heroicon-m-plus', 'w-4 h-4')</button>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                            @if($zeilenLinien->isEmpty())
                                                <tr><td colspan="{{ count($wochenTage) + 1 }}">
                                                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Für diese Mahlzeit gibt es keine Linie">Im Reiter „Menü-Linien" eine Linie anlegen (oder eine Linie für alle Mahlzeiten lassen), dann Gerichte in die Tage setzen.</x-fa::empty>
                                                </td></tr>
                                            @else
                                                {{-- Spec 57 · Paket 1: Tagesfuß --}}
                                                <tr class="bg-[var(--fa-ground)]" data-sp-tagesfuss>
                                                    <td class="align-top"><span class="{{ $etikett }}">Tag gesamt</span></td>
                                                    @foreach($wochenTage as $tag)
                                                        @php $tf = $zkTage[$tag->format('Y-m-d')] ?? null; @endphp
                                                        <td class="align-top text-[length:var(--fa-text-sm)] tabular-nums">
                                                            @if($tf && $tf['portionen'] > 0)
                                                                <dl class="flex flex-col gap-0.5">
                                                                    <div class="flex justify-between gap-2"><dt class="text-[var(--fa-ink-3)]">{{ $gaesteAusRollen ? 'Gäste' : 'Portionen' }}</dt><dd class="text-[var(--fa-ink)]">{{ $zahl($gaesteAusRollen ? $tf['gaeste'] : $tf['portionen']) }}</dd></div>
                                                                    <div class="flex justify-between gap-2"><dt class="text-[var(--fa-ink-3)]">Umsatz</dt><dd class="text-[var(--fa-ink)]">{{ $zahl($tf['umsatz']) }} €</dd></div>
                                                                    <div class="flex justify-between gap-2"><dt class="text-[var(--fa-ink-3)]">Wareneinsatz</dt><dd class="font-medium {{ $wesText[$tf['status']] ?? 'text-[var(--fa-ink-3)]' }}">{{ $tf['wes'] !== null ? $zahl($tf['wes']) . ' %' : '–' }}</dd></div>
                                                                    @if($tf['ek_je_gast'] !== null)
                                                                        <div class="flex justify-between gap-2"><dt class="text-[var(--fa-ink-3)]">EK je Gast</dt><dd class="text-[var(--fa-ink)]">{{ $zahl($tf['ek_je_gast'], 2) }} €</dd></div>
                                                                    @endif
                                                                </dl>
                                                            @else
                                                                <span class="text-[var(--fa-ink-3)]">Nicht belegt</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 {{ $leise }}" data-sp-legende>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[var(--fa-ok)]"></span>Wareneinsatz im Zielband</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[var(--fa-warn)]"></span>darüber</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[var(--fa-crit)]"></span>weit darüber</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[var(--fa-info)]"></span>darunter</span>
                                <span>Vg vegan · Vt vegetarisch · Sw Schwein · Rd Rind · Fi Fisch · Fl Fleisch</span>
                                <span>Buchstaben und Zahlen: Allergene und Zusatzstoffe · Stern am Preis: Linienpreis</span>
                            </div>
                            {{-- Einfügen unter der Matrix, in voller Breite (Dominique 2026-10-06: „wie in der Demo unten drunter").
                                 Stand vorher als schmales Seitenpanel rechts; die Grundanordnung des Originals gilt. --}}
                            @if($cellDatum !== null)
                                <section class="fa-surface p-4 flex flex-col gap-3 border-[var(--fa-accent-line)]" data-sp-picker>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
                                            {{ $pickerErsetzenId ? 'Ersetzen' : 'Einfügen' }} · {{ $tagKurz[\Illuminate\Support\Carbon::parse($cellDatum)->isoWeekday()] ?? '' }} {{ \Illuminate\Support\Carbon::parse($cellDatum)->format('d.m.') }} · {{ $linien->firstWhere('id', $cellLinie)?->name ?? 'Ohne Linie' }}
                                        </p>
                                        <div role="group" aria-label="Art des Inhalts" class="{{ $seg }}">
                                            @foreach($typLabel as $tv => $tl)
                                                <button type="button" wire:click="$set('pickerTyp', '{{ $tv }}')" aria-pressed="{{ $pickerTyp === $tv ? 'true' : 'false' }}" class="{{ $segKnopf }} {{ $pickerTyp === $tv ? $segAn : $segAus }}">{{ $tl }}</button>
                                            @endforeach
                                        </div>
                                        <div class="relative flex-1 min-w-[14rem]">
                                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                                            <x-fa::input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ $typLabel[$pickerTyp] ?? 'Gericht' }} suchen" class="pl-8" aria-label="{{ $typLabel[$pickerTyp] ?? 'Gericht' }} suchen" />
                                        </div>
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="cellSchliessen">Schließen</x-fa::button>
                                    </div>

                                    {{-- Spec 42: Facetten (Hauptgruppe → Unterklasse) nur für Gerichte --}}
                                    @if($pickerTyp === 'gericht' && $pickerHauptgruppen->isNotEmpty())
                                        <div class="flex flex-wrap gap-1.5" data-sp-picker-facetten>
                                            <button type="button" wire:click="pickerWaehleHg(null)" class="{{ $chip }} {{ $pickerHauptgruppe === null ? $chipAn : $chipAus }}">Alle</button>
                                            @foreach($pickerHauptgruppen as $hg)
                                                <button type="button" wire:click="pickerWaehleHg({{ $hg->id }})" class="{{ $chip }} {{ (int) $pickerHauptgruppe === (int) $hg->id ? $chipAn : $chipAus }}">{{ $hg->label }}</button>
                                            @endforeach
                                        </div>
                                        @if($pickerUntergruppen->isNotEmpty())
                                            <div class="flex flex-wrap gap-1.5 pl-3 border-l-2 border-[var(--fa-line)]" data-sp-picker-unterklassen>
                                                @foreach($pickerUntergruppen as $uk)
                                                    <button type="button" wire:click="pickerWaehleKlasse({{ $uk->id }})" class="{{ $chip }} {{ (int) $pickerDishClass === (int) $uk->id ? $chipAn : $chipAus }}">{{ $uk->label }}</button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif

                                    @if($kandidaten->isNotEmpty())
                                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-0.5 max-h-80 overflow-y-auto">
                                            @foreach($kandidaten as $k)
                                                <button type="button" wire:key="kand-{{ $pickerTyp }}-{{ $k->id }}" wire:click="inhaltHinzu('{{ $pickerTyp }}', {{ $k->id }})"
                                                        class="group flex items-center justify-between gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left hover:bg-[var(--fa-hover)]">
                                                    <span class="min-w-0 truncate text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $k->name }}">{{ $k->name }}</span>
                                                    <span class="flex items-center gap-2 shrink-0 {{ $leise }} tabular-nums">
                                                        @if($pickerTyp === 'gericht' && ($k->dishClass?->diet_form))<span>{{ ucfirst($k->dishClass->diet_form) }}</span>@endif
                                                        @if($pickerTyp === 'gericht' && $k->sales_net)
                                                            <span>{{ $zahl($k->sales_net, 2) }} €</span>
                                                        @elseif($pickerTyp === 'concept' && ($k->price_per_person_cache ?? null))
                                                            <span>{{ $zahl($k->price_per_person_cache, 2) }} € je Person</span>
                                                        @endif
                                                        @svg('heroicon-m-plus-circle', 'w-5 h-5 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)]')
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @else
                                        <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Treffer">Suchbegriff oder Gruppe ändern.</x-fa::empty>
                                    @endif
                                </section>
                            @endif
                        @else
                            {{-- Monats-Kalender --}}
                            @php $gridStart = $monatStart->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY); @endphp
                            <x-fa::section title="{{ $monatNamen[(int) $monatStart->month] }} {{ $monatStart->year }}" icon="heroicon-o-calendar" :meta="$mahlzeiten[$mahlzeit] ?? ''">
                                <div class="grid grid-cols-7 gap-1">
                                    @foreach([1, 2, 3, 4, 5, 6, 7] as $wd)
                                        <div class="pb-1 text-center {{ $etikett }}">{{ $tagKurz[$wd] }}</div>
                                    @endforeach
                                    @for($i = 0; $i < 42; $i++)
                                        @php
                                            $tag = $gridStart->copy()->addDays($i);
                                            $ymd = $tag->format('Y-m-d');
                                            $imMonat = (int) $tag->month === (int) $monatStart->month;
                                            $info = $monatsRaster[$ymd] ?? null;
                                        @endphp
                                        <button type="button" wire:key="cal-{{ $ymd }}" wire:click="tagOeffnen('{{ $ymd }}')"
                                                aria-label="{{ $tag->format('d.m.Y') }} in der Wochenansicht öffnen"
                                                class="flex flex-col items-stretch text-left rounded-[var(--fa-radius-control)] border p-2 h-20 transition-colors {{ $imMonat ? 'border-[var(--fa-line)] bg-[var(--fa-surface)] hover:bg-[var(--fa-hover)]' : 'border-transparent opacity-40' }} {{ $tag->isToday() ? 'ring-2 ring-[var(--fa-accent)]' : '' }}">
                                            <span class="flex items-center justify-between">
                                                <span class="text-[length:var(--fa-text-sm)] tabular-nums {{ $tag->isToday() ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink-2)]' }}">{{ $tag->format('j') }}</span>
                                                @if($info)<x-fa::badge>{{ $mehrzahl((int) $info['count'], 'Eintrag', 'Einträge') }}</x-fa::badge>@endif
                                            </span>
                                            @if($info && $info['vk'] > 0)
                                                <span class="mt-auto text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-2)]">{{ $zahl($info['vk'], 2) }} €</span>
                                            @endif
                                        </button>
                                    @endfor
                                </div>
                                <p class="{{ $leise }}">Tag anklicken, um ihn in der Wochenansicht zu öffnen. Gezeigt wird die Belegung der Mahlzeit „{{ $mahlzeiten[$mahlzeit] ?? '' }}".</p>
                            </x-fa::section>
                        @endif
                    </div>

                    {{-- ═══ Spec 57 · Paket 3: Reiter MENGEN (Essen je Linie × Tag) ═══ --}}
                    <div x-show="tab === 'mengen'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-mengen>
                        <x-fa::section title="Mengen" icon="heroicon-o-users" :meta="$kwText" description="Woche und Mahlzeit wie im Kalender. Eine Zahl gilt für alle Einträge der Zelle; leer oder 0 setzt auf den Standard der Linie bzw. des Plans zurück.">
                            <x-slot:actions>
                                <x-fa::button size="sm" icon="heroicon-m-arrow-uturn-left" wire:click="mengenVorwoche" data-sp-mengen-vorwoche>Mengen aus Vorwoche übernehmen</x-fa::button>
                            </x-slot:actions>
                            <div class="flex flex-wrap items-end gap-2">
                                <x-fa::field label="Alle Mengen skalieren um Faktor" for="sp-mengen-faktor" class="w-56">
                                    <x-fa::input id="sp-mengen-faktor" inputmode="decimal" wire:model="mengenFaktor" numeric aria-label="Skalierungsfaktor" />
                                </x-fa::field>
                                <x-fa::button wire:click="mengenSkalieren">Faktor anwenden</x-fa::button>
                                @if($mengenHinweis)<x-fa::signal tone="info" class="self-center" data-sp-mengen-hinweis>{{ $mengenHinweis }}</x-fa::signal>@endif
                            </div>
                            @if($mengen && $mengen['zeilen'] !== [])
                                <div class="overflow-x-auto -mx-4">
                                    <table class="fa-table fa-table--compact" data-sp-mengen-matrix>
                                        <thead><tr>
                                            <th class="pl-4">Linie</th>
                                            <th class="num" title="Summe der Vorwoche (Planwerte)">Vorwoche</th>
                                            <th class="num" title="Durchschnitt der letzten vier Wochen (Planwerte)">Ø 4 Wochen</th>
                                            @foreach($mengen['tage'] as $mt)
                                                <th class="text-center">{{ $tagKurz[\Illuminate\Support\Carbon::parse($mt)->isoWeekday()] }}</th>
                                            @endforeach
                                            <th class="num">Summe</th><th class="num">Anteil</th><th class="num">Wareneinsatz</th><th class="num">Ø Preis</th><th class="num pr-4">Umsatz</th>
                                        </tr></thead>
                                        <tbody>
                                            @foreach($mengen['zeilen'] as $mz)
                                                <tr wire:key="mz-{{ $mz['line_id'] }}">
                                                    <td class="pl-4"><span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $mz['color'] ? '' : 'bg-[var(--fa-ink-3)]' }}" @if($mz['color']) style="background: {{ $mz['color'] }}" @endif></span><span class="whitespace-nowrap">{{ $mz['name'] }}</span></span></td>
                                                    <td class="num text-[var(--fa-ink-2)]">{{ $mz['vorwoche'] ?: '–' }}</td>
                                                    <td class="num text-[var(--fa-ink-2)]">{{ $mz['schnitt4'] > 0 ? $zahl($mz['schnitt4']) : '–' }}</td>
                                                    @foreach($mengen['tage'] as $mt)
                                                        @php $mp = $mz['zellen'][$mt] ?? null; @endphp
                                                        <td class="text-center">
                                                            @if($mp !== null)
                                                                <input type="number" min="0" value="{{ $mp }}"
                                                                       wire:change="mengenSetzen({{ $mz['line_id'] }}, '{{ $mt }}', $event.target.value)"
                                                                       aria-label="Essen {{ $mz['name'] }} am {{ \Illuminate\Support\Carbon::parse($mt)->format('d.m.') }}"
                                                                       class="fa-control h-7 w-20 text-right tabular-nums text-[length:var(--fa-text-md)]" />
                                                            @else
                                                                <span class="text-[var(--fa-ink-3)]" title="Zelle nicht belegt, erst im Kalender ein Gericht setzen">–</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="num font-semibold">{{ $zahl($mz['summe']) }}</td>
                                                    <td class="num text-[var(--fa-ink-2)]">{{ $mz['anteil'] !== null ? $zahl($mz['anteil'], 1) . ' %' : '–' }}</td>
                                                    <td class="num">{{ $mz['wes'] !== null ? $zahl($mz['wes'], 1) . ' %' : '–' }}</td>
                                                    <td class="num"><x-fa::money :value="$mz['vk_schnitt']" /></td>
                                                    <td class="num pr-4">{{ $zahl($mz['umsatz']) }} €</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="bg-[var(--fa-ground)] font-semibold">
                                                <td class="pl-4 py-2"><span class="{{ $etikett }}">Gesamt</span></td>
                                                <td class="num py-2 text-[var(--fa-ink-2)]">{{ $zahl($mengen['summe']['vorwoche']) }}</td>
                                                <td class="num py-2 text-[var(--fa-ink-2)]">{{ $zahl($mengen['summe']['schnitt4']) }}</td>
                                                @foreach($mengen['tage'] as $mt)
                                                    <td class="py-2 text-center tabular-nums">{{ $zahl($mengen['summe']['je_tag'][$mt] ?? 0) }}</td>
                                                @endforeach
                                                <td class="num py-2">{{ $zahl($mengen['summe']['summe']) }}</td>
                                                <td class="py-2"></td>
                                                <td class="num py-2">{{ $mengen['summe']['wes'] !== null ? $zahl($mengen['summe']['wes'], 1) . ' %' : '–' }}</td>
                                                <td class="py-2"></td>
                                                <td class="num py-2 pr-4">{{ $zahl($mengen['summe']['umsatz']) }} €</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <p class="{{ $leise }}">„Vorwoche" und „Ø 4 Wochen" sind Planwerte. Echte Verkaufszahlen stehen im Reiter „Plan/Ist".</p>
                            @else
                                <x-fa::empty compact icon="heroicon-o-queue-list" title="Für diese Mahlzeit gibt es keine Linien" />
                            @endif
                        </x-fa::section>
                    </div>

                    {{-- ═══ Spec 57 · Paket 4: Reiter BEDARF (Zutaten aus Plan × Mengen, nur lesend) ═══ --}}
                    <div x-show="tab === 'bedarf'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-bedarf>
                        <x-fa::section title="Bedarf" icon="heroicon-o-shopping-cart" :meta="$kwText" description="Rezepte bis zum Grundprodukt aufgelöst, in der Basiseinheit, mit dem Hauptartikel des Lieferanten und ganzen Gebinden.">
                            <div class="flex flex-wrap items-center gap-3">
                                <div role="group" aria-label="Zeitraum" class="{{ $seg }}">
                                    <button type="button" wire:click="bedarfTagSetzen(null)" aria-pressed="{{ $bedarfTag === null ? 'true' : 'false' }}" class="{{ $segKnopf }} {{ $bedarfTag === null ? $segAn : $segAus }}">Ganze Woche</button>
                                    @foreach($wochenTage as $wt)
                                        <button type="button" wire:click="bedarfTagSetzen('{{ $wt->format('Y-m-d') }}')" aria-pressed="{{ $bedarfTag === $wt->format('Y-m-d') ? 'true' : 'false' }}" class="{{ $segKnopf }} {{ $bedarfTag === $wt->format('Y-m-d') ? $segAn : $segAus }}">{{ $tagKurz[$wt->isoWeekday()] }}</button>
                                    @endforeach
                                </div>
                                @unless($bedarfAn)
                                    <x-fa::button icon="heroicon-m-calculator" wire:click="bedarfBerechnen" data-sp-bedarf-berechnen>Bedarf berechnen</x-fa::button>
                                @endunless
                            </div>
                            @if($bedarfAn && $bedarf)
                                @if($bedarf['liste'] === null)
                                    <x-fa::empty compact icon="heroicon-o-shopping-cart" title="In diesem Zeitraum ist nichts geplant" />
                                @else
                                    @foreach($bedarf['liste']['lieferanten'] as $lf)
                                        <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] overflow-hidden" wire:key="bedarf-{{ $loop->index }}">
                                            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-[var(--fa-ground)] border-b border-[var(--fa-line)]">
                                                <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $lf['lieferant'] }}</span>
                                                <span class="flex items-center gap-2 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-2)]">
                                                    {{ $mehrzahl(count($lf['positionen']), 'Position', 'Positionen') }} · EK {{ $zahl($lf['ek_summe'], 2) }} €
                                                    @unless($lf['ek_vollstaendig'])<x-fa::badge tone="warn">unvollständig</x-fa::badge>@endunless
                                                </span>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="fa-table fa-table--compact">
                                                    <thead><tr><th>Grundprodukt</th><th class="num">Menge</th><th>Gebinde und Artikel</th><th class="num">EK</th></tr></thead>
                                                    <tbody>
                                                        @foreach($lf['positionen'] as $pos)
                                                            <tr>
                                                                <td>{{ $pos['gp'] }}</td>
                                                                <td class="num"><x-fa::menge :value="(float) $pos['menge_kg']" unit="kg" /></td>
                                                                <td class="text-[var(--fa-ink-2)]">
                                                                    {{ $pos['lead_artikel'] ?? 'Kein Artikel hinterlegt' }}{{ $pos['lead_artikel_nr'] ? ' · ' . $pos['lead_artikel_nr'] : '' }}
                                                                    @if(($pos['gebinde']['berechenbar'] ?? false) && isset($pos['gebinde']['qty_packs']))
                                                                        · {{ $pos['gebinde']['qty_packs'] }}× {{ $pos['gebinde']['packaging_unit'] ?: 'Gebinde' }}{{ $pos['gebinde']['pack_qty'] ? ' à ' . rtrim(rtrim(number_format((float) $pos['gebinde']['pack_qty'], 3, ',', ''), '0'), ',') . ' ' . ($pos['gebinde']['pack_unit_code'] ?? '') : '' }}
                                                                    @elseif(! empty($pos['gebinde']['grund']))
                                                                        · <x-fa::signal tone="warn">{{ $pos['gebinde']['grund'] }}</x-fa::signal>
                                                                    @endif
                                                                </td>
                                                                <td class="num"><x-fa::money :value="$pos['bestell_ek_eur']" /></td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endforeach
                                    @if(! empty($bedarf['liste']['warnungen']))
                                        <x-fa::notice tone="warn">
                                            @foreach(array_slice($bedarf['liste']['warnungen'], 0, 5) as $w)<p>{{ $w }}</p>@endforeach
                                        </x-fa::notice>
                                    @endif
                                    <p class="{{ $leise }}">An den Einkauf geht der Bedarf über die Produktion: im Menü „Weitere Aktionen" die Woche an die Produktion übergeben, dann im Produktionsauftrag den Bedarf freigeben. So wird nichts doppelt bestellt.</p>
                                @endif
                            @endif
                        </x-fa::section>
                    </div>

                    {{-- ═══ Spec 57 · Paket 8: Reiter PLAN/IST (nur lesend, Verkaufsjournal) ═══ --}}
                    <div x-show="tab === 'planist'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-planist>
                        <x-fa::section title="Plan und Ist" icon="heroicon-o-scale" :meta="$kwText">
                            @if($planIst)
                                @php $pis = $planIst['summe']; @endphp
                                <x-fa::kpis :items="[
                                    ['label' => 'Essen geplant', 'value' => $zahl($pis['plan'])],
                                    ['label' => 'Verkauft (Ist)', 'value' => $planIst['hat_ist'] ? $zahl($pis['ist']) : 'Keine Daten', 'primary' => $planIst['hat_ist']],
                                    ['label' => 'Umsatz Plan', 'value' => $zahl($pis['plan_umsatz']) . ' €'],
                                    ['label' => 'Umsatz Ist', 'value' => $planIst['hat_ist'] ? $zahl($pis['ist_umsatz']) . ' €' : 'Keine Daten'],
                                ]" />
                                @if($pis['abweichung_pct'] !== null)
                                    <x-fa::signal :tone="$pis['abweichung_pct'] < 0 ? 'crit' : 'ok'">Verkauft {{ $zahl(abs($pis['abweichung_pct']), 1) }} % {{ $pis['abweichung_pct'] < 0 ? 'unter' : 'über' }} Plan</x-fa::signal>
                                @endif
                                @unless($planIst['hat_ist'])
                                    <x-fa::notice tone="warn">Für diese Woche liegen keine Verkaufszahlen vor. Verkäufe werden im Controlling importiert (Datei aus der Kasse) und dort den Gerichten zugeordnet.</x-fa::notice>
                                @endunless
                                @if($planIst['zeilen'] !== [])
                                    <div class="overflow-x-auto -mx-4">
                                        <table class="fa-table fa-table--compact" data-sp-planist>
                                            <thead><tr><th class="pl-4">Gericht</th><th class="num">Plan</th><th class="num">Ist</th><th class="num">Abweichung</th><th class="num">Umsatz Plan</th><th class="num pr-4">Umsatz Ist</th></tr></thead>
                                            <tbody>
                                                @foreach($planIst['zeilen'] as $pz)
                                                    <tr>
                                                        <td class="pl-4">{{ $pz['name'] }}</td>
                                                        <td class="num">{{ $zahl($pz['plan']) }}</td>
                                                        <td class="num">{{ $pz['ist'] !== null ? $zahl($pz['ist']) : '–' }}</td>
                                                        <td class="num {{ ($pz['abweichung_pct'] ?? 0) < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $pz['abweichung_pct'] !== null ? (($pz['abweichung_pct'] > 0 ? '+' : '') . $zahl($pz['abweichung_pct'], 1) . ' %') : '–' }}</td>
                                                        <td class="num">{{ $zahl($pz['plan_umsatz'], 2) }} €</td>
                                                        <td class="num pr-4">{{ $pz['ist_umsatz'] !== null ? $zahl($pz['ist_umsatz'], 2) . ' €' : '–' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @if($planIst['nicht_vergleichbar'] !== [])
                                    <p class="{{ $leise }}">Nicht vergleichbar (Konzept oder Paket, in der Kasse kein einzelnes Gericht): {{ implode(', ', $planIst['nicht_vergleichbar']) }}.</p>
                                @endif
                                <p class="{{ $leise }}">{{ $planIst['hinweis'] }}</p>
                            @endif
                        </x-fa::section>
                    </div>

                    {{-- ═══ Reiter: MENÜ-LINIEN ═══ --}}
                    <div x-show="tab === 'linien'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-linien>
                        <x-fa::section title="Menü-Linien" icon="heroicon-o-queue-list" :meta="$mehrzahl($linien->count(), 'Linie', 'Linien')" description="Jede Linie ist eine Ausgabestelle und eine Zeile im Kalender. Das Zielband ersetzt für die Linie das Ziel des Betriebs: Suppe und Dessert dürfen anders kalkulieren als der Hauptgang. Hauptgang-Linien zählen die Gäste des Tages.">
                            {{-- Spec 57 · Paket 2: jede Linie ist eine Ausgabestelle (Rolle, Kasse, Preis, Zielband). --}}
                            <div class="overflow-x-auto -mx-4">
                                <table class="fa-table">
                                    <thead><tr>
                                        <th class="pl-4">Linie</th><th>Rolle</th><th>Mahlzeit</th><th>Kasse</th>
                                        <th class="num">Preis</th><th class="num">Zielband Wareneinsatz</th><th class="num">Essen je Tag</th><th class="pr-4"><span class="sr-only">Aktionen</span></th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($linien as $linie)
                                            <tr wire:key="linie-{{ $linie->id }}" @if($editLinieId === $linie->id) aria-selected="true" @endif>
                                                <td class="pl-4">
                                                    <span class="flex items-center gap-2">
                                                        <span class="w-3 h-3 rounded-full shrink-0 {{ $linie->color ? '' : 'bg-[var(--fa-ink-3)]' }}" @if($linie->color) style="background: {{ $linie->color }}" @endif></span>
                                                        <span class="font-medium">{{ $linie->name }}</span>
                                                        @if($linie->is_vegetarian)<x-fa::badge tone="ok">vegetarisch</x-fa::badge>@endif
                                                        @if($linie->is_standing)<x-fa::badge title="Zählt nicht für die Wiederholungsregel">Dauerangebot</x-fa::badge>@endif
                                                    </span>
                                                </td>
                                                <td>{{ $rollen[$linie->role] ?? 'Ohne Rolle' }}</td>
                                                <td>{{ $linie->meal ? ($mahlzeiten[$linie->meal] ?? $linie->meal) : 'Alle Mahlzeiten' }}</td>
                                                <td class="tabular-nums">{{ $linie->plu ?: '–' }}</td>
                                                <td class="num">
                                                    @if($linie->manuellerPreis() !== null)
                                                        <x-fa::money :value="$linie->manuellerPreis()" /> <span class="text-[var(--fa-ink-3)]">fest</span>
                                                    @else
                                                        <span class="text-[var(--fa-ink-2)]">vom Gericht</span>
                                                    @endif
                                                </td>
                                                <td class="num">
                                                    @if($linie->target_wes_min_pct !== null || $linie->target_wes_max_pct !== null)
                                                        {{ $linie->target_wes_min_pct !== null ? number_format($linie->target_wes_min_pct, 0) : '0' }} bis {{ $linie->target_wes_max_pct !== null ? number_format($linie->target_wes_max_pct, 0) : 'offen' }} %
                                                    @else
                                                        <span class="text-[var(--fa-ink-3)]" title="Es gilt das Ziel des Betriebs">Ziel des Betriebs</span>
                                                    @endif
                                                </td>
                                                <td class="num">{{ $linie->default_pax ?: 'Standard' }}</td>
                                                <td class="pr-4">
                                                    <span class="flex items-center justify-end gap-0.5">
                                                        <x-fa::icon-button icon="heroicon-m-chevron-up" size="sm" label="{{ $linie->name }} nach oben" wire:click="linieVerschieben({{ $linie->id }}, -1)" />
                                                        <x-fa::icon-button icon="heroicon-m-chevron-down" size="sm" label="{{ $linie->name }} nach unten" wire:click="linieVerschieben({{ $linie->id }}, 1)" />
                                                        <x-fa::icon-button icon="heroicon-m-pencil-square" size="sm" label="{{ $linie->name }} bearbeiten" wire:click="linieEdit({{ $linie->id }})" />
                                                        <x-fa::icon-button icon="heroicon-m-trash" size="sm" tone="danger" label="{{ $linie->name }} entfernen" wire:click="linieRaus({{ $linie->id }})" wire:confirm="Linie entfernen? Einträge bleiben (ohne Linie)." />
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-fa::input wire:model="neueLinie" wire:keydown.enter="linieAdd" placeholder="Name der neuen Linie" class="w-64" aria-label="Name der neuen Linie" />
                                <x-fa::button icon="heroicon-m-plus" wire:click="linieAdd">Linie hinzufügen</x-fa::button>
                            </div>
                        </x-fa::section>

                        @if($editLinieId !== null)
                            <x-fa::section title="Linie bearbeiten" icon="heroicon-o-pencil-square" :meta="$linieForm['name'] ?? ''" data-sp-linie-form>
                                <x-slot:actions>
                                    <x-fa::button variant="ghost" size="sm" wire:click="$set('editLinieId', null)">Abbrechen</x-fa::button>
                                    <x-fa::button size="sm" icon="heroicon-m-check" wire:click="linieSpeichern">Linie speichern</x-fa::button>
                                </x-slot:actions>
                                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
                                    <x-fa::field label="Name" for="sp-linie-name" class="md:col-span-2"><x-fa::input id="sp-linie-name" wire:model="linieForm.name" /></x-fa::field>
                                    <x-fa::field label="Farbe" for="sp-linie-farbe"><input id="sp-linie-farbe" type="color" wire:model="linieForm.color" class="fa-control h-9 w-16 p-1" /></x-fa::field>
                                    <x-fa::field label="Rolle" for="sp-linie-rolle">
                                        <x-fa::select id="sp-linie-rolle" wire:model="linieForm.role" placeholder="Keine Rolle" :options="$rollen" />
                                    </x-fa::field>
                                    <x-fa::field label="Mahlzeit" for="sp-linie-mahlzeit">
                                        <x-fa::select id="sp-linie-mahlzeit" wire:model="linieForm.meal" placeholder="Alle Mahlzeiten" :options="$mahlzeiten" />
                                    </x-fa::field>
                                    <x-fa::field label="Kassen-Nr." for="sp-linie-plu"><x-fa::input id="sp-linie-plu" wire:model="linieForm.plu" maxlength="32" /></x-fa::field>
                                    <x-fa::field label="Preis" for="sp-linie-preis">
                                        <x-fa::select id="sp-linie-preis" wire:model.live="linieForm.price_mode" :options="['auto' => 'Vom Gericht', 'manuell' => 'Fester Linienpreis']" />
                                    </x-fa::field>
                                    @if(($linieForm['price_mode'] ?? 'auto') === 'manuell')
                                        <x-fa::field label="Linienpreis netto (€)" for="sp-linie-preiswert"><x-fa::input id="sp-linie-preiswert" inputmode="decimal" wire:model="linieForm.price_value" numeric placeholder="z. B. 6,40" /></x-fa::field>
                                    @endif
                                    <x-fa::field label="Wareneinsatz von (%)" for="sp-linie-wesmin"><x-fa::input id="sp-linie-wesmin" inputmode="decimal" wire:model="linieForm.target_wes_min_pct" numeric placeholder="leer = keine Grenze" /></x-fa::field>
                                    <x-fa::field label="Wareneinsatz bis (%)" for="sp-linie-wesmax"><x-fa::input id="sp-linie-wesmax" inputmode="decimal" wire:model="linieForm.target_wes_max_pct" numeric placeholder="leer = Ziel des Betriebs" /></x-fa::field>
                                    <x-fa::field label="Essen je Tag" for="sp-linie-pax"><x-fa::input id="sp-linie-pax" type="number" min="0" wire:model="linieForm.default_pax" numeric placeholder="Standard des Plans" /></x-fa::field>
                                    <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="linieForm.is_vegetarian" class="{{ $haken }}" /> Nur vegetarisch</label>
                                    <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="Dauerangebote (Salatbar …) zählen nicht für die Wiederholungsregel"><input type="checkbox" wire:model="linieForm.is_standing" class="{{ $haken }}" /> Dauerangebot</label>
                                </div>
                            </x-fa::section>
                        @endif
                    </div>

                    {{-- ═══ Reiter: STAMMDATEN ═══ --}}
                    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-stammdaten>
                        <x-fa::section title="Plan" icon="heroicon-o-identification">
                            <x-slot:actions>
                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="speichern" data-sp-stammdaten-speichern>Stammdaten speichern</x-fa::button>
                            </x-slot:actions>
                            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                                <x-fa::field label="Name" for="sp-form-name" class="md:col-span-2"><x-fa::input id="sp-form-name" wire:model="form.name" /></x-fa::field>
                                <x-fa::field label="Start (Montag)" for="sp-form-start"><x-fa::input id="sp-form-start" type="date" wire:model.live="form.start_date" wire:change="speichern" /></x-fa::field>
                                <x-fa::field label="Zyklus (Wochen)" for="sp-form-zyklus"><x-fa::input id="sp-form-zyklus" type="number" min="1" wire:model.live="form.cycle_weeks" wire:change="speichern" numeric /></x-fa::field>
                                <x-fa::field label="Mindestabstand (Tage)" for="sp-form-abstand" hint="0 = keine Wiederholungsregel"><x-fa::input id="sp-form-abstand" type="number" min="0" wire:model.live="form.min_abstand_tage" wire:change="speichern" numeric /></x-fa::field>
                            </div>

                            {{-- Spec 57 · Paket 9: Öffnungstage — steuern Kalender, Aushang, Produktion und KI-Lauf. --}}
                            <div class="flex flex-col gap-1.5" data-sp-oeffnungstage>
                                <span class="{{ $etikett }}">Öffnungstage</span>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Öffnungstage">
                                    @foreach($tagKurz as $iso => $kurz)
                                        @php $offen = in_array($iso, array_map('intval', (array) ($form['opening_days'] ?? [])), true); @endphp
                                        <button type="button" wire:click="oeffnungstagUmschalten({{ $iso }})" aria-pressed="{{ $offen ? 'true' : 'false' }}"
                                                class="{{ $chip }} min-w-11 justify-center {{ $offen ? $chipAn : $chipAus }}">{{ $kurz }}</button>
                                    @endforeach
                                </div>
                                <p class="{{ $leise }}">Steuern die Spalten im Kalender, den Aushang, die Produktion und den KI-Lauf.</p>
                            </div>

                            {{-- Spec 33 P5: Status und Zuordnung aus dem geteilten Bauteil. Ein Gültigkeitsfenster
                                 hat der Plan bewusst nicht: es steht in seinen Einträgen. --}}
                            <div class="pt-3 border-t border-[var(--fa-line)]">
                                <x-foodalchemist::ausgabe-status
                                    status-model="form.status"
                                    outlet-model="form.outlet_id"
                                    :betriebe="$betriebe" :zustand="$plan->laufZustand()" :grund="$plan->laufGrund()"
                                    :fenster-hinweis="$fensterHinweis" :konflikt="$portfolioKonflikt"
                                    toggle="aktivUmschalten" />
                            </div>
                            <x-foodalchemist::crm-kunde-picker
                                :ausgabe="$plan" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                        </x-fa::section>

                        <x-fa::section title="Teilnehmer und Budget" icon="heroicon-o-banknotes" description="Standard-Kopfzahl für die Übergabe an die Produktion (je Zelle überschreibbar) und das Wareneinsatz-Ziel je Person für die Budget-Anzeige.">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <x-fa::field label="Teilnehmer (Standard)" for="sp-form-pax"><x-fa::input id="sp-form-pax" type="number" min="1" wire:model.live="form.default_pax" wire:change="speichern" numeric data-sp-default-pax /></x-fa::field>
                                <x-fa::field label="Budget EK je Person (€)" for="sp-form-budget"><x-fa::input id="sp-form-budget" inputmode="decimal" wire:model.live="form.budget_wareneinsatz" wire:change="speichern" placeholder="z. B. 1,80" numeric title="Wareneinsatz-Ziel je Person und Mahlzeit" /></x-fa::field>
                            </div>
                        </x-fa::section>

                        {{-- Spec 59: Vorgaben je Woche (mind./höchstens je Prüf-Chip) --}}
                        @include('foodalchemist::livewire.speiseplan.partials.vorgaben')

                        {{-- Spec 57 · Paket 7: Vorlage für Betriebe (verknüpfte Kopie je Betrieb, im eigenen Team) --}}
                        <x-fa::section title="Vorlage für Betriebe" icon="heroicon-o-building-storefront">
                            @if($vorlageHinweis)<x-fa::notice tone="info" data-sp-vorlage-hinweis>{{ $vorlageHinweis }}</x-fa::notice>@endif
                            @if($sp->source_plan_id !== null)
                                {{-- Dieser Plan ist die Kopie eines Betriebs --}}
                                <div class="flex flex-col gap-3" data-sp-kopie-abgleich>
                                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                                        Kopie der Vorlage „{{ $vorlagenAbgleich['vorlage']['name'] ?? 'unbekannt' }}", zuletzt abgeglichen {{ $sp->source_synced_at?->format('d.m.Y H:i') ?? 'noch nie' }}.
                                        Preise, Mengen und Öffnungstage pflegt der Betrieb selbst.
                                    </p>
                                    @if(($vorlagenAbgleich['vorlage'] ?? null) === null)
                                        <x-fa::notice tone="warn">Die Vorlage gibt es nicht mehr. Dieser Plan ist jetzt frei.</x-fa::notice>
                                    @elseif($vorlagenAbgleich['zellen'] === [] && $vorlagenAbgleich['neue_linien'] === [])
                                        <x-fa::signal tone="ok">Ab heute stimmt der Plan mit der Vorlage überein.</x-fa::signal>
                                    @else
                                        @if($vorlagenAbgleich['neue_linien'] !== [])
                                            <x-fa::signal tone="info">Neue Linien in der Vorlage: {{ collect($vorlagenAbgleich['neue_linien'])->pluck('name')->implode(', ') }}</x-fa::signal>
                                        @endif
                                        <div class="overflow-x-auto -mx-4">
                                            <table class="fa-table fa-table--compact">
                                                <thead><tr><th class="pl-4">Tag</th><th>Linie</th><th>Vorlage</th><th>Betrieb</th><th class="pr-4"><span class="sr-only">Aktion</span></th></tr></thead>
                                                <tbody>
                                                    @foreach(array_slice($vorlagenAbgleich['zellen'], 0, 40) as $vz)
                                                        <tr wire:key="vz-{{ $vz['key'] }}">
                                                            <td class="pl-4 tabular-nums whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($vz['datum'])->format('d.m.') }} · {{ $mahlzeiten[$vz['mahlzeit']] ?? $vz['mahlzeit'] }}</td>
                                                            <td>{{ $vz['linie'] }}</td>
                                                            <td>{{ implode(', ', $vz['vorlage']) ?: 'leer' }}</td>
                                                            <td class="text-[var(--fa-ink-2)]">{{ implode(', ', $vz['betrieb']) ?: 'leer' }}</td>
                                                            <td class="pr-4">
                                                                <span class="flex items-center justify-end gap-2">
                                                                    <x-fa::badge :tone="$vz['art'] === 'vorlage_geaendert' ? 'warn' : 'neutral'">{{ $vz['art'] === 'vorlage_geaendert' ? 'Aus der Vorlage' : 'Lokal geändert' }}</x-fa::badge>
                                                                    <x-fa::button variant="ghost" size="sm" wire:click="ausVorlageUebernehmen('{{ $vz['key'] }}')">Übernehmen</x-fa::button>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div>
                                            <x-fa::button icon="heroicon-m-arrow-down-tray" wire:click="ausVorlageUebernehmen(null)" wire:confirm="Alle Änderungen der Vorlage übernehmen? Lokale Abweichungen bleiben." data-sp-vorlage-uebernehmen>Änderungen aus der Vorlage übernehmen</x-fa::button>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" data-sp-vorlage-schalter>
                                    <input type="checkbox" @checked($sp->is_template) wire:click="vorlageUmschalten" class="{{ $haken }} mt-0.5" />
                                    <span>Als Vorlage freigeben. Betriebe bekommen eine verknüpfte Kopie und übernehmen Änderungen per Abgleich.</span>
                                </label>
                                @if($sp->is_template)
                                    <div class="flex flex-col gap-2" data-sp-betriebskopien>
                                        @forelse($betriebsKopien as $bk)
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2" wire:key="bk-{{ $bk['id'] }}">
                                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $bk['outlet'] ?? $bk['name'] }} <span class="text-[var(--fa-ink-3)]">· {{ $bk['status'] }}</span></span>
                                                <span class="flex items-center gap-2">
                                                    @if($bk['aus_vorlage'] > 0)<x-fa::badge tone="warn">{{ $mehrzahl((int) $bk['aus_vorlage'], 'Änderung offen', 'Änderungen offen') }}</x-fa::badge>@else<x-fa::badge tone="ok">Aktuell</x-fa::badge>@endif
                                                    @if($bk['lokal'] > 0)<x-fa::badge>{{ $mehrzahl((int) $bk['lokal'], 'lokale Änderung', 'lokale Änderungen') }}</x-fa::badge>@endif
                                                    <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-top-right-on-square" wire:click="$dispatch('speiseplan-editor.bearbeiten', { id: {{ $bk['id'] }} })">Kopie öffnen</x-fa::button>
                                                </span>
                                            </div>
                                        @empty
                                            <p class="{{ $leise }}">Noch keine Kopie für einen Betrieb.</p>
                                        @endforelse
                                        @if($betriebe->isNotEmpty())
                                            <div class="flex flex-wrap items-center gap-2">
                                                <x-fa::select wire:model="kopieOutletId" class="w-64" aria-label="Betrieb für die Kopie" placeholder="Betrieb wählen" :options="$betriebe->pluck('name', 'id')" />
                                                <x-fa::button icon="heroicon-m-plus" wire:click="betriebsKopieAnlegen" data-sp-kopie-anlegen>Kopie für Betrieb anlegen</x-fa::button>
                                            </div>
                                        @else
                                            <x-fa::signal tone="warn">Noch keine Betriebe angelegt. Das geht unter Einstellungen › Betriebe.</x-fa::signal>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </x-fa::section>

                        <x-fa::section title="Zyklus ausrollen" icon="heroicon-o-arrow-path" description="Den {{ $sp->cycle_weeks }}-Wochen-Block ab Start auf alle Folgewochen bis zum Zieldatum kopieren. Belegte Zellen bleiben unberührt, außer du wählst „Belegte Zellen ersetzen“. Die Essen-Zahlen wandern mit.">
                            <div class="flex flex-wrap items-end gap-3">
                                <x-fa::field label="Ausrollen bis" for="sp-ausrollen-bis" class="w-48"><x-fa::input id="sp-ausrollen-bis" type="date" wire:model="ausrollenBis" title="Zyklus bis zu diesem Datum ausrollen" /></x-fa::field>
                                <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model.live="ausrollenErsetzen" class="{{ $haken }}" /> Belegte Zellen ersetzen</label>
                                <x-fa::button icon="heroicon-m-arrow-path" wire:click="ausrollen" :wire:confirm="$ausrollenErsetzen ? 'Belegte Zellen in den Folgewochen werden durch die Vorlage ersetzt. Fortfahren?' : null" data-sp-ausrollen>Zyklus ausrollen</x-fa::button>
                                @if($ausrollenInfo)<x-fa::signal tone="info" class="self-center">{{ $ausrollenInfo }}</x-fa::signal>@endif
                            </div>
                        </x-fa::section>
                    </div>

                    {{-- ═══ Spec 43: Reiter DRUCK UND AUSHANG (Ausgabe, Erscheinungsbild, digitaler Aushang) ═══ --}}
                    <div x-show="tab === 'praesentation'" x-cloak class="pt-4 flex flex-col gap-4" data-sp-tab-praesentation>
                        @if($brandingFehler)<x-fa::notice tone="crit">{{ $brandingFehler }}</x-fa::notice>@endif

                        {{-- Spec 57 · Paket 6: Druck und Export der sichtbaren Woche/Mahlzeit --}}
                        <x-fa::section title="Drucken und exportieren" icon="heroicon-o-printer" :meta="$kwText" description="Kennzeichnung (Allergene, Zusatzstoffe, Kostform) kommt immer aus den Rezepten. Logo und Farben der Gäste-Drucke kommen aus Erscheinungsbild und Präsentations-Design. Jede Vorlage öffnet im neuen Tab und lässt sich dort als PDF speichern.">
                            <div class="flex flex-wrap items-end gap-3">
                                <x-fa::field label="Tag (Aufsteller, Schilder, Tagesliste)" for="sp-ausgabe-tag" class="w-64">
                                    <x-fa::select id="sp-ausgabe-tag" wire:model.live="ausgabeTag">
                                        @foreach($wochenTage as $wt)
                                            <option value="{{ $wt->format('Y-m-d') }}" @selected($wt->format('Y-m-d') === $ausgabeTagEffektiv)>{{ $tagKurz[$wt->isoWeekday()] }} {{ $wt->format('d.m.') }}</option>
                                        @endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Linie (Linien- und Buffetschild)" for="sp-ausgabe-linie" class="w-64">
                                    <x-fa::select id="sp-ausgabe-linie" wire:model.live="ausgabeLinie" placeholder="Alle Linien" :options="$matrixLinien->pluck('name', 'id')" />
                                </x-fa::field>
                                <label class="flex items-center gap-2 h-9 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model.live="ausgabePreise" class="{{ $haken }}" /> Preise zeigen</label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2" data-sp-ausgabe-formate>
                                @foreach([
                                    ['woche', 'Wochenaushang A4', 'Linien × Tage, Kennzeichnung und Legende', 'heroicon-o-calendar-days'],
                                    ['tag', 'Tischaufsteller', 'Zeltkarte A4 quer, ein Tag, alle Linien', 'heroicon-o-rectangle-stack'],
                                    ['schild', 'Linienschilder', 'Je Linie ein Schild (A5 quer)', 'heroicon-o-tag'],
                                    ['buffet', 'Buffetschilder', 'Je Gericht und Komponente ein Zeltkärtchen, 6 pro A4', 'heroicon-o-squares-2x2'],
                                    ['liste_woche', 'Allergen- und Komponentenliste der Woche', 'Für den Ordner an der Ausgabe', 'heroicon-o-clipboard-document-list'],
                                    ['liste_tag', 'Allergen- und Komponentenliste des Tages', 'Nur der gewählte Tag', 'heroicon-o-clipboard-document'],
                                    ['csv', 'Tabelle für Excel', 'Woche als CSV-Datei (Semikolon getrennt)', 'heroicon-o-table-cells'],
                                ] as [$fk, $fl, $fs, $fi])
                                    <a href="{{ $ausgabeLinks[$fk] ?? '#' }}" target="_blank"
                                       class="flex items-start gap-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] hover:bg-[var(--fa-hover)] hover:border-[var(--fa-accent-line)] px-3 py-2.5 transition-colors" data-sp-format="{{ $fk }}">
                                        @svg($fi, 'w-5 h-5 shrink-0 mt-0.5 text-[var(--fa-ink-3)]')
                                        <span class="min-w-0">
                                            <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $fl }}</span>
                                            <span class="block {{ $leise }}">{{ $fs }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </x-fa::section>

                        <x-fa::section title="Erscheinungsbild" icon="heroicon-o-swatch">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <x-fa::field label="Markenfarbe" for="sp-brand-farbe"><input id="sp-brand-farbe" type="color" wire:model="brandColor" class="fa-control h-9 p-1"></x-fa::field>
                                <x-fa::field label="Bandfarbe" for="sp-band-farbe" optional><input id="sp-band-farbe" type="color" wire:model="bandColor" class="fa-control h-9 p-1"></x-fa::field>
                                <x-fa::field label="Text in der Fußzeile" for="sp-footer"><x-fa::input id="sp-footer" wire:model="footerText" placeholder="z. B. Küche XY" /></x-fa::field>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-fa::field label="Logo">
                                    <div class="flex items-center gap-3">
                                        @if($brandingBilder['logo'])
                                            <img src="{{ $brandingBilder['logo'] }}" alt="Logo" class="h-9 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)]">
                                            <x-fa::button variant="ghost" size="sm" wire:click="brandingLogoEntfernen">Logo entfernen</x-fa::button>
                                        @endif
                                        <input type="file" wire:model="logoUpload" accept="image/*" aria-label="Logo hochladen" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                    </div>
                                </x-fa::field>
                                <x-fa::field label="Titelbild">
                                    <div class="flex items-center gap-3">
                                        @if($brandingBilder['cover'])
                                            <img src="{{ $brandingBilder['cover'] }}" alt="Titelbild" class="h-9 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)]">
                                            <x-fa::button variant="ghost" size="sm" wire:click="brandingCoverEntfernen">Titelbild entfernen</x-fa::button>
                                        @endif
                                        <input type="file" wire:model="coverUpload" accept="image/*" aria-label="Titelbild hochladen" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                    </div>
                                </x-fa::field>
                            </div>
                            <div>
                                <x-fa::button icon="heroicon-m-check" wire:click="brandingSpeichern" data-sp-branding-speichern>Erscheinungsbild speichern</x-fa::button>
                            </div>
                        </x-fa::section>

                        <x-fa::section title="Digitaler Aushang" icon="heroicon-o-tv">
                            @if($presentationHinweis)<x-fa::notice tone="ok" data-sp-praes-hinweis>{{ $presentationHinweis }}</x-fa::notice>@endif
                            @if($presentationFehler)<x-fa::notice tone="crit" data-sp-praes-fehler>{{ $presentationFehler }}</x-fa::notice>@endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-fa::field label="Design" for="sp-praes-design">
                                    <x-fa::select id="sp-praes-design" wire:model="presentationDesign" data-sp-praes-design>
                                        @foreach($presentationDesignOptionen as $opt)
                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Gültig bis" for="sp-praes-gueltig" required>
                                    <x-fa::input id="sp-praes-gueltig" type="date" wire:model="presentationGueltigBis" data-sp-praes-gueltig />
                                </x-fa::field>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" data-sp-praes-laufend>
                                    <input type="checkbox" wire:model="presentationLaufendeWoche" class="{{ $haken }} mt-0.5">
                                    <span>Immer die laufende Woche zeigen (jeden Montag neu eingefroren). Sonst {{ $kwText }}.</span>
                                </label>
                                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]"><input type="checkbox" wire:model="presentationPreisAnzeige" class="{{ $haken }}" data-sp-praes-preis> Preise anzeigen</label>
                                {{-- Ebene 2 · Republish-Preis-Schutz (nur relevant mit Preisen) --}}
                                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen. An: aktuelle Verkaufspreise übernehmen. Nur mit Preisen relevant; die erste Veröffentlichung ist immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren" class="{{ $haken }}"> Preise beim erneuten Veröffentlichen aktualisieren</label>
                                <p class="{{ $leise }}">Der Aushang in der Gemeinschaftsverpflegung ist ohne Preise üblich; die Kennzeichnung der Allergene und Zusatzstoffe ist immer sichtbar. Preise z. B. für Café- oder Bistro-Pläne, sie folgen dem aktiven Betrieb.</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-fa::field label="Text auf dem Knopf" for="sp-praes-cta" optional><x-fa::input id="sp-praes-cta" wire:model="presentationCtaText" placeholder="z. B. Mehr Infos" /></x-fa::field>
                                <x-fa::field label="Ziel des Knopfs (Link)" for="sp-praes-ctalink" optional><x-fa::input id="sp-praes-ctalink" type="url" wire:model="presentationCtaLink" placeholder="https://…" /></x-fa::field>
                            </div>

                            @if($presentationInfo['design_veraltet'] ?? false)
                                {{-- Bug-Runde 2026-09-17 #2: Aushang rendert nur den eingefrorenen Stand. --}}
                                <x-fa::notice tone="warn" title="Design wurde nach der Veröffentlichung geändert" data-fa-design-veraltet>
                                    Der Aushang zeigt weiter den Stand von {{ $presentationInfo['published_at'] ?? 'unbekannt' }}. Zum Übernehmen unten „Neu veröffentlichen".
                                </x-fa::notice>
                            @endif

                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::button variant="ghost" icon="heroicon-m-eye" :href="route('foodalchemist.speiseplan.praesentation', ['id' => $sp->id, 'design' => $presentationDesign])" target="_blank">Vorschau öffnen</x-fa::button>
                                <x-fa::button icon="heroicon-m-signal" wire:click="veroeffentlichen" wire:confirm="Diesen Aushang veröffentlichen? Der aktuelle Stand wird eingefroren." data-sp-praes-publish :disabled="! $presentationGueltigBis">
                                    {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Aushang veröffentlichen' }}
                                </x-fa::button>
                                @if($presentationInfo['enabled'] ?? false)
                                    <x-fa::button variant="danger" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link funktioniert dann nicht mehr." data-sp-praes-withdraw>Veröffentlichung zurückziehen</x-fa::button>
                                @endif
                            </div>
                            @unless($presentationGueltigBis)
                                <x-fa::signal tone="warn">Zum Veröffentlichen ein Datum bei „Gültig bis" setzen.</x-fa::signal>
                            @endunless

                            @if($presentationLink)
                                <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3" x-data>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 min-w-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-2 py-1.5 font-mono text-[length:var(--fa-text-sm)] break-all select-all text-[var(--fa-ink)]" data-sp-praes-link>{{ $presentationLink }}</div>
                                        <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.lastChild.textContent = ' Kopiert'">Link kopieren</x-fa::button>
                                    </div>
                                    <p class="{{ $leise }}">Freigegeben am {{ $presentationInfo['published_at'] ?? 'unbekannt' }} · gültig bis {{ $presentationInfo['expires_at'] ?? 'offen' }} · {{ ($presentationInfo['live'] ?? false) ? 'aktiv' : 'inaktiv oder abgelaufen' }}</p>
                                </div>
                            @endif
                        </x-fa::section>

                        {{-- Slice F: Betriebs-Links — pro Betrieb ein eigener Aushang-Link (eigene Vorlage + Name) --}}
                        <x-fa::section title="Aushang je Betrieb" icon="heroicon-o-building-storefront" :meta="$mehrzahl(count($betriebsLinks), 'Link', 'Links')" description="Ein zusätzlicher Aushang-Link pro Betrieb, mit der Vorlage und dem Namen dieses Betriebs und eigener Freigabe. Der Standard-Link oben bleibt bestehen.">
                            @forelse($betriebsLinks as $bl)
                                <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3" x-data>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $bl['outlet_name'] }}</span>
                                        <x-fa::badge :tone="$bl['enabled'] ? 'ok' : 'neutral'">{{ $bl['enabled'] ? 'Aktiv' : 'Inaktiv' }}</x-fa::badge>
                                        <span class="ml-auto {{ $leise }}">Vorlage: {{ $bl['design'] }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="flex-1 min-w-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-2 py-1.5 font-mono text-[length:var(--fa-text-sm)] break-all select-all text-[var(--fa-ink)]">{{ $bl['url'] }}</div>
                                        <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.lastChild.textContent = ' Kopiert'">Link kopieren</x-fa::button>
                                        @if($bl['enabled'])
                                            <x-fa::button variant="danger" size="sm" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er funktioniert dann nicht mehr.">Link zurückziehen</x-fa::button>
                                        @else
                                            <x-fa::button size="sm" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})">Link wieder freigeben</x-fa::button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="{{ $leise }}">Noch kein Betriebs-Link angelegt.</p>
                            @endforelse

                            @if(count($betriebsOptionen) > 0)
                                <div class="flex flex-col gap-3 rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line-strong)] p-3">
                                    <span class="{{ $etikett }}">Weiteren Betrieb hinzufügen</span>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 items-end">
                                        <x-fa::field label="Betrieb" for="sp-outlet-publish">
                                            <x-fa::select id="sp-outlet-publish" wire:model="outletPublishId" placeholder="Betrieb wählen" :options="collect($betriebsOptionen)->pluck('name', 'id')" />
                                        </x-fa::field>
                                        <x-fa::field label="Gültig bis" for="sp-outlet-gueltig" optional><x-fa::input id="sp-outlet-gueltig" type="date" wire:model="outletPublishGueltigBis" /></x-fa::field>
                                        <x-fa::field label="Vorlage" for="sp-outlet-design" optional>
                                            <x-fa::select id="sp-outlet-design" wire:model="outletPublishDesign" placeholder="Wie Betrieb oder Dokument">
                                                @foreach($presentationDesignOptionen as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Name im Link" for="sp-outlet-slug" optional><x-fa::input id="sp-outlet-slug" wire:model="outletPublishSlug" placeholder="z. B. broich-nord-2027" /></x-fa::field>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="{{ $leise }}">Beliebig viele Betriebe möglich, je Betrieb ein eigener Link. Ohne eigenes Datum gilt „Gültig bis" des Standard-Links.</p>
                                        <x-fa::button icon="heroicon-m-plus" wire:click="betriebVeroeffentlichen">Betrieb hinzufügen</x-fa::button>
                                    </div>
                                </div>
                            @else
                                <x-fa::signal tone="warn">Noch keine Betriebe angelegt. Das geht unter Einstellungen › Betriebe.</x-fa::signal>
                            @endif
                        </x-fa::section>
                    </div>
                </x-foodalchemist::editor-tabs>
            </div>

            {{-- ═══ Kontext-Spalte rechts ═══
                 Oben das Eintrag-Detail (der Einfüge-Bereich steht unter der Matrix). Darunter die Live-Kennzahlen, die bei jeder Änderung
                 mitrechnen (aus jedem Reiter sichtbar, einklappbar). --}}
            <aside class="shrink-0 pr-6 pt-4 sticky top-0 self-start max-h-[85vh] overflow-y-auto flex flex-col gap-3" x-bind:class="rail ? 'w-80' : ''">

                {{-- Spec 57 · Paket 5: Eintrag-Detail — Tastatur-Weg zu Ersetzen, Verschieben, Kopieren (MVP-032) --}}
                @if($detailEintrag)
                    @php $dk = $detailKennzahlen ?? []; @endphp
                    <section class="fa-surface w-80 max-w-full p-3 flex flex-col gap-3 border-[var(--fa-accent-line)]" data-sp-eintrag-detail="{{ $detailEintrag->id }}">
                        <header class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="{{ $etikett }}">{{ $linien->firstWhere('id', $detailEintrag->line_id)?->name ?? 'Ohne Linie' }} · {{ $tagKurz[$detailEintrag->entry_date->isoWeekday()] ?? '' }} {{ $detailEintrag->entry_date->format('d.m.') }} · {{ $mahlzeiten[$detailEintrag->meal] ?? $detailEintrag->meal }}</p>
                                <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)] break-words">{{ $dk['titel'] ?? $detailEintrag->inhaltName() }}</p>
                                @if(! empty($dk['untertitel']))<p class="{{ $leise }}">{{ $dk['untertitel'] }}</p>@endif
                                @php
                                    $inhaltLink = $detailEintrag->sales_recipe_id !== null
                                        ? ['Gericht öffnen', route('foodalchemist.verkauf.index', ['rezept' => $detailEintrag->sales_recipe_id])]
                                        : ($detailEintrag->concept_id !== null
                                            ? ['Konzept öffnen', route('foodalchemist.concepter.index', ['tab' => 'concepts', 'sel' => $detailEintrag->concept_id])]
                                            : null);
                                @endphp
                                @if($inhaltLink)
                                    <a href="{{ $inhaltLink[1] }}" target="_blank" class="mt-1 inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:underline" data-sp-inhalt-link>
                                        {{ $inhaltLink[0] }} @svg('heroicon-m-arrow-top-right-on-square', 'w-3.5 h-3.5')
                                    </a>
                                @endif
                            </div>
                            <x-fa::icon-button icon="heroicon-m-x-mark" size="sm" label="Detail schließen" wire:click="eintragSchliessen" />
                        </header>
                        @if($dk !== [])
                            <dl class="grid grid-cols-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-x divide-[var(--fa-line)] text-center">
                                <div class="px-2 py-1.5"><dt class="{{ $leise }}">Verkauf netto</dt><dd class="text-[length:var(--fa-text-md)] font-semibold"><x-fa::money :value="((float) ($dk['vk'] ?? 0)) > 0 ? $dk['vk'] : null" /></dd></div>
                                <div class="px-2 py-1.5"><dt class="{{ $leise }}">EK</dt><dd class="text-[length:var(--fa-text-md)] font-semibold"><x-fa::money :value="$dk['ek'] ?? null" /></dd></div>
                                <div class="px-2 py-1.5"><dt class="{{ $leise }}">Wareneinsatz</dt><dd class="text-[length:var(--fa-text-md)] font-semibold tabular-nums {{ $wesText[$dk['status'] ?? 'unbekannt'] ?? '' }}">{{ ($dk['wes'] ?? null) !== null ? $zahl($dk['wes'], 1) . ' %' : '–' }}</dd></div>
                            </dl>
                        @endif

                        <x-fa::button icon="heroicon-m-arrows-right-left" class="w-full" wire:click="eintragErsetzenStarten({{ $detailEintrag->id }})">Eintrag ersetzen</x-fa::button>

                        <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]" data-sp-verschieben>
                            <span class="{{ $etikett }}">Verschieben</span>
                            <div class="grid grid-cols-2 gap-2">
                                <x-fa::select wire:model="verschiebeDatum" size="sm" aria-label="Verschieben auf Tag">
                                    @foreach($wochenTage as $wt)
                                        <option value="{{ $wt->format('Y-m-d') }}">{{ $tagKurz[$wt->isoWeekday()] }} {{ $wt->format('d.m.') }}</option>
                                    @endforeach
                                </x-fa::select>
                                <x-fa::select wire:model="verschiebeLinie" size="sm" aria-label="Verschieben in Linie" placeholder="Ohne Linie" :options="$matrixLinien->pluck('name', 'id')" />
                            </div>
                            <x-fa::button size="sm" icon="heroicon-m-arrow-right" wire:click="eintragVerschiebenAusDetail">Eintrag verschieben</x-fa::button>
                        </div>

                        <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]" data-sp-kopieren>
                            <fieldset class="flex flex-col gap-1.5">
                                <legend class="{{ $etikett }} mb-1.5">Auf weitere Tage kopieren</legend>
                                <span class="flex flex-wrap gap-1.5">
                                    @foreach($wochenTage as $wt)
                                        @php $wtYmd = $wt->format('Y-m-d'); @endphp
                                        @php $gleicherTag = $wtYmd === $detailEintrag->entry_date->format('Y-m-d'); @endphp
                                        <label class="fa-chip {{ $gleicherTag ? 'opacity-40 pointer-events-none' : '' }}">
                                            <input type="checkbox" wire:model="kopierTage" value="{{ $wtYmd }}" class="sr-only peer" @disabled($gleicherTag) />
                                            <span>{{ $tagKurz[$wt->isoWeekday()] }}</span>
                                        </label>
                                    @endforeach
                                </span>
                            </fieldset>
                            <x-fa::button size="sm" icon="heroicon-m-document-duplicate" wire:click="eintragKopieren">Eintrag kopieren</x-fa::button>
                        </div>

                        <div class="pt-3 border-t border-[var(--fa-line)]">
                            <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" class="w-full" wire:click="eintragRaus({{ $detailEintrag->id }})" wire:confirm="Eintrag entfernen?">Eintrag entfernen</x-fa::button>
                        </div>
                    </section>
                @endif

                {{-- Kennzahlen-Kopf mit Ein-/Ausklappen --}}
                <div class="flex items-center justify-between gap-2" x-show="rail" x-cloak>
                    <h3 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Kennzahlen der Woche</h3>
                    <x-fa::icon-button icon="heroicon-m-chevron-double-right" size="sm" label="Kennzahlen ausblenden" x-on:click="rail = false" />
                </div>
                <div x-show="! rail" x-cloak>
                    <x-fa::icon-button icon="heroicon-o-chart-bar" label="Kennzahlen einblenden" x-on:click="rail = true" />
                </div>

                <div class="flex flex-col gap-3" x-show="rail" x-cloak data-sp-kennzahlen>
                    @if($kosten)
                        <div class="{{ $karte }} text-center">
                            <p class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums text-[var(--fa-ink)] leading-tight">{{ $zahl($kosten['woche']['vk'], 2) }} €</p>
                            <p class="{{ $etikett }}">Verkaufspreis je Person · {{ $mahlzeiten[$mahlzeit] ?? '' }}</p>
                            <p class="{{ $leise }} tabular-nums">EK {{ $zahl($kosten['woche']['ek'], 2) }} € · Standard {{ $sp->default_pax }} Teilnehmer</p>
                        </div>
                    @endif

                    {{-- Wareneinsatz-Budget · Spec 57 · E2: Ø EK je GAST und Tag (Hauptgang-Linien) gegen Budget,
                         gerechnet im Service (budgetAmpel), nicht hier. --}}
                    @if($budget)
                        <div class="{{ $karte }}" data-sp-budget>
                            <div class="flex items-center justify-between gap-2">
                                <span class="{{ $etikett }}">Wareneinsatz-Budget</span>
                                <x-fa::badge :tone="$ampelTon[$budget['ampel']] ?? 'neutral'">{{ $budget['ampel'] === 'success' ? 'Im Ziel' : ($budget['ampel'] === 'warning' ? $mehrzahl((int) $budget['ueber_tage'], 'Tag darüber', 'Tage darüber') : 'Über dem Ziel') }}</x-fa::badge>
                            </div>
                            <p class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-2)]">Ø EK {{ $zahl($budget['avg'], 2) }} € bei Budget {{ $zahl($budget['budget'], 2) }} € {{ $budget['basis'] === 'je_gast' ? 'je Gast und Tag' : 'je Person und Tag (Summe aller Linien)' }}</p>
                            @if($budget['basis'] !== 'je_gast')
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">Für „je Gast" im Reiter „Menü-Linien" die Hauptgang-Linien mit der Rolle „Hauptgang" markieren.</p>
                            @endif
                        </div>
                    @endif

                    {{-- Spec 57 · Paket 2: Wareneinsatz je Linie gegen ihr Zielband (Woche). --}}
                    @if(! empty($zk['linien']))
                        <div class="{{ $karte }}" data-sp-linien-ampel>
                            <span class="{{ $etikett }}">Wareneinsatz je Linie</span>
                            @foreach($zk['linien'] as $lid => $la)
                                <div class="flex flex-col gap-1" wire:key="la-{{ $lid }}">
                                    <div class="flex justify-between gap-2 text-[length:var(--fa-text-sm)]"><span class="truncate text-[var(--fa-ink)]">{{ $la['name'] }}</span><span class="tabular-nums font-medium {{ $wesText[$la['status']] ?? 'text-[var(--fa-ink-3)]' }}">{{ $la['wes'] !== null ? $zahl($la['wes']) . ' %' : 'ohne Preis' }}</span></div>
                                    <div class="relative h-1.5 rounded-full bg-[var(--fa-neutral-soft)]" aria-hidden="true">
                                        <div class="absolute h-full rounded-full bg-[var(--fa-ok-soft)]" style="left: {{ min(100, (float) ($la['band']['min'] ?? 0)) }}%; width: {{ max(0, min(100, (float) $la['band']['max']) - min(100, (float) ($la['band']['min'] ?? 0))) }}%"></div>
                                        @if($la['wes'] !== null)
                                            <div class="absolute -top-0.5 w-1 h-2.5 rounded {{ $wesPunkt[$la['status']] ?? 'bg-[var(--fa-ink-3)]' }}" style="left: calc({{ min(98, max(0, (float) $la['wes'])) }}% - 2px)"></div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                            <p class="{{ $leise }}">Hell hinterlegt: Zielband (Skala 0 bis 100 %). Ohne eigenes Band gilt das Ziel des Betriebs von {{ number_format($zk['team_ziel'] ?? 0, 0) }} %.</p>
                        </div>
                    @endif

                    {{-- Kostformen-Abdeckung: ist jede Kostform an jedem Werktag vertreten? --}}
                    <div class="{{ $karte }}" data-sp-kostformen>
                        <span class="{{ $etikett }}">Kostformen</span>
                        @forelse($kostformen as $kf)
                            <div class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]">
                                <span class="truncate text-[var(--fa-ink)]">{{ $kf['label'] }}</span>
                                @if($kf['erfuellt'])
                                    <x-fa::badge tone="ok" icon="heroicon-m-check" title="An jedem Werktag vertreten">Täglich</x-fa::badge>
                                @elseif($kf['abgedeckt'] > 0)
                                    <x-fa::badge tone="warn" title="Fehlt am {{ implode(', ', array_map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m.'), $kf['fehltage'])) }}">{{ $kf['abgedeckt'] }} von {{ $kf['tage'] }} Tagen</x-fa::badge>
                                @else
                                    <x-fa::badge>Fehlt</x-fa::badge>
                                @endif
                            </div>
                        @empty
                            <p class="{{ $leise }}">Keine Kostformen hinterlegt.</p>
                        @endforelse
                    </div>

                    {{-- Kennzeichnung der Woche (deklarationspflichtig, Vorsorgeprinzip über alle Gerichte) --}}
                    @if($kennzeichnung)
                        @php
                            $algEnth = collect($kennzeichnung['woche']['allergene'])->whereIn('status', ['enthalten', 'spuren']);
                            $zusJa = collect($kennzeichnung['woche']['zusatzstoffe'])->where('status', 'ja');
                        @endphp
                        <div class="{{ $karte }}" data-sp-kennzeichnung>
                            <span class="{{ $etikett }}">Kennzeichnung der Woche</span>
                            @if($algEnth->isEmpty())
                                <p class="{{ $leise }}">Keine Allergene deklariert oder noch unbekannt.</p>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($algEnth as $a)
                                        <x-fa::badge :tone="$a['status'] === 'enthalten' ? 'crit' : 'warn'" title="{{ $a['status'] === 'spuren' ? 'Spuren' : 'Enthalten' }}">{{ $a['label'] }}{{ $a['status'] === 'spuren' ? ' (Spuren)' : '' }}</x-fa::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if($zusJa->isNotEmpty())
                                <div class="flex flex-wrap gap-1 pt-2 border-t border-[var(--fa-line)]">
                                    @foreach($zusJa as $z)
                                        <x-fa::badge title="Zusatzstoff">{{ $z['label'] }}</x-fa::badge>
                                    @endforeach
                                </div>
                            @endif
                            <p class="{{ $leise }}">Die vollständige Kennzeichnung je Tag steht im Aushang.</p>
                        </div>
                    @endif

                    {{-- Nährwert-Wochenbilanz (Ø je Person und Tag) --}}
                    @if($naehrwerte && $naehrwerte['tage_mit_daten'] > 0)
                        @php $n = $naehrwerte['schnitt']; @endphp
                        <div class="{{ $karte }}" data-sp-naehrwerte>
                            <span class="{{ $etikett }}">Nährwerte · Ø je Person und Tag</span>
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-[length:var(--fa-text-sm)]">
                                @foreach([
                                    ['kcal', 'kcal', 0, ''],
                                    ['Eiweiß', 'protein_g', 1, ' g'],
                                    ['Fett', 'fett_g', 1, ' g'],
                                    ['davon gesättigt', 'gesfett_g', 1, ' g'],
                                    ['Salz', 'salz_g', 2, ' g'],
                                    ['Zucker', 'zucker_g', 1, ' g'],
                                ] as [$nl, $nk, $ns, $ne])
                                    <div class="flex justify-between gap-2"><dt class="text-[var(--fa-ink-3)]">{{ $nl }}</dt><dd class="tabular-nums text-[var(--fa-ink)]">{{ $n[$nk] !== null ? $zahl($n[$nk], $ns) . $ne : '–' }}</dd></div>
                                @endforeach
                            </dl>
                            @if($naehrwerte['confidence'] !== 'high')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">Verlässlichkeit {{ $konfidenzText[$naehrwerte['confidence']] ?? $naehrwerte['confidence'] }}: nicht alle Gerichte haben Nährwerte und Portionsgewicht.</p>@endif
                        </div>
                    @endif

                    {{-- Abwechslung: Diät-Mix + Warengruppen der Woche + Vorgaben des Plans (Spec 59) --}}
                    @if($abwechslung)
                        @include('foodalchemist::livewire.speiseplan.partials.abwechslung', ['ab' => $abwechslung])
                    @endif

                    <div class="{{ $karte }}">
                        @if(!empty($wiederholungen))
                            <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)]">Zu frühe Wiederholungen ({{ count($wiederholungen) }})</span>
                            @foreach($wiederholungen as $w)
                                <div class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]">
                                    <span class="truncate text-[var(--fa-ink)]">{{ $w['name'] }}</span>
                                    <x-fa::badge tone="warn">{{ $w['vorkommen'] }}× · {{ $w['min_abstand'] }} Tage</x-fa::badge>
                                </div>
                            @endforeach
                        @else
                            <x-fa::signal tone="ok">Keine zu frühen Wiederholungen</x-fa::signal>
                        @endif
                    </div>
                </div>
            </aside>
        </div>
    @endif
</x-foodalchemist::modal>
