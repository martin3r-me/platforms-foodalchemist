{{-- Marken-Nachlauf (Plan GP-Stamm 3g): Rezeptur-GPs ohne Marke, gruppiert nach unbekannten Kürzeln; „Kürzel zuordnen“ nur Plattform-/Master-Admin. --}}
@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
    $fallLabel = ['gerendert' => 'neu benannt', 'teilen' => 'teilen', 'fall_b' => 'Fall B: Artikel ohne Marke', 'ohne_artikel' => 'ohne Artikel'];
    $fallTon = ['gerendert' => 'ok', 'teilen' => 'warn', 'fall_b' => 'neutral', 'ohne_artikel' => 'neutral'];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Marke offen" icon="heroicon-o-tag" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lieferanten', 'href' => route('foodalchemist.suppliers.index')],
            ['label' => 'Marke offen'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div class="flex flex-col gap-4" data-marke-offen>
            <x-fa::page-header title="Marke offen"
                :subtitle="$fmt($liste['gps_offen']) . ' Rezeptur-GPs ohne Marke · ' . $fmt($liste['artikel_offen']) . ' Artikel · ' . $fmt(count($liste['gruppen'])) . ' unbekannte Kürzel'" />

            @if($fehler)<x-fa::notice tone="crit" data-marke-offen-fehler>{{ $fehler }}</x-fa::notice>@endif
            @if($meldung)<x-fa::notice tone="ok" data-marke-offen-meldung>{{ $meldung }}</x-fa::notice>@endif

            @if($offenOhneRolle !== [])
                <x-fa::notice tone="warn" data-lieferanten-rolle-offen>
                    {{ count($offenOhneRolle) }} {{ count($offenOhneRolle) === 1 ? 'Lieferant ist' : 'Lieferanten sind' }} noch nicht eingestuft (Rolle „offen“), hängen aber an Rezepturware:
                    {{ collect($offenOhneRolle)->take(6)->map(fn ($l) => $l['name'] . ' (' . $l['gps'] . ')')->implode(' · ') }}{{ count($offenOhneRolle) > 6 ? ' · …' : '' }}.
                    Hersteller bekommen ihren Kurznamen als Marke — Rolle beim Lieferanten setzen.
                </x-fa::notice>
            @endif

            <div class="flex flex-wrap items-end gap-3">
                <x-fa::field label="Lieferant" for="mo-lieferant">
                    <x-fa::select id="mo-lieferant" size="sm" wire:model.live="lieferant">
                        <option value="">Alle Lieferanten</option>
                        @foreach($lieferanten as $l)
                            <option value="{{ $l['id'] }}">{{ $l['name'] }} ({{ $fmt($l['n']) }})</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Suchen" for="mo-suche">
                    <x-fa::input id="mo-suche" size="sm" type="search" wire:model.live.debounce.300ms="suche" placeholder="Kürzel oder Artikel" />
                </x-fa::field>
            </div>

            <x-fa::section title="Unbekannte Kürzel" :meta="(string) count($gruppen)">
                @if($gruppen === [])
                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine unbekannten Kürzel in dieser Auswahl">Filter ändern oder später wieder schauen.</x-fa::empty>
                @else
                    <div class="-mx-4 -mb-4 overflow-x-auto border-t border-[var(--fa-line)]">
                        <table class="fa-table">
                            <thead>
                                <tr>
                                    <th>Kürzel</th>
                                    <th>Lieferant</th>
                                    <th class="text-right">GPs</th>
                                    <th class="text-right">Artikel</th>
                                    <th>Beispiele</th>
                                    <th><span class="sr-only">Aktionen</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($gruppen as $g)
                                    <tr wire:key="mo-{{ $g['schluessel'] }}" data-marke-offen-gruppe="{{ $g['schluessel'] }}">
                                        <td class="whitespace-nowrap">
                                            <span class="font-mono font-medium">{{ $g['roh'] }}</span>
                                            <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $g['position'] === 'ende' ? 'am Ende' : 'am Anfang' }}</div>
                                        </td>
                                        <td>{{ $g['lieferant'] }}</td>
                                        <td class="text-right tabular-nums">{{ $fmt($g['gps']) }}</td>
                                        <td class="text-right tabular-nums">{{ $fmt($g['artikel']) }}</td>
                                        <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ implode(' · ', $g['beispiele']) }}</td>
                                        <td class="whitespace-nowrap text-right">
                                            @if($darfZuordnen && $offen !== $g['schluessel'])
                                                <x-fa::button size="sm" variant="secondary" wire:click="oeffnen(@js($g['schluessel']))" data-kuerzel-zuordnen-oeffnen>Kürzel zuordnen</x-fa::button>
                                            @endif
                                        </td>
                                    </tr>
                                    @if($offen === $g['schluessel'])
                                        <tr wire:key="mo-form-{{ $g['schluessel'] }}">
                                            <td colspan="6">
                                                <div class="flex flex-col gap-3 py-2" data-kuerzel-zuordnen-form>
                                                    <div class="flex flex-wrap items-end gap-3">
                                                        <x-fa::field label="Marke (Schreibweise in der Klammer)" for="mo-marke">
                                                            <x-fa::input id="mo-marke" size="sm" wire:model="form.marke" placeholder="z. B. Bake & Bite" data-mo-marke />
                                                        </x-fa::field>
                                                        <x-fa::field label="Typ" for="mo-typ">
                                                            <x-fa::select id="mo-typ" size="sm" wire:model="form.typ" :options="['herstellermarke' => 'Herstellermarke', 'eigenmarke' => 'Eigenmarke des Händlers']" />
                                                        </x-fa::field>
                                                        <x-fa::field label="Gilt für" for="mo-geltung">
                                                            <x-fa::select id="mo-geltung" size="sm" wire:model="form.geltung" :options="['lieferant' => 'nur ' . $g['lieferant'], 'alle' => 'alle Lieferanten']" />
                                                        </x-fa::field>
                                                        <x-fa::field label="Vorrang" for="mo-vorrang" hint="0–9, höher gewinnt">
                                                            <x-fa::input id="mo-vorrang" size="sm" type="number" min="0" max="9" numeric wire:model="form.vorrang" />
                                                        </x-fa::field>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <x-fa::button size="sm" variant="ghost" wire:click="vorschau" data-kuerzel-vorschau>Vorschau</x-fa::button>
                                                        <x-fa::button size="sm" variant="primary" wire:click="zuordnen" data-kuerzel-zuordnen>Zuordnen und GPs neu benennen</x-fa::button>
                                                        <x-fa::button size="sm" variant="ghost" wire:click="schliessen">Abbrechen</x-fa::button>
                                                    </div>
                                                    @if(($ergebnis['trockenlauf'] ?? false) && ($ergebnis['gps'] ?? []) !== [])
                                                        <ul class="flex flex-col gap-1 text-[length:var(--fa-text-sm)]" data-kuerzel-vorschau-liste>
                                                            @foreach($ergebnis['gps'] as $gp)
                                                                <li class="flex items-center gap-2">
                                                                    <x-fa::badge :tone="$fallTon[$gp['fall']]">{{ $fallLabel[$gp['fall']] }}</x-fa::badge>
                                                                    <span>{{ $gp['name_alt'] }}</span>
                                                                    @if($gp['name_neu'])<span class="text-[var(--fa-ink-3)]">→</span><span class="font-medium">{{ $gp['name_neu'] }}</span>@endif
                                                                    @if($gp['fall'] === 'teilen')<span class="text-[var(--fa-ink-3)]">({{ implode(', ', $gp['marken']) }})</span>@endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        </div>
    </x-ui-page-container>
</x-ui-page>
