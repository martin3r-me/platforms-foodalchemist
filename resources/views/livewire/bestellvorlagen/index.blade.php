{{-- Spec 68/73 · Bestellvorlagen: vorne die Liste (nach Kategorien), Vorlage im Editor. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Bestellvorlagen" icon="heroicon-o-document-duplicate" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Bestellvorlagen" subtitle="Wiederkehrende Bestellungen und Musterproduktionen. Grundprodukte wählen ihren Artikel beim Bestellen nach Strategie; Rezepte, Konzepte und Pakete werden über die Rezepturen in Bedarf aufgelöst." />

        @if($fehler)<x-fa::notice tone="crit" data-vorlagen-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

        <x-fa::section title="Neue Vorlage" icon="heroicon-o-plus-circle" data-vorlage-neu-sektion>
            <form wire:submit="anlegen" class="flex flex-wrap items-end gap-2">
                <x-fa::field label="Name" for="vn-name"><x-fa::input id="vn-name" wire:model="neuName" placeholder="z. B. Montag Molkerei" class="w-64" data-vorlage-neu /></x-fa::field>
                <x-fa::field label="Kategorie" for="vn-kat">
                    <x-fa::input id="vn-kat" wire:model="neuKategorie" list="vn-kat-liste" placeholder="optional" class="w-48" />
                    <datalist id="vn-kat-liste">@foreach($kategorien as $k)<option value="{{ $k }}"></option>@endforeach</datalist>
                </x-fa::field>
                <x-fa::button type="submit" variant="primary" icon="heroicon-m-plus">Anlegen</x-fa::button>
            </form>
        </x-fa::section>

        <x-fa::section title="Vorlagen" icon="heroicon-o-document-duplicate" :meta="$gesamt" data-vorlagen-liste>
            <div class="flex flex-wrap items-end gap-2">
                <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="suche" placeholder="Vorlage suchen …" class="w-64" aria-label="Vorlage suchen" />
                @if($kategorien !== [])
                    <x-fa::select size="sm" wire:model.live="kategorieFilter" :options="array_combine($kategorien, $kategorien)" placeholder="Alle Kategorien" class="w-48" aria-label="Kategorie" />
                @endif
            </div>
            @if($gruppen->isEmpty())
                <x-fa::empty compact icon="heroicon-o-document-duplicate" title="{{ $gesamt === 0 ? 'Noch keine Vorlagen' : 'Keine Vorlage passt' }}">Auch aus einer Bestellrunde oder Bestellung lässt sich eine Vorlage speichern.</x-fa::empty>
            @else
                <table class="fa-table fa-table--compact" data-vorlagen-tabelle>
                    <thead><tr><th>Vorlage</th><th class="text-right">Positionen</th><th>Bestelltag</th><th>Zuletzt bestellt</th><th></th></tr></thead>
                    <tbody>
                        @foreach($gruppen as $kategorie => $vorlagen)
                            <tr wire:key="kat-{{ md5($kategorie) }}" class="bg-[var(--fa-ground)]"><td colspan="5" class="font-semibold text-[var(--fa-ink-2)]">{{ $kategorie }} <span class="{{ $leise }} font-normal">· {{ $vorlagen->count() }}</span></td></tr>
                            @foreach($vorlagen as $v)
                                <tr wire:key="v-{{ $v->id }}" class="cursor-pointer hover:bg-[var(--fa-hover)]" wire:click="oeffnen({{ $v->id }})" data-vorlage-zeile="{{ $v->id }}">
                                    <td class="font-medium">{{ $v->name }}@if($v->note)<div class="{{ $leise }}">{{ $v->note }}</div>@endif</td>
                                    <td class="text-right tabular-nums">{{ $v->lines_count }}</td>
                                    <td>{{ $v->weekday ? $wochentage[$v->weekday] : '–' }}</td>
                                    <td class="tabular-nums">{{ $v->last_used_at?->format('d.m.Y') ?? '–' }}</td>
                                    <td class="text-right"><x-fa::button size="sm">Öffnen</x-fa::button></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fa::section>
    </x-ui-page-container>

    <livewire:foodalchemist.bestellvorlagen.editor key="vorlage-editor" />
    {{-- Bestellrunde (derselbe Werkbank-Editor wie unter Bestellungen) --}}
    <livewire:foodalchemist.orders.editor key="vorlagen-orders-editor" />
</x-ui-page>
