{{-- M1-04: Rezept-Taxonomie — HG-Baum links, Kategorien rechts (M4 liest dieselben Service-Methoden)
     fa-pass 2026-10-05: Bausteine/Tokens, Anordnung wie bisher (Hauptgruppen links, Kategorien und
     „Neue Kategorie" rechts). Häufigste Aufgabe = Kategorien einer Hauptgruppe pflegen und ordnen.
     Löschen im Menü „Weitere Aktionen" (rot), gesperrt solange Rezepte daran hängen. --}}
@php
    $gewaehlt = $hauptgruppen->firstWhere('id', $hauptgruppeId);
    $zeileBasis = 'group flex items-center gap-1 min-h-9 pr-1 rounded-[var(--fa-radius-control)] transition-colors';
@endphp

<div class="flex flex-col gap-4">
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

    <div class="flex flex-wrap gap-4 items-start">
        {{-- Hauptgruppen --}}
        <x-fa::section title="Hauptgruppen" :meta="$hauptgruppen->count()" class="w-72 max-w-full shrink-0" data-taxonomie-hg x-data="{ dragId: null }">
            <div class="flex flex-col gap-0.5 -mx-1">
                @foreach($hauptgruppen as $hg)
                    @php($darfEditHg = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $hg))
                    @php($aktiv = $hauptgruppeId === $hg->id)
                    <div wire:key="hg-{{ $hg->id }}" class="{{ $zeileBasis }} {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                         @dragover.prevent
                         @drop.prevent="if (dragId !== null && dragId !== {{ $hg->id }}) $wire.hgVerschieben(dragId, {{ $hg->id }}); dragId = null"
                         :class="{ 'ring-1 ring-inset ring-[var(--fa-accent-line)]': dragId !== null && dragId !== {{ $hg->id }} }">
                        @if($hgEditId === $hg->id)
                            <x-fa::input size="sm" wire:model="hgEditName" wire:keydown.enter="hgSave" wire:keydown.escape="$set('hgEditId', null)" aria-label="Name der Hauptgruppe" class="flex-1 min-w-0 ml-1" autofocus />
                            <x-fa::button size="sm" variant="primary" wire:click="hgSave">Speichern</x-fa::button>
                        @else
                            <span class="shrink-0 pl-0.5">@include('foodalchemist::livewire.settings.partials.reorder-cell', ['id' => $hg->id, 'upMethod' => 'hgHoch', 'downMethod' => 'hgRunter', 'first' => $loop->first, 'last' => $loop->last])</span>
                            <button type="button" wire:click="waehleHg({{ $hg->id }})" @if($aktiv) aria-current="true" @endif
                                    class="flex-1 min-w-0 truncate text-left px-1.5 py-1.5 text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium' : '' }}" title="{{ $hg->label }}">{{ $hg->label }}</button>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" title="Kategorien">{{ $hg->kategorie_count }}</span>
                            @if($darfEditHg)
                                <span class="shrink-0 flex opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                                    <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Hauptgruppe umbenennen" wire:click="startHgEdit({{ $hg->id }}, @js($hg->label))" />
                                    @if($hg->kategorie_count > 0)
                                        <x-fa::icon-button size="sm" icon="heroicon-o-trash" label="Hat Kategorien, erst dort entfernen" disabled class="opacity-40 cursor-not-allowed" />
                                    @else
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Hauptgruppe löschen" wire:click="hgDelete({{ $hg->id }})" wire:confirm="Diese Hauptgruppe löschen?" />
                                    @endif
                                </span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-taxonomie-hg-neu>
                <label for="hg-neu" class="sr-only">Neue Hauptgruppe</label>
                <x-fa::input id="hg-neu" size="sm" wire:model="neueHauptgruppe" wire:keydown.enter="hgNeu" placeholder="Neue Hauptgruppe" class="flex-1 min-w-0" />
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="hgNeu" title="Hauptgruppe anlegen">Anlegen</x-fa::button>
            </div>
        </x-fa::section>

        {{-- Kategorien der gewählten HG --}}
        <div class="flex-1 basis-[26rem] min-w-0 flex flex-col gap-4">
            <x-fa::section title="Kategorien" :meta="$gewaehlt ? $gewaehlt->label . ' · ' . $kategorien->count() . ' Kategorien' : null" data-taxonomie-kategorien x-data="{ dragId: null }">
                <div class="overflow-x-auto -mx-4">
                    <table class="fa-table">
                        <thead>
                            <tr>
                                <th class="w-px"><span class="sr-only">Reihenfolge</span></th>
                                <th class="w-full">Bezeichnung</th>
                                <th>Technik</th>
                                <th class="num">Position</th>
                                <th class="num">Rezepte</th>
                                <th><span class="sr-only">Aktionen</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($kategorien as $kat)
                                @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $kat))
                                <tr wire:key="kat-{{ $kat->id }}"
                                    @dragover.prevent
                                    @drop.prevent="if (dragId !== null && dragId !== {{ $kat->id }}) $wire.katVerschieben(dragId, {{ $kat->id }}); dragId = null"
                                    :class="{ 'ring-1 ring-inset ring-[var(--fa-accent-line)]': dragId !== null && dragId !== {{ $kat->id }} }">
                                    <td class="whitespace-nowrap">@include('foodalchemist::livewire.settings.partials.reorder-cell', ['id' => $kat->id, 'upMethod' => 'katHoch', 'downMethod' => 'katRunter', 'first' => $loop->first, 'last' => $loop->last])</td>
                                    @if($editId === $kat->id)
                                        <td><x-fa::input size="sm" wire:model="form.label" wire:keydown.enter="save" aria-label="Bezeichnung" class="w-full min-w-40" /></td>
                                        <td><x-fa::input size="sm" wire:model="form.technik" wire:keydown.enter="save" aria-label="Technik" class="w-36" /></td>
                                        <td class="num"><x-fa::input size="sm" type="number" numeric wire:model="form.sort_order" aria-label="Position" class="w-20" /></td>
                                        <td></td>
                                        <td class="whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-fa::button size="sm" variant="ghost" wire:click="$set('editId', null)">Abbrechen</x-fa::button>
                                                <x-fa::button size="sm" variant="primary" wire:click="save">Speichern</x-fa::button>
                                            </div>
                                        </td>
                                    @else
                                        <td class="font-medium">{{ $kat->label }}</td>
                                        <td class="text-[var(--fa-ink-2)]">{{ $kat->technik ?? '' }}</td>
                                        <td class="num text-[var(--fa-ink-2)]">{{ $kat->sort_order }}</td>
                                        <td class="num text-[var(--fa-ink-2)]">{{ number_format($kat->recipe_count, 0, ',', '.') }}</td>
                                        <td class="whitespace-nowrap">
                                            @if($darfEdit)
                                                <div class="flex items-center justify-end gap-1">
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $kat->id }})">Bearbeiten</x-fa::button>
                                                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                        <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $kat->label }}"
                                                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                        <div class="hidden w-64 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                            @if($kat->recipe_count > 0)
                                                                <p class="flex items-start gap-2 px-3 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                                                                    @svg('heroicon-o-lock-closed', 'w-4 h-4 shrink-0 mt-px') Löschen gesperrt: {{ $kat->recipe_count }} Rezepte hängen daran. Erst zusammenführen oder umhängen.
                                                                </p>
                                                            @else
                                                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $kat->id }})"
                                                                        wire:confirm="Kategorie „{{ $kat->label }}“ löschen?"
                                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                                                    @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Kategorie löschen
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <x-fa::empty compact icon="heroicon-o-folder-open" title="{{ $gewaehlt ? 'Noch keine Kategorien in dieser Hauptgruppe' : 'Keine Hauptgruppe gewählt' }}">
                                            {{ $gewaehlt ? 'Unten die erste Kategorie anlegen.' : 'Links eine Hauptgruppe wählen oder anlegen.' }}
                                        </x-fa::empty>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-fa::section>

            <x-fa::section title="Neue Kategorie" :meta="$gewaehlt ? 'in ' . $gewaehlt->label : null" data-taxonomie-neu>
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Bezeichnung" for="kat-neu-label" class="flex-1 min-w-48">
                        <x-fa::input id="kat-neu-label" wire:model="neu.label" placeholder="z. B. Helle Fonds" />
                    </x-fa::field>
                    <x-fa::field label="Technik" for="kat-neu-technik" optional class="w-44">
                        <x-fa::input id="kat-neu-technik" wire:model="neu.technik" />
                    </x-fa::field>
                    <x-fa::field label="Position" for="kat-neu-sort" class="w-24">
                        <x-fa::input id="kat-neu-sort" type="number" numeric wire:model="neu.sort_order" />
                    </x-fa::field>
                    <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create">Kategorie anlegen</x-fa::button>
                </div>
            </x-fa::section>
        </div>
    </div>
</div>
