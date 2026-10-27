{{-- Lieferanten → Marken: alle Marken-Kürzel (supplier_marken) zentral. Lesen für alle, Ändern nur Plattform-/Master-Admin. --}}
@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
    $typLabel = ['herstellermarke' => 'Herstellermarke', 'eigenmarke' => 'Eigenmarke', 'sperre' => 'Kein Kürzel'];
    $typTon = ['herstellermarke' => 'neutral', 'eigenmarke' => 'info', 'sperre' => 'warn'];
    $fallLabel = ['gerendert' => 'neu benannt', 'teilen' => 'teilen', 'fall_b' => 'Fall B: Artikel ohne Marke', 'ohne_artikel' => 'ohne Artikel'];
    $fallTon = ['gerendert' => 'ok', 'teilen' => 'warn', 'fall_b' => 'neutral', 'ohne_artikel' => 'neutral'];
    $letzteSeite = max(1, (int) ceil($liste['gesamt'] / $liste['pro_seite']));
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Marken" icon="heroicon-o-tag" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lieferanten', 'href' => route('foodalchemist.suppliers.index')],
            ['label' => 'Marken'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div class="flex flex-col gap-4" data-marken-seite>
            <x-fa::page-header title="Marken" :subtitle="$fmt($liste['gesamt']) . ' Kürzel und Marken für die GP-Klammer'">
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-tag" :href="route('foodalchemist.suppliers.marke-offen')" wire:navigate>Marke offen</x-fa::button>
                    @if($darfPflegen)
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-plus" wire:click="$set('neuOffen', true)" data-marke-neu-oeffnen>Neue Marke</x-fa::button>
                    @endif
                </x-slot:actions>
            </x-fa::page-header>

            @if($fehler)<x-fa::notice tone="crit" data-marken-fehler>{{ $fehler }}</x-fa::notice>@endif
            @if($meldung)<x-fa::notice tone="ok" data-marken-meldung>{{ $meldung }}</x-fa::notice>@endif

            @if($nachrendern !== [])
                <x-fa::notice tone="info" data-nachrendern-angebot>
                    Passende „Marke offen“-GPs mit dem Kürzel „{{ $nachrendern['kuerzel'] }}“ jetzt neu benennen? Nur GPs, deren Artikel alle dieselbe Marke ergeben.
                    @if(isset($nachrendern['ergebnis']))
                        <ul class="mt-2 flex flex-col gap-1 text-[length:var(--fa-text-sm)]" data-nachrendern-vorschau>
                            @forelse($nachrendern['ergebnis']['gps'] as $gp)
                                <li class="flex items-center gap-2">
                                    <x-fa::badge :tone="$fallTon[$gp['fall']]">{{ $fallLabel[$gp['fall']] }}</x-fa::badge>
                                    <span>{{ $gp['name_alt'] }}</span>
                                    @if($gp['name_neu'])<span class="text-[var(--fa-ink-3)]">→</span><span class="font-medium">{{ $gp['name_neu'] }}</span>@endif
                                </li>
                            @empty
                                <li class="text-[var(--fa-ink-3)]">Keine offenen GPs mit diesem Kürzel.</li>
                            @endforelse
                        </ul>
                    @endif
                    <x-slot:actions>
                        @if(! isset($nachrendern['ergebnis']))
                            <x-fa::button size="sm" variant="ghost" wire:click="nachrendernVorschau" data-nachrendern-vorschau-knopf>Vorschau</x-fa::button>
                        @endif
                        <x-fa::button size="sm" variant="primary" wire:click="nachrendernAnwenden" data-nachrendern-anwenden>Neu benennen</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="nachrendernVerwerfen">Später</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif

            @if($neuOffen && $darfPflegen)
                <x-fa::section title="Neue Marke" data-marke-neu-form>
                    <div class="flex flex-wrap items-end gap-3">
                        <x-fa::field label="Gilt für" for="mk-neu-lieferant">
                            <x-fa::select id="mk-neu-lieferant" size="sm" wire:model="neuForm.supplier_id">
                                <option value="">alle Lieferanten</option>
                                @foreach($lieferanten as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Kürzel (ohne Punkt)" for="mk-neu-kuerzel">
                            <x-fa::input id="mk-neu-kuerzel" size="sm" wire:model="neuForm.kuerzel" data-mk-neu-kuerzel />
                        </x-fa::field>
                        <x-fa::field label="Marke (Schreibweise in der Klammer)" for="mk-neu-marke">
                            <x-fa::input id="mk-neu-marke" size="sm" wire:model="neuForm.marke" placeholder="Markenname wie auf dem Artikel" data-mk-neu-marke />
                        </x-fa::field>
                        <x-fa::field label="Typ" for="mk-neu-typ">
                            <x-fa::select id="mk-neu-typ" size="sm" wire:model="neuForm.typ" :options="$typLabel" />
                        </x-fa::field>
                        <x-fa::field label="Position" for="mk-neu-position">
                            <x-fa::select id="mk-neu-position" size="sm" wire:model="neuForm.position" :options="['anfang' => 'am Anfang', 'ende' => 'am Ende']" />
                        </x-fa::field>
                        <x-fa::field label="Vorrang" for="mk-neu-vorrang" hint="0–9">
                            <x-fa::input id="mk-neu-vorrang" size="sm" type="number" min="0" max="9" numeric wire:model="neuForm.vorrang" />
                        </x-fa::field>
                        <x-fa::button size="sm" variant="primary" wire:click="neuSpeichern" data-mk-neu-speichern>Speichern</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    </div>
                </x-fa::section>
            @endif

            <div class="flex flex-wrap gap-2">
                <x-fa::button size="sm" :variant="$dublettenAnsicht ? 'ghost' : 'secondary'" wire:click="$set('dublettenAnsicht', false)">Alle Einträge</x-fa::button>
                <x-fa::button size="sm" :variant="$dublettenAnsicht ? 'secondary' : 'ghost'" wire:click="$set('dublettenAnsicht', true)" data-mk-dubletten-ansicht>Bei mehreren Lieferanten gleich angelegt</x-fa::button>
            </div>

            @if($dublettenAnsicht)
                <x-fa::section title="Bei mehreren Lieferanten gleich angelegt" :meta="(string) count($dubletten)" data-mk-dubletten
                    description="Herstellermarken mit gleicher Marke, gleichem Kürzel und gleicher Position, je Lieferant angelegt. Zusammenführen legt eine Zeile für alle Lieferanten an (höchster Vorrang) und entfernt die Lieferanten-Zeilen. Eigenmarken und Sperren bleiben je Lieferant.">
                    @forelse($dubletten as $d)
                        <div class="flex flex-col gap-2 py-2 border-b border-[var(--fa-line)] last:border-b-0" wire:key="mkd-{{ md5($d['schluessel']) }}" data-mk-dublette>
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-medium">{{ $d['marke'] }}</span>
                                <span class="font-mono text-[var(--fa-ink-2)]">{{ $d['kuerzel'] }}</span>
                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $d['position'] === 'ende' ? 'am Ende' : 'am Anfang' }} · {{ implode(', ', $d['lieferanten']) }}</span>
                                @if($d['vorrang_abweichend'])<x-fa::badge tone="warn">Vorrang abweichend, nimmt {{ $d['vorrang'] }}</x-fa::badge>@endif
                                @if($d['global_konflikt'])<x-fa::badge tone="crit" title="{{ $d['global_konflikt'] }}">globaler Konflikt</x-fa::badge>@endif
                                @if($darfPflegen && ! $d['global_konflikt'])
                                    <span class="ml-auto flex gap-1">
                                        <x-fa::button size="sm" variant="ghost" wire:click="zusammenfuehrenVorschau({{ \Illuminate\Support\Js::from($d['schluessel']) }})" data-mk-zusammen-vorschau>Vorschau</x-fa::button>
                                        @if(($zusammenVorschau['schluessel'] ?? null) === $d['schluessel'])
                                            <x-fa::button size="sm" variant="primary" wire:click="zusammenfuehren({{ \Illuminate\Support\Js::from($d['schluessel']) }})" data-mk-zusammen>Für alle zusammenführen</x-fa::button>
                                        @endif
                                    </span>
                                @endif
                            </div>
                            @if(($zusammenVorschau['schluessel'] ?? null) === $d['schluessel'])
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-mk-zusammen-vorschau-text>
                                    Neu: „{{ $zusammenVorschau['marke'] }}“ ({{ $zusammenVorschau['kuerzel'] }}) für alle Lieferanten, Vorrang {{ $zusammenVorschau['vorrang'] }}; entfällt bei {{ implode(', ', $zusammenVorschau['entfernt']) }}.
                                    Nachrendern danach: {{ $zusammenVorschau['nachrendern']['zaehler']['gerendert'] }} GP neu benannt, {{ $zusammenVorschau['nachrendern']['zaehler']['teilen'] }} teilen, {{ $zusammenVorschau['nachrendern']['zaehler']['fall_b'] }} Fall B.
                                </p>
                            @endif
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine doppelt angelegten Herstellermarken">Jede Herstellermarke steht nur einmal.</x-fa::empty>
                    @endforelse
                </x-fa::section>
            @endif

            <div class="flex flex-wrap items-end gap-3">
                <x-fa::field label="Lieferant" for="mk-lieferant">
                    <x-fa::select id="mk-lieferant" size="sm" wire:model.live="lieferant">
                        <option value="">Alle</option>
                        @foreach($lieferanten as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Typ" for="mk-typ">
                    <x-fa::select id="mk-typ" size="sm" wire:model.live="typ">
                        <option value="">Alle Typen</option>
                        @foreach($typLabel as $wert => $label)<option value="{{ $wert }}">{{ $label }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    <input type="checkbox" wire:model.live="nurGlobal" data-mk-nur-global> nur global
                </label>
                <x-fa::field label="Suchen" for="mk-suche">
                    <x-fa::input id="mk-suche" size="sm" type="search" wire:model.live.debounce.300ms="suche" placeholder="Marke oder Kürzel" />
                </x-fa::field>
                <x-fa::button size="sm" variant="ghost" wire:click="zaehlen" wire:loading.attr="disabled" title="Zählt für diese Seite, wie viele Artikel jedes Kürzel auflöst (dauert einige Sekunden)" data-mk-zaehlen>Artikel zählen</x-fa::button>
            </div>

            <x-fa::section title="Marken und Kürzel" :meta="(string) $liste['gesamt']">
                @if($liste['zeilen'] === [])
                    <x-fa::empty compact icon="heroicon-o-tag" title="Keine Einträge in dieser Auswahl">Filter ändern.</x-fa::empty>
                @else
                    <div class="-mx-4 -mb-4 overflow-x-auto border-t border-[var(--fa-line)]">
                        <table class="fa-table">
                            <thead>
                                <tr>
                                    <th>Marke</th><th>Kürzel</th><th>Gilt für</th><th>Position</th><th>Typ</th><th class="text-right">Vorrang</th>
                                    <th class="text-right">GPs</th><th class="text-right">Artikel</th><th><span class="sr-only">Aktionen</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($liste['zeilen'] as $z)
                                    <tr wire:key="mk-{{ $z['id'] }}" data-marke-zeile="{{ $z['id'] }}">
                                        @if($bearbeitenId === $z['id'])
                                            <td>
                                                @if($z['typ'] === 'sperre' && ($editForm['typ'] ?? '') === 'sperre')
                                                    <span class="text-[var(--fa-ink-3)]">nie eine Marke</span>
                                                @else
                                                    <x-fa::input size="sm" wire:model="editForm.marke" data-mk-edit-marke />
                                                @endif
                                            </td>
                                            <td class="font-mono">{{ $z['kuerzel'] }}</td>
                                            <td>{{ $z['lieferant'] ?? 'alle' }}</td>
                                            <td><x-fa::select size="sm" wire:model="editForm.position" :options="['anfang' => 'am Anfang', 'ende' => 'am Ende']" /></td>
                                            <td><x-fa::select size="sm" wire:model="editForm.typ" :options="$typLabel" /></td>
                                            <td class="text-right"><x-fa::input size="sm" type="number" min="0" max="9" numeric wire:model="editForm.vorrang" /></td>
                                            <td></td><td></td>
                                            <td class="whitespace-nowrap text-right">
                                                <x-fa::button size="sm" variant="primary" wire:click="speichern" data-mk-edit-speichern>Speichern</x-fa::button>
                                                <x-fa::button size="sm" variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                                            </td>
                                        @else
                                            <td class="{{ $z['typ'] === 'sperre' ? 'text-[var(--fa-ink-3)]' : 'font-medium' }}">{{ $z['typ'] === 'sperre' ? 'nie eine Marke' : $z['marke'] }}</td>
                                            <td class="font-mono">{{ $z['kuerzel'] }}</td>
                                            <td>{{ $z['lieferant'] ?? 'alle' }}</td>
                                            <td>{{ $z['position'] === 'ende' ? 'am Ende' : 'am Anfang' }}</td>
                                            <td><x-fa::badge :tone="$typTon[$z['typ']] ?? 'neutral'">{{ $typLabel[$z['typ']] ?? $z['typ'] }}</x-fa::badge></td>
                                            <td class="text-right tabular-nums">{{ $z['vorrang'] }}</td>
                                            <td class="text-right tabular-nums" data-mk-gps>{{ $z['gps'] === null ? '–' : $fmt($z['gps']) }}</td>
                                            <td class="text-right tabular-nums" data-mk-artikel>{{ array_key_exists($z['id'], $artikelZahlen) ? $fmt($artikelZahlen[$z['id']]) : '–' }}</td>
                                            <td class="whitespace-nowrap text-right">
                                                @if($darfPflegen)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="bearbeiten({{ $z['id'] }})" data-mk-bearbeiten>Bearbeiten</x-fa::button>
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-trash" wire:click="loeschen({{ $z['id'] }})"
                                                        wire:confirm="Eintrag {{ $z['kuerzel'] }} entfernen?" aria-label="Eintrag {{ $z['kuerzel'] }} entfernen" data-mk-loeschen />
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>

            @if($letzteSeite > 1)
                <div class="flex items-center justify-between">
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">Seite {{ $liste['seite'] }} von {{ $letzteSeite }}</span>
                    <div class="flex gap-1">
                        <x-fa::icon-button icon="heroicon-m-chevron-left" label="Vorherige Seite" size="sm" wire:click="blaettern(-1)" :disabled="$liste['seite'] <= 1" />
                        <x-fa::icon-button icon="heroicon-m-chevron-right" label="Nächste Seite" size="sm" wire:click="blaettern(1)" :disabled="$liste['seite'] >= $letzteSeite" />
                    </div>
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
