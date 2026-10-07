{{-- #469: Wissens-Kategorien — pflegbares Vokabular (Klassifikation + grobe Routing-Ebene).
     Häufigste Aufgabe: Kategorie umbenennen oder beschreiben. Liste oben, Anlegen darunter. --}}
@php
    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
@endphp

<div class="flex flex-col gap-5" data-settings-wissenskategorien>
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-kat-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::section title="Kategorien" :meta="$kategorien->count() > 0 ? $kategorien->count() . ' ' . ($kategorien->count() === 1 ? 'Kategorie' : 'Kategorien') : null"
                   description="Jedes Wissens-Dossier gehört zu genau einer Kategorie. Über die Kategorie steuert die Wissens-Steuerung grob, welcher Arbeitsschritt welches Wissen bekommt.">
        @if($kategorien->isEmpty())
            <x-fa::empty icon="heroicon-o-folder" title="Noch keine Kategorie angelegt">
                Lege unten die erste Kategorie an, zum Beispiel „Regelwerke“ oder „Warenkunde“.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table min-w-[680px]" data-kat-tabelle>
                    <thead>
                        <tr>
                            <th>Kategorie</th>
                            <th>Beschreibung</th>
                            <th class="num">Dossiers</th>
                            <th class="num">Reihenfolge</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($kategorien as $kat)
                            @php
                                $anzahlDocs = (int) ($docCounts[$kat->slug] ?? 0);
                            @endphp
                            <tr class="{{ $kat->active ? '' : 'opacity-60' }}" wire:key="kat-{{ $kat->id }}">
                                @if($editId === $kat->id)
                                    <td colspan="5" class="bg-[var(--fa-accent-soft)]">
                                        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] py-1">
                                            <x-fa::field label="Name" for="kat-edit-label-{{ $kat->id }}">
                                                <x-fa::input id="kat-edit-label-{{ $kat->id }}" wire:model="form.label" />
                                            </x-fa::field>
                                            <x-fa::field label="Beschreibung" for="kat-edit-beschreibung-{{ $kat->id }}" optional>
                                                <x-fa::input id="kat-edit-beschreibung-{{ $kat->id }}" wire:model="form.description" />
                                            </x-fa::field>
                                            <x-fa::field label="Reihenfolge" for="kat-edit-sort-{{ $kat->id }}" hint="Kleinere Zahl steht weiter oben.">
                                                <x-fa::input id="kat-edit-sort-{{ $kat->id }}" wire:model="form.sort_order" numeric class="max-w-[8rem]" />
                                            </x-fa::field>
                                        </div>
                                        <div class="flex justify-end gap-2 pt-3">
                                            <x-fa::button variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="save" data-kat-save>Kategorie speichern</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td class="align-top">
                                        <span class="font-medium text-[var(--fa-ink)]" title="Kennung: {{ $kat->slug }}">{{ $kat->label }}</span>
                                        @unless($kat->active)<x-fa::badge class="ml-1.5">Inaktiv</x-fa::badge>@endunless
                                    </td>
                                    <td class="align-top text-[var(--fa-ink-2)] max-w-[24rem]">
                                        <span class="line-clamp-2">{{ $kat->description ?: '–' }}</span>
                                    </td>
                                    <td class="num align-top {{ $anzahlDocs === 0 ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink)]' }}">{{ number_format($anzahlDocs, 0, ',', '.') }}</td>
                                    <td class="num align-top text-[var(--fa-ink-2)]">{{ $kat->sort_order }}</td>
                                    <td class="align-top">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="edit({{ $kat->id }})" data-kat-edit>Bearbeiten</x-fa::button>
                                            <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $kat->label }}" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="toggleActive({{ $kat->id }})" class="{{ $menuPunkt }}">
                                                        @svg($kat->active ? 'heroicon-m-eye-slash' : 'heroicon-m-eye', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                                        {{ $kat->active ? 'Kategorie deaktivieren' : 'Kategorie aktivieren' }}
                                                    </button>
                                                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $kat->id }})"
                                                            wire:confirm="Kategorie „{{ $kat->label }}“ und {{ $anzahlDocs }} {{ $anzahlDocs === 1 ? 'Dossier' : 'Dossiers' }} endgültig löschen? Das lässt sich nicht rückgängig machen."
                                                            class="{{ $menuGefahr }}">
                                                        @svg('heroicon-m-trash', 'w-4 h-4')
                                                        Kategorie samt Dossiers löschen
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>

    {{-- Anlegen --}}
    <x-fa::section title="Neue Kategorie" icon="heroicon-o-plus-circle" data-kat-anlegen>
        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))]">
            <x-fa::field label="Name" for="kat-neu-label" required hint="Die interne Kennung entsteht automatisch aus dem Namen.">
                <x-fa::input id="kat-neu-label" wire:model="neu.label" placeholder="z. B. Regelwerke" data-kat-neu-label />
            </x-fa::field>
            <x-fa::field label="Beschreibung" for="kat-neu-beschreibung" optional>
                <x-fa::input id="kat-neu-beschreibung" wire:model="neu.description" />
            </x-fa::field>
        </div>
        <div class="flex justify-end">
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-kat-neu-anlegen>Kategorie anlegen</x-fa::button>
        </div>
    </x-fa::section>
</div>
