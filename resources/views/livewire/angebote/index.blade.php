{{-- #380: Angebote-Übersicht — Anfrage → Angebot, kundengebunden.
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Die schmale Filterspalte (Suche + Status)
     ist entfallen: Suche und Status-Chips stehen direkt über der Tabelle, die Tabelle bekommt die Breite.
     Häufigste Aufgabe: ein Angebot finden und öffnen → Suche zuerst, Zeilenklick öffnet den Editor.
     Funktion, wire:-Bindungen und data-Marker unverändert. --}}
@php
    // Lebenszyklus-Farbe aus der Badge-Variante des Enums (AngebotStatus::badgeVariant) → Token-Ton.
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'warning' => 'warn', 'primary' => 'accent', 'success' => 'ok', 'danger' => 'crit'];
    $chip = 'inline-flex items-center h-7 px-3 rounded-full text-[length:var(--fa-text-sm)] font-medium border transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border-[var(--fa-accent-line)]';
    $chipAus = 'bg-[var(--fa-surface)] text-[var(--fa-ink-2)] border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Angebote" icon="heroicon-o-document-text" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Angebote'],
        ]" />
    </x-slot>

    {{-- Editor (Vollbild, pro Angebot) — geöffnet per angebot-editor.bearbeiten --}}
    <livewire:foodalchemist.angebote.editor />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Angebote" :subtitle="number_format($items->total(), 0, ',', '.') . ' ' . ($items->total() === 1 ? 'Angebot' : 'Angebote')">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu" data-angebot-neu>Neue Anfrage anlegen</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-80">
                <label for="angebot-suche" class="sr-only">Angebote durchsuchen</label>
                @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                <x-fa::input id="angebot-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Angebot oder Anfrage suchen" class="pl-8" />
            </div>
            <div role="group" aria-label="Status" class="flex flex-wrap items-center gap-1.5">
                <button type="button" wire:click="waehleStatus('')" aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}"
                        class="{{ $chip }} {{ $statusFilter === '' ? $chipAn : $chipAus }}">Alle</button>
                @foreach($statusWerte as $sw)
                    <button type="button" wire:key="st-{{ $sw['value'] }}" wire:click="waehleStatus('{{ $sw['value'] }}')"
                            aria-pressed="{{ $statusFilter === $sw['value'] ? 'true' : 'false' }}"
                            class="{{ $chip }} {{ $statusFilter === $sw['value'] ? $chipAn : $chipAus }}">{{ $sw['label'] }}</button>
                @endforeach
            </div>
        </div>

        <div class="fa-surface overflow-hidden" data-angebot-tabelle>
            {{-- Spec 28: eigener Scroll-Kasten, damit der Tabellenkopf kleben kann --}}
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            <th>Status</th>
                            <th>Anlass</th>
                            <th class="num">Gäste</th>
                            <th>Datum</th>
                            <th class="num">Angebotssumme</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            <x-foodalchemist::table-row :active="$selectedId === $it->id" wire:key="ang-{{ $it->id }}" wire:click="waehle({{ $it->id }})" data-angebot-zeile="{{ $it->id }}">
                                <td class="min-w-[12rem] font-medium text-[var(--fa-ink)]">{{ $it->name }}</td>
                                <td class="whitespace-nowrap">
                                    <x-fa::badge :tone="$statusTon[$it->status->badgeVariant()] ?? 'neutral'" data-status="{{ $it->status->value }}">{{ $it->status->label() }}</x-fa::badge>
                                </td>
                                <td class="text-[var(--fa-ink-2)]">{{ $it->occasion ?: '–' }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $it->personen ?? '–' }}</td>
                                <td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]">{{ $it->event_date ? $it->event_date->format('d.m.Y') : '–' }}</td>
                                <td class="num"><x-fa::money :value="$it->total_price" missing="Noch kein Preis" /></td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr wire:key="ang-empty">
                                <td colspan="6">
                                    <x-fa::empty icon="heroicon-o-document-text" title="Keine Angebote gefunden">
                                        Suche oder Status zurücksetzen oder oben eine neue Anfrage anlegen.
                                    </x-fa::empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $items->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>

    {{-- #380: Concepter-Editor wiederverwendet — bearbeitet angebots-lokale Menü-Entwürfe
         (öffnet via concepter-editor.oeffnen aus dem Angebote-Editor). Gleiche
         Einbettung wie im Concepter-Browser, damit die Konzept-Bausteine identisch laufen. --}}
    <livewire:foodalchemist.concepter.editor />
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
