{{-- R5: Schreibstile — eigene Seite, CRUD (sprach_duktus = Material für den KI-Auftrag, GL-06).
     Häufigste Aufgabe: einen bestehenden Stil nachschärfen. Darum steht die Liste oben, Anlegen darunter. --}}
@php
    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
@endphp

<div class="flex flex-col gap-5" data-settings-schreibstile>
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-stil-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::section title="Schreibstile" :meta="$stile->count() > 0 ? $stile->count() . ' ' . ($stile->count() === 1 ? 'Stil' : 'Stile') : null"
                   description="Der Sprachstil und die Beispiele gehen in jeden KI-Text, der mit diesem Stil geschrieben wird. Die Beschreibung bleibt intern.">
        @if($stile->isEmpty())
            <x-fa::empty icon="heroicon-o-pencil-square" title="Noch kein Schreibstil angelegt">
                Lege unten den ersten Stil an, zum Beispiel „Rustikal“ oder „Fine Dining“. Danach steht er bei jedem KI-Text zur Auswahl.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table min-w-[720px]" data-stil-tabelle>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Sprachstil</th>
                            <th>Beschreibung</th>
                            <th class="num">Reihenfolge</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stile as $stil)
                            <tr class="{{ $stil->is_inactive ? 'opacity-60' : '' }}" wire:key="stil-{{ $stil->id }}">
                                @if($editId === $stil->id)
                                    <td colspan="5" class="bg-[var(--fa-accent-soft)]">
                                        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] py-1">
                                            <x-fa::field label="Name" for="stil-edit-name-{{ $stil->id }}">
                                                <x-fa::input id="stil-edit-name-{{ $stil->id }}" wire:model="form.name" />
                                            </x-fa::field>
                                            <x-fa::field label="Reihenfolge" for="stil-edit-sort-{{ $stil->id }}" hint="Kleinere Zahl steht weiter oben.">
                                                <x-fa::input id="stil-edit-sort-{{ $stil->id }}" wire:model="form.sort_order" numeric class="max-w-[8rem]" />
                                            </x-fa::field>
                                            <x-fa::field label="Sprachstil" for="stil-edit-duktus-{{ $stil->id }}" hint="So soll die KI klingen. Geht direkt in den KI-Auftrag.">
                                                <x-fa::textarea id="stil-edit-duktus-{{ $stil->id }}" wire:model="form.sprach_duktus" rows="3" placeholder="z. B. bodenständig, warm, kurze Sätze" />
                                            </x-fa::field>
                                            {{-- Beispiel-Wordings gehen als schreibstil_beispiele mit in den KI-Auftrag (anders als die Beschreibung). --}}
                                            <x-fa::field label="Beispiel-Formulierungen" for="stil-edit-beispiele-{{ $stil->id }}" hint="Je Zeile ein Beispiel. Die KI orientiert sich daran.">
                                                <x-fa::textarea id="stil-edit-beispiele-{{ $stil->id }}" wire:model="form.beispiele_md" rows="3" />
                                            </x-fa::field>
                                            <x-fa::field label="Beschreibung" for="stil-edit-beschreibung-{{ $stil->id }}" optional hint="Nur interner Hinweis, die KI sieht ihn nicht.">
                                                <x-fa::input id="stil-edit-beschreibung-{{ $stil->id }}" wire:model="form.description" />
                                            </x-fa::field>
                                        </div>
                                        <div class="flex justify-end gap-2 pt-3">
                                            <x-fa::button variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="save" data-stil-save>Schreibstil speichern</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td class="align-top">
                                        <span class="font-medium text-[var(--fa-ink)]">{{ $stil->name }}</span>
                                        @if($stil->is_inactive)<x-fa::badge class="ml-1.5">Inaktiv</x-fa::badge>@endif
                                    </td>
                                    <td class="align-top text-[var(--fa-ink-2)] max-w-[28rem]">
                                        <span class="line-clamp-2" title="{{ $stil->sprach_duktus }}">{{ $stil->sprach_duktus }}</span>
                                    </td>
                                    <td class="align-top text-[var(--fa-ink-3)] max-w-[16rem]">
                                        <span class="line-clamp-2">{{ $stil->description ?: '–' }}</span>
                                    </td>
                                    <td class="num align-top text-[var(--fa-ink-2)]">{{ $stil->sort_order }}</td>
                                    <td class="align-top">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="edit({{ $stil->id }})" data-stil-edit>Bearbeiten</x-fa::button>
                                            <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $stil->name }}" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-52 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="toggleInactive({{ $stil->id }})" class="{{ $menuPunkt }}">
                                                        @svg($stil->is_inactive ? 'heroicon-m-eye' : 'heroicon-m-eye-slash', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                                        {{ $stil->is_inactive ? 'Schreibstil aktivieren' : 'Schreibstil deaktivieren' }}
                                                    </button>
                                                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $stil->id }})" wire:confirm="Schreibstil „{{ $stil->name }}“ löschen? Das geht nur, solange ihn nichts verwendet." class="{{ $menuGefahr }}">
                                                        @svg('heroicon-m-trash', 'w-4 h-4')
                                                        Schreibstil löschen
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
    <x-fa::section title="Neuer Schreibstil" icon="heroicon-o-plus-circle" data-stil-anlegen>
        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))]">
            <x-fa::field label="Name" for="stil-neu-name" required>
                <x-fa::input id="stil-neu-name" wire:model="neu.name" placeholder="z. B. Rustikal" data-stil-neu-name />
            </x-fa::field>
            <x-fa::field label="Beschreibung" for="stil-neu-beschreibung" optional hint="Nur interner Hinweis, die KI sieht ihn nicht.">
                <x-fa::input id="stil-neu-beschreibung" wire:model="neu.description" />
            </x-fa::field>
            <x-fa::field label="Sprachstil" for="stil-neu-duktus" required hint="So soll die KI klingen. Geht direkt in den KI-Auftrag.">
                <x-fa::textarea id="stil-neu-duktus" wire:model="neu.sprach_duktus" rows="3" placeholder="z. B. bodenständig, warm, kurze Sätze" />
            </x-fa::field>
            <x-fa::field label="Beispiel-Formulierungen" for="stil-neu-beispiele" optional hint="Je Zeile ein Beispiel. Die KI orientiert sich daran.">
                <x-fa::textarea id="stil-neu-beispiele" wire:model="neu.beispiele_md" rows="3" />
            </x-fa::field>
        </div>
        <div class="flex justify-end">
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-stil-neu-anlegen>Schreibstil anlegen</x-fa::button>
        </div>
    </x-fa::section>
</div>
