{{-- M-K10 / Doc 16 §11: Kalkulator. Bibliothek links, Positions-Editor rechts.
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt. Anordnung unverändert
     (Bibliothek · Kopf · Positionen · Ergebnis). Löschen ins Menü „Weitere Aktionen",
     fehlender Einzelpreis als „Preis fehlt" statt 0,00 €, Positionstyp als Chips.
     Funktion, wire:-Bindungen und Feldnamen unverändert. --}}
@php
    $typTon = ['gericht' => 'accent', 'basisrezept' => 'info', 'gp' => 'ok', 'frei' => 'neutral'];
    $typLabel = ['gericht' => 'Gericht', 'basisrezept' => 'Basisrezept', 'gp' => 'Grundprodukt', 'frei' => 'Frei'];
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Kalkulator" icon="heroicon-o-calculator" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Kalkulator" subtitle="Positionen zusammenstellen und vom Wareneinsatz bis zum Verkaufspreis rechnen." />

        <div class="grid grid-cols-1 lg:grid-cols-[18rem_minmax(0,1fr)] gap-4">

            {{-- Bibliothek --}}
            <div class="fa-surface self-start min-w-0" data-kalkulator-liste>
                <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-[var(--fa-line)]">
                    <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Kalkulationen</p>
                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-plus" wire:click="neueKalkulation">Neue Kalkulation</x-fa::button>
                </div>
                <div class="flex flex-col gap-0.5 p-1.5 max-h-[70vh] overflow-y-auto">
                    @forelse($kalkulationen as $k)
                        <button type="button" wire:key="kalk-{{ $k->id }}" wire:click="waehle({{ $k->id }})"
                                @if($selectedId === $k->id) aria-current="true" @endif
                                class="w-full text-left px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedId === $k->id ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                            <span class="block text-[length:var(--fa-text-md)] font-medium break-words {{ $selectedId === $k->id ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">{{ $k->title }}</span>
                            <span class="block {{ $leise }} tabular-nums">{{ $k->positionen_count }} {{ $k->positionen_count === 1 ? 'Position' : 'Positionen' }}</span>
                        </button>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-calculator" title="Noch keine Kalkulation">Mit „Neue Kalkulation“ die erste anlegen.</x-fa::empty>
                    @endforelse
                </div>
            </div>

            {{-- Editor --}}
            @if($active === null)
                <div class="fa-surface flex items-center justify-center min-h-[40vh]">
                    <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Keine Kalkulation geöffnet">
                        Links eine Kalkulation wählen oder eine neue anlegen.
                        <x-slot:action><x-fa::button icon="heroicon-m-plus" wire:click="neueKalkulation">Neue Kalkulation</x-fa::button></x-slot:action>
                    </x-fa::empty>
                </div>
            @else
                <div class="flex flex-col gap-4 min-w-0">
                    {{-- Kopf --}}
                    <x-fa::section title="Kalkulation" icon="heroicon-o-document-text">
                        <x-slot:actions>
                            @if($meldung)<x-fa::signal tone="ok">{{ $meldung }}</x-fa::signal>@endif
                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                <div class="hidden w-52 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="loeschen({{ $active->id }})" wire:confirm="Diese Kalkulation löschen?"
                                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                        @svg('heroicon-o-trash', 'w-4 h-4') Kalkulation löschen
                                    </button>
                                </div>
                            </div>
                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichereKopf">Speichern</x-fa::button>
                        </x-slot:actions>
                        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))]">
                            <x-fa::field label="Titel" for="kalk-titel">
                                <x-fa::input id="kalk-titel" wire:model="titel" placeholder="Zum Beispiel: Menü Sommerfest" />
                            </x-fa::field>
                            <x-fa::field label="Eigene Marge (%)" for="kalk-marge" hint="Leer: Marge aus den Team-Einstellungen.">
                                <x-fa::input id="kalk-marge" type="number" min="0" step="0.5" numeric wire:model="margeOverride" placeholder="Team" />
                            </x-fa::field>
                        </div>
                        <x-fa::field label="Notiz" for="kalk-notiz" optional>
                            <x-fa::input id="kalk-notiz" wire:model="note" />
                        </x-fa::field>
                    </x-fa::section>

                    {{-- Positionen --}}
                    <section class="fa-surface min-w-0" data-kalkulator-positionen>
                        <header class="px-4 pt-4 pb-2">
                            <h3 class="flex items-center gap-2 text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">@svg('heroicon-o-queue-list', 'w-[18px] h-[18px] text-[var(--fa-ink-3)]') Positionen</h3>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="fa-table">
                                <thead>
                                    <tr>
                                        <th>Typ</th>
                                        <th class="w-full">Position</th>
                                        <th class="num">Menge</th>
                                        <th>Einheit</th>
                                        <th class="num">Einzel-EK</th>
                                        <th class="num">Wareneinsatz</th>
                                        <th class="num">Minuten</th>
                                        <th><span class="sr-only">Aktionen</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($berechnung['positionen'] as $p)
                                        <tr wire:key="pos-{{ $p['id'] }}">
                                            <td><x-fa::badge :tone="$typTon[$p['type']] ?? 'neutral'">{{ $typLabel[$p['type']] ?? $p['type'] }}</x-fa::badge></td>
                                            <td class="min-w-[12rem]">
                                                <x-fa::input size="sm" value="{{ $p['label'] }}" aria-label="Bezeichnung"
                                                    wire:change="updatePos({{ $p['id'] }}, 'label', $event.target.value)" />
                                            </td>
                                            <td class="num">
                                                <x-fa::input size="sm" numeric type="number" min="0" step="0.001" class="w-24" aria-label="Menge"
                                                    value="{{ rtrim(rtrim(number_format($p['quantity'], 3, '.', ''), '0'), '.') }}"
                                                    wire:change="updatePos({{ $p['id'] }}, 'quantity', $event.target.value)" />
                                            </td>
                                            <td>
                                                <x-fa::input size="sm" class="w-20" aria-label="Einheit" value="{{ $p['unit'] }}" placeholder="Stk"
                                                    wire:change="updatePos({{ $p['id'] }}, 'unit', $event.target.value)" />
                                            </td>
                                            <td class="num">
                                                <x-fa::input size="sm" numeric type="number" min="0" step="0.0001" class="w-28" aria-label="Einzel-EK in Euro"
                                                    value="{{ rtrim(rtrim(number_format($p['einzel_ek'], 4, '.', ''), '0'), '.') }}"
                                                    wire:change="updatePos({{ $p['id'] }}, 'einzel_ek', $event.target.value)" />
                                            </td>
                                            <td class="num font-medium">
                                                @if((float) $p['einzel_ek'] > 0)
                                                    <x-fa::money :value="$p['wareneinsatz']" />
                                                @else
                                                    <x-fa::money :value="null" title="Kein Einzelpreis: der Wareneinsatz dieser Position fehlt in der Summe" />
                                                @endif
                                            </td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $p['work_time_min'] !== null ? $p['work_time_min'] : '–' }}</td>
                                            <td class="whitespace-nowrap text-right">
                                                @if($p['type'] !== 'frei')
                                                    <x-fa::icon-button size="sm" icon="heroicon-m-arrow-path" label="Preis und Zeit neu übernehmen" wire:click="aktualisierePos({{ $p['id'] }})" />
                                                @endif
                                                <x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-x-mark" label="Position entfernen" wire:click="entfernePos({{ $p['id'] }})" />
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8"><x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Positionen">Unten ein Gericht, Basisrezept oder Grundprodukt hinzufügen.</x-fa::empty></td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Hinzufügen --}}
                        <div class="px-4 py-3 border-t border-[var(--fa-line)] bg-[var(--fa-ground)] rounded-b-[var(--fa-radius-surface)] flex flex-col gap-3" data-kalkulator-hinzufuegen>
                            <div class="flex flex-wrap items-end gap-3">
                                <x-fa::choice name="addTyp" label="Position hinzufügen" idPrefix="kalk" :options="$typLabel" />
                                @if($addTyp === 'frei')
                                    <x-fa::button icon="heroicon-m-plus" wire:click="addPosition">Freie Zeile hinzufügen</x-fa::button>
                                @else
                                    <div class="relative w-full max-w-xs">
                                        <label for="kalk-suche" class="sr-only">{{ $typLabel[$addTyp] }} suchen</label>
                                        @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                                        <x-fa::input id="kalk-suche" type="search" wire:model.live.debounce.300ms="addSuche" class="pl-8" placeholder="{{ $typLabel[$addTyp] }} suchen" />
                                    </div>
                                @endif
                            </div>

                            @if($addTyp !== 'frei')
                                <div class="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto">
                                    @forelse($quellen as $q)
                                        <x-fa::button size="sm" icon="heroicon-m-plus" wire:key="q-{{ $addTyp }}-{{ $q['id'] }}" wire:click="addPosition({{ $q['id'] }})">{{ $q['label'] }}</x-fa::button>
                                    @empty
                                        <span class="{{ $leise }}">{{ $addSuche !== '' ? 'Nichts gefunden.' : 'Zum Suchen tippen oder aus der Liste wählen.' }}</span>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </section>

                    {{-- Ergebnis: HK1 → Zuschläge → HK2 → VK --}}
                    @php
                        $margeText = rtrim(rtrim(number_format((float) $berechnung['marge_pct'], 2, ',', '.'), '0'), ',');
                    @endphp
                    <x-fa::section title="Ergebnis" icon="heroicon-o-calculator"
                        :meta="'Marge ' . $margeText . ' %' . ($active->margin_override_pct !== null ? ', eigene Marge' : ', aus den Team-Einstellungen')">
                        <dl class="max-w-md flex flex-col text-[length:var(--fa-text-md)]" data-kalkulator-ergebnis>
                            <div class="flex items-center justify-between gap-3 py-1 font-medium text-[var(--fa-ink)]">
                                <dt>HK1 · Wareneinsatz (Summe der Positionen)</dt>
                                <dd class="tabular-nums whitespace-nowrap">{{ $euro($berechnung['hk1']) }}</dd>
                            </div>
                            @foreach($berechnung['bloecke'] as $blk)
                                @if($blk['key'] !== 'we')
                                    <div class="flex items-center justify-between gap-3 py-1 text-[var(--fa-ink-2)]">
                                        <dt>+ {{ $blk['label'] }}</dt>
                                        <dd class="tabular-nums whitespace-nowrap">{{ $euro($blk['betrag']) }}</dd>
                                    </div>
                                @endif
                            @endforeach
                            <div class="flex items-center justify-between gap-3 py-2 mt-1 border-t border-[var(--fa-line-strong)] font-semibold text-[var(--fa-ink)]">
                                <dt>= HK2 · Selbstkosten</dt>
                                <dd class="tabular-nums whitespace-nowrap">{{ $euro($berechnung['hk2']) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 py-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)] px-3 -mx-3">
                                <dt class="text-[var(--fa-ink-2)]">VK-Vorschlag (HK2 mit Marge)</dt>
                                <dd class="tabular-nums whitespace-nowrap text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-accent)]">{{ $euro($berechnung['vk_vorschlag']) }}</dd>
                            </div>
                        </dl>
                        <p class="{{ $leise }}">Arbeitszeit gesamt: {{ number_format((float) $berechnung['work_time_min'], 0, ',', '.') }} Minuten. Stundensatz und Zuschläge stehen in den Einstellungen unter Kalkulation.</p>
                    </x-fa::section>
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
