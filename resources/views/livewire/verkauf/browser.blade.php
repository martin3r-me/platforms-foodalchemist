{{-- Gerichte-Browser (Verkauf) — Hauptgruppen links, Tabelle Mitte, Preis-Cockpit rechts.
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt, analog Basisrezept-Browser.
     Hauptaufgabe: Gerichte finden und Preis/Wareneinsatz prüfen. Neu: Status-Filter als Chips über
     der Tabelle, Wareneinsatz in Ampelfarbe, fehlender VK/EK als Signal, Status als Chip mit Menü.
     Funktion, wire:-Bindungen und data-Marker unverändert. --}}
@php
    $statusOptionen = ['' => 'Alle'];
    foreach ($statusFaelle as $fall) {
        if (($statusCounts[$fall->value] ?? 0) > 0 || $status === $fall->value) {
            $statusOptionen[$fall->value] = $fall->label() . ' ' . number_format($statusCounts[$fall->value] ?? 0, 0, ',', '.');
        }
    }
    $konfidenzTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit'];
    $statusWahl = [\Platform\FoodAlchemist\Enums\RecipeStatus::Draft, \Platform\FoodAlchemist\Enums\RecipeStatus::Review, \Platform\FoodAlchemist\Enums\RecipeStatus::Approved, \Platform\FoodAlchemist\Enums\RecipeStatus::Deprecated];
    $numSpalten = ['ek', 'vk', 'we', 'zutaten'];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Gerichte" icon="heroicon-o-banknotes" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Hauptgruppen" width="w-80">
            <div class="p-3 flex flex-col gap-3" data-vk-baum>
                <div class="relative">
                    <label for="vk-suche" class="sr-only">Gerichte durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="vk-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Name, Verkaufstext oder Kunde" class="pl-8" data-vk-suche />
                </div>

                {{-- Geschmack: zweiter Klick hebt die Auswahl auf (waehleGeschmack schaltet um). Labels zentral (MVP-024). --}}
                <div role="group" aria-label="Geschmack" class="flex flex-wrap gap-1.5" data-geschmack-pills>
                    @foreach(['suess', 'herzhaft', 'neutral'] as $wert)
                        <button type="button" wire:click="waehleGeschmack('{{ $wert }}')" aria-pressed="{{ $geschmack === $wert ? 'true' : 'false' }}"
                                class="h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] transition-colors {{ $geschmack === $wert ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]' }}">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($wert) }}</button>
                    @endforeach
                </div>

                {{-- Baum: Diät-Klassen sind die aufklappbare Ebene unter dem AKTIVEN Knoten.
                     „Alle Hauptgruppen" offen → globale Klassen-Zähler; Hauptgruppe offen → auf sie begrenzt. --}}
                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    <x-foodalchemist::filter-row wire:click="waehleHauptgruppe(null)" :active="$hauptgruppe === null"
                        :child-active="$klasse !== null" :count="$gesamtCount" data-gesamt-count>Alle Hauptgruppen</x-foodalchemist::filter-row>
                    @if($hauptgruppe === null)
                        <x-foodalchemist::filter-ast data-vk-klassen-ast>
                            @foreach($klassen as $k)
                                <x-foodalchemist::filter-row level="child" wire:key="vkk-alle-{{ $k->id }}"
                                    wire:click="waehleKlasse({{ $k->id }})"
                                    :active="$klasse === $k->id"
                                    :count="$klassenCounts[$k->id] ?? 0">{{ $k->label }}</x-foodalchemist::filter-row>
                            @endforeach
                        </x-foodalchemist::filter-ast>
                    @endif

                    <div class="flex flex-col gap-0.5" data-vk-hg-liste>
                        @foreach($hauptgruppen as $hg)
                            <div wire:key="vkhg-{{ $hg->id }}">
                                <x-foodalchemist::filter-row wire:click="waehleHauptgruppe({{ $hg->id }})"
                                    :active="$hauptgruppe === $hg->id" :child-active="$klasse !== null"
                                    :count="$hgCounts[$hg->id] ?? 0" title="{{ $hg->code }} · {{ $hg->label }}"><span class="inline-block w-9 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $hg->code }}</span>{{ $hg->label }}</x-foodalchemist::filter-row>
                                @if($hauptgruppe === $hg->id)
                                    <x-foodalchemist::filter-ast data-vk-klassen-ast>
                                        @foreach($klassen as $k)
                                            @if(($klassenCounts[$k->id] ?? 0) > 0 || $klasse === $k->id)
                                                <x-foodalchemist::filter-row level="child" wire:key="vkk-{{ $hg->id }}-{{ $k->id }}"
                                                    wire:click="waehleKlasse({{ $k->id }})"
                                                    :active="$klasse === $k->id"
                                                    :count="$klassenCounts[$k->id] ?? 0">{{ $k->label }}</x-foodalchemist::filter-row>
                                            @endif
                                        @endforeach
                                    </x-foodalchemist::filter-ast>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Detail-Spalte erst, wenn ein Gericht gewählt ist — vorher nahm der leere Hinweis Platz der Tabelle weg. --}}
    @if($recipeId !== null)
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_verkauf" side="right">
            <livewire:foodalchemist.verkauf.detail-panel :recipe-id="$recipeId" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>
    @endif

    {{-- M6-04: VK-Editor + geteilter Zutaten-Editor (P-2: innerhalb x-ui-page) --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    <livewire:foodalchemist.verkauf.vk-generator-modal />
    <livewire:foodalchemist.recipes.ingredient-editor />
    <livewire:foodalchemist.recipes.pairing-netz-modal />
    {{-- R7-Fix: Sprung-Ziele des Zutaten-Editors als Modals (GP + Basisrezept) --}}
    <livewire:foodalchemist.gps.gp-modal />
    <livewire:foodalchemist.recipes.recipe-modal />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Gerichte" :subtitle="number_format($rezepte->total(), 0, ',', '.') . ' Treffer'">
            <x-slot:actions>
                {{-- KI-Erstellung lebt in der Planung-Leitstelle (2026-08, Regler-Leitplanken). --}}
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="$dispatch('vk-modal.oeffnen')" data-vk-anlegen>Neues Gericht</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <p class="-mt-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[80ch]">
            Der Verkaufspreis ergibt sich aus dem Materialeinsatz der Darreichung, dem Grundaufschlag des Unternehmens und dem Faktor der Speisen-Klasse.
        </p>

        @if($aktiverBetrieb !== null)
            <x-fa::signal tone="info" icon="heroicon-m-building-storefront" data-vk-brille-hinweis>
                VK und Wareneinsatz gelten für {{ $aktiverBetrieb }}, gerechnet mit dessen Kostenstruktur.
            </x-fa::signal>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-fa::choice name="status" :options="$statusOptionen" />
            {{-- E14: Ansichts-Schalter — knappe Spalten je Aufgabe --}}
            <div class="flex items-center gap-3">
                <div role="group" aria-label="Ansicht" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" data-ansicht-schalter>
                    @foreach($ansichten as $ak => [$al, $unused])
                        <button type="button" wire:click="$set('ansicht', '{{ $ak }}')" aria-pressed="{{ $ansicht === $ak ? 'true' : 'false' }}"
                                class="h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $ansicht === $ak ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                data-ansicht="{{ $ak }}">{{ $al }}</button>
                    @endforeach
                </div>
                <x-fa::select wire:model.live="perPage" size="sm" aria-label="Einträge je Seite" class="w-auto" data-per-page>
                    @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }} je Seite</option>@endforeach
                </x-fa::select>
            </div>
        </div>

        <div class="fa-surface overflow-hidden" data-vk-tabelle>
            {{-- MVP-024: Statuswechsel-Fehler sichtbar statt still verschluckt --}}
            @if($statusFehler !== null)
                <x-fa::notice tone="crit" class="m-3" data-status-fehler>{{ $statusFehler }}</x-fa::notice>
            @endif
            <div class="max-h-[70vh] overflow-auto">{{-- R13: schmaler Mittelteil scrollt statt abzuschneiden --}}
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            {{-- E14: Kopf folgt dem KATALOG, nicht der Ansicht (sonst Versatz zu den Zellen) --}}
                            @foreach($spalten as $sp)
                                <th class="{{ $spaltenKatalog[$sp][1] }} {{ in_array($sp, $numSpalten, true) ? 'num' : '' }}">{{ $spaltenKatalog[$sp][0] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rezepte as $r)
                            @php
                                // Ebene 2: mit Betriebsbrille zeigt die VK-Spalte den betriebsscharfen VK ($vkDisplay), sonst die Baseline (sales_net); WE folgt demselben VK.
                                $betriebsVk = ($vkDisplay[$r->id] ?? null) !== null;
                                $vkR = $betriebsVk ? (float) $vkDisplay[$r->id] : ($r->sales_net !== null ? (float) $r->sales_net : null);
                                $weR = ($vkR !== null && $vkR > 0 && $r->ek_total_eur !== null) ? (float) $r->ek_total_eur / $vkR * 100 : null;
                                $weFarbe = $weR === null ? 'text-[var(--fa-ink-3)]' : ($weR > 35 ? 'text-[var(--fa-crit)] font-semibold' : ($weR > 30 ? 'text-[var(--fa-warn)] font-medium' : 'text-[var(--fa-ink)]'));
                            @endphp
                            <x-foodalchemist::table-row :active="$recipeId === $r->id" wire:key="vk-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_verkauf', 'open', true)"
                                data-vk-zeile="{{ $r->id }}">
                                {{-- R6: Namens-Klick öffnet direkt den VK-Editor (Zeilen-Klick bleibt Panel-Auswahl) --}}
                                <td class="min-w-[8rem]" wire:click.stop="bearbeite({{ $r->id }})" title="{{ $r->name }}: zum Bearbeiten anklicken">
                                    <button type="button" class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline" data-vk-name>{{ $r->name }}</button>
                                </td>
                                @if(in_array('klasse', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $r->dishClass?->label ?? '–' }}</td>@endif
                                @if(in_array('geschmack', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($r->taste_direction) }}</td>@endif
                                @if(in_array('hauptgruppe', $spalten, true))<td class="whitespace-nowrap font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="{{ $r->dishMainGroup?->label }}">{{ $r->dishMainGroup?->code ?? '–' }}</td>@endif
                                @if(in_array('ek', $spalten, true))<td class="num"><x-fa::money :value="$r->ek_total_eur" missing="EK fehlt" /></td>@endif
                                @if(in_array('vk', $spalten, true))<td class="num" @if($betriebsVk) title="VK für {{ $aktiverBetrieb }}" @endif>
                                    <span class="inline-flex items-center gap-1 {{ $betriebsVk ? 'font-medium' : '' }}">
                                        @if($betriebsVk)@svg('heroicon-m-building-storefront', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')@endif
                                        <x-fa::money :value="$vkR" missing="VK fehlt" />
                                    </span>
                                </td>@endif
                                @if(in_array('we', $spalten, true))<td class="num {{ $weFarbe }}" title="Wareneinsatz = EK geteilt durch VK netto">{{ $weR !== null ? number_format($weR, 1, ',', '.') . ' %' : '–' }}</td>@endif
                                @if(in_array('zutaten', $spalten, true))<td class="num text-[var(--fa-ink-2)]">{{ $r->n_ingredients_total }}</td>@endif
                                @if(in_array('allergen', $spalten, true))<td class="whitespace-nowrap">
                                    <x-fa::badge :tone="$konfidenzTon[$r->allergens_confidence] ?? 'neutral'">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($r->allergens_confidence) }}</x-fa::badge>
                                </td>@endif
                                @if(in_array('status', $spalten, true))
                                {{-- Status als Chip; Kuratoren ändern ihn über ein kleines Menü (Platzhalter bleibt Auto-Zustand) --}}
                                <td class="whitespace-nowrap" wire:click.stop @click.stop>
                                    @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $r) && $r->status !== \Platform\FoodAlchemist\Enums\RecipeStatus::Stub)
                                        <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="vst-{{ $r->id }}-{{ $r->status->value }}">
                                            <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status von {{ $r->name }} ändern" data-status-select>
                                                <x-fa::status :value="$r->status" />@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                            </button>
                                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-40 fa-surface shadow-lg py-1">
                                                @foreach($statusWahl as $fall)
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="statusSetzen({{ $r->id }}, '{{ $fall->value }}')"
                                                            class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $r->status === $fall ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                                        {{ $fall->label() }}@if($r->status === $fall)@svg('heroicon-m-check', 'w-4 h-4')@endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <x-fa::status :value="$r->status" />
                                    @endif
                                </td>@endif
                            </x-foodalchemist::table-row>
                        @empty
                            <tr>
                                <td colspan="{{ count($spalten) + 1 }}">
                                    <x-fa::empty icon="heroicon-o-banknotes" title="Keine Gerichte gefunden">Filter zurücksetzen oder ein neues Gericht anlegen.</x-fa::empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $rezepte->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
