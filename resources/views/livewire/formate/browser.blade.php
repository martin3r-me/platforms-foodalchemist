{{-- Formate-Browser — Marken- und Themen-Container über den Konzepten (z. B. CHEFS.CORNER).
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und
     Event-Namen unverändert. Neu: „Neues Format" als Hauptaktion im Seitenkopf, Filter als Filterzeilen
     (Einsatz-Dimensionen eingeklappt, offen sobald gewählt), Status als Chip mit Menü statt Dropdown je
     Zeile, Detail-Spalte erst nach Auswahl. --}}
@php
    $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'];
    $statusTon = ['draft' => 'neutral', 'active' => 'ok', 'archiviert' => 'neutral'];
    $statusIcon = ['draft' => 'heroicon-m-pencil', 'active' => 'heroicon-m-check', 'archiviert' => 'heroicon-m-archive-box'];
    $originLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'];

    // Einsatz-Dimensionen: [Überschrift, Property, Methoden-Feld, Vokabular, Label-Feld, wire:key-Präfix]
    $facetten = [
        ['Eventtyp', $eventtypFilter, 'eventtypFilter', $facetteEventtypen, 'name', 'ffev'],
        ['Servierform', $servierformFilter, 'servierformFilter', $facetteServierformen, 'label', 'ffsf'],
        ['Einsatzmoment', $momentFilter, 'momentFilter', $facetteMomente, 'name', 'ffem'],
        ['Saison', $saisonFilter, 'saisonFilter', $facetteSaisons, 'name', 'ffsa'],
    ];
    $filterAktiv = $search !== '' || $statusFilter !== '' || $originFilter !== ''
        || $eventtypFilter !== '' || $servierformFilter !== '' || $momentFilter !== '' || $saisonFilter !== '';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Formate" icon="heroicon-o-rectangle-group" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Formate" width="w-80">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="format-suche" class="sr-only">Formate durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="format-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Name, Gästename oder Claim" class="pl-8" />
                </div>

                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Status</p>
                    <x-foodalchemist::filter-row wire:click="waehleStatus('')" :active="$statusFilter === ''">Alle Status</x-foodalchemist::filter-row>
                    @foreach($statusLabel as $val => $lbl)
                        <x-foodalchemist::filter-row wire:key="fst-{{ $val }}" wire:click="waehleStatus('{{ $val }}')" :active="$statusFilter === $val">{{ $lbl }}</x-foodalchemist::filter-row>
                    @endforeach
                </div>

                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Herkunft</p>
                    <x-foodalchemist::filter-row wire:click="waehleOrigin('')" :active="$originFilter === ''">Jede Herkunft</x-foodalchemist::filter-row>
                    @foreach($originLabel as $val => $lbl)
                        <x-foodalchemist::filter-row wire:key="fori-{{ $val }}" wire:click="waehleOrigin('{{ $val }}')" :active="$originFilter === $val">
                            <span class="inline-flex items-center gap-1.5">{{ $lbl }}@if($val === 'kunde')@svg('heroicon-m-lock-closed', 'w-3.5 h-3.5 shrink-0')@endif</span>
                        </x-foodalchemist::filter-row>
                    @endforeach
                </div>

                {{-- F1: geteilte Concept-Dimensionen als Filter (aus den Einstellungen gepflegt). Eingeklappt, offen sobald gewählt. --}}
                <div class="flex flex-col gap-1 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Einsatz</p>
                    @foreach($facetten as [$titel, $wert, $feld, $vokabular, $labelFeld, $praefix])
                        @continue($vokabular->isEmpty())
                        @php
                            $gewaehlt = $vokabular->firstWhere('id', (int) $wert);
                        @endphp
                        <details class="group" @if($wert !== '') open @endif wire:key="ffgrp-{{ $feld }}" wire:ignore.self>
                            <summary class="flex items-center justify-between gap-2 h-8 px-2.5 rounded-[var(--fa-radius-control)] cursor-pointer select-none text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]">
                                <span class="inline-flex items-center gap-1.5 min-w-0">
                                    @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 transition-transform group-open:rotate-90')
                                    <span class="truncate {{ $gewaehlt ? 'font-semibold text-[var(--fa-ink)]' : '' }}">{{ $titel }}</span>
                                </span>
                                @if($gewaehlt)<x-fa::badge tone="accent" class="max-w-[9rem]"><span class="truncate" title="{{ $gewaehlt->{$labelFeld} }}">{{ $gewaehlt->{$labelFeld} }}</span></x-fa::badge>@endif
                            </summary>
                            <x-foodalchemist::filter-ast>
                                <x-foodalchemist::filter-row level="child" wire:click="waehleFacette('{{ $feld }}', '')" :active="$wert === ''">Alle</x-foodalchemist::filter-row>
                                @foreach($vokabular as $eintrag)
                                    <x-foodalchemist::filter-row level="child" wire:key="{{ $praefix }}-{{ $eintrag->id }}"
                                        wire:click="waehleFacette('{{ $feld }}', '{{ $eintrag->id }}')"
                                        :active="$wert === (string) $eintrag->id">{{ $eintrag->{$labelFeld} }}</x-foodalchemist::filter-row>
                                @endforeach
                            </x-foodalchemist::filter-ast>
                        </details>
                    @endforeach
                </div>

                @if($filterAktiv)
                    <div class="pt-2 border-t border-[var(--fa-line)]">
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-x-mark" wire:click="filterZuruecksetzen" class="w-full">Filter zurücksetzen</x-fa::button>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Detail-Spalte erst, wenn ein Format gewählt ist (vorher nahm der leere Hinweis Platz der Tabelle weg). --}}
    @if($selectedId !== null)
        <x-slot name="activity">
            <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="640" scope="activity_formate" side="right">
                <livewire:foodalchemist.formate.detail-panel :selected-id="$selectedId" />
            </x-foodalchemist::detail-sidebar>
        </x-slot>
    @endif

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Formate" :subtitle="number_format($items->total(), 0, ',', '.') . ($items->total() === 1 ? ' Format' : ' Formate')">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu">Neues Format</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <div class="fa-surface overflow-hidden">
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            <th>Eventtyp und Servierform</th>
                            <th>Herkunft</th>
                            <th class="num">Editionen</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            <x-foodalchemist::table-row :active="$selectedId === $it->id" wire:key="frow-{{ $it->id }}" wire:click="waehle({{ $it->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_formate', 'open', true)">
                                {{-- Namens-Klick öffnet den Editor, Zeilen-Klick nur das Detail --}}
                                <td class="min-w-[10rem]">
                                    <button type="button" wire:click.stop="bearbeite({{ $it->id }})" title="{{ $it->name }} bearbeiten"
                                            class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline">{{ $it->name }}</button>
                                    @if($it->consumer_name && $it->consumer_name !== $it->name)
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Für Gäste: {{ $it->consumer_name }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ collect([$it->eventType?->name, $it->servingForm?->label])->filter()->join(' · ') ?: '–' }}</td>
                                <td class="whitespace-nowrap">
                                    @if($it->origin === 'kunde')
                                        <x-fa::badge tone="warn" icon="heroicon-m-lock-closed" title="Kundeneigenes Format, nicht für andere Kunden verwenden">Kunde</x-fa::badge>
                                    @elseif($it->origin)
                                        <x-fa::badge>{{ $originLabel[$it->origin] ?? $it->origin }}</x-fa::badge>
                                    @else
                                        <span class="text-[var(--fa-ink-3)]">–</span>
                                    @endif
                                </td>
                                <td class="num {{ $it->editions_count === 0 ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink-2)]' }}">{{ $it->editions_count }}</td>
                                {{-- Status als Chip, Änderung über ein kleines Menü --}}
                                <td class="whitespace-nowrap" wire:click.stop @click.stop>
                                    <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="fstsel-{{ $it->id }}-{{ $it->status }}">
                                        <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status von {{ $it->name }} ändern">
                                            <x-fa::badge :tone="$statusTon[$it->status] ?? 'neutral'" :icon="$statusIcon[$it->status] ?? null">{{ $statusLabel[$it->status] ?? $it->status }}</x-fa::badge>@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                        </button>
                                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-40 fa-surface shadow-lg py-1">
                                            @foreach($statusLabel as $val => $lbl)
                                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="statusSetzen({{ $it->id }}, '{{ $val }}')"
                                                        class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $it->status === $val ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                                    {{ $lbl }}@if($it->status === $val)@svg('heroicon-m-check', 'w-4 h-4')@endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr>
                                <td colspan="5">
                                    @if($filterAktiv)
                                        <x-fa::empty icon="heroicon-o-rectangle-group" title="Kein Format passt zu den Filtern">Filter links zurücksetzen oder die Suche ändern.</x-fa::empty>
                                    @else
                                        <x-fa::empty icon="heroicon-o-rectangle-group" title="Noch keine Formate">Ein Format bündelt mehrere Konzepte als Editionen unter einer Marke. Oben rechts „Neues Format“ anlegen.</x-fa::empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $items->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>

    {{-- Voll-Editor-Modal — auf Seitenebene, öffnet via formate-editor.oeffnen --}}
    <livewire:foodalchemist.formate.editor />
</x-ui-page>
