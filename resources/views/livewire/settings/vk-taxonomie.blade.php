{{-- D-6 §4.6: VK-Taxonomie — Master-Detail (Speisen-HG links, Klassen-Tabelle rechts); HG + Klassen anlegbar (#372)
     fa-pass 2026-10-05: Bausteine/Tokens, Anordnung wie bisher (Speisen-Hauptgruppen links, Diätformen rechts).
     Häufigste Aufgabe = Speisen-Hauptgruppen anlegen, benennen und ordnen. Der Zähler links zählt Gerichte.
     Die vier Diätformen gelten für alle Hauptgruppen; gewählt wird sie am Gericht. --}}
@php
    $zeileBasis = 'group flex items-center gap-1 min-h-9 pr-1 rounded-[var(--fa-radius-control)] transition-colors';
    $merkmale = fn ($k) => collect(['vegan' => $k->is_vegan, 'vegetarisch' => $k->is_vegi, 'halal' => $k->is_halal, 'koscher' => $k->is_koscher])->filter()->keys();
@endphp

<div class="flex flex-col gap-4" data-settings-vk-taxonomie>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($meldung !== null)<x-fa::notice tone="ok" data-taxo-meldung>{{ $meldung }}</x-fa::notice>@endif
    @if($fehler !== null)<x-fa::notice tone="crit" data-taxo-fehler>{{ $fehler }}</x-fa::notice>@endif

    <div class="flex flex-wrap gap-4 items-start">
        {{-- Speisen-Hauptgruppen links --}}
        <x-fa::section title="Speisen-Hauptgruppen" :meta="$hauptgruppen->count()" class="w-80 max-w-full shrink-0" data-taxo-hgs x-data="{ dragId: null }">
            <div class="flex flex-col gap-0.5 -mx-1">
                @foreach($hauptgruppen as $hg)
                    @php($darfEditHg = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $hg))
                    @php($nKl = $klassenJeHg[$hg->id] ?? 0)
                    @php($aktiv = $hauptgruppeId === $hg->id)
                    <div wire:key="thg-{{ $hg->id }}" class="{{ $zeileBasis }} {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }} {{ $hg->is_inactive ? 'opacity-60' : '' }}"
                         @dragover.prevent
                         @drop.prevent="if (dragId !== null && dragId !== {{ $hg->id }}) $wire.hgVerschieben(dragId, {{ $hg->id }}); dragId = null"
                         :class="{ 'ring-1 ring-inset ring-[var(--fa-accent-line)]': dragId !== null && dragId !== {{ $hg->id }} }">
                        @if($hgEditId === $hg->id)
                            <x-fa::input size="sm" wire:model="hgEditName" wire:keydown.enter="hgSave" wire:keydown.escape="$set('hgEditId', null)" aria-label="Name der Hauptgruppe" class="flex-1 min-w-0 ml-1" autofocus />
                            <x-fa::button size="sm" variant="primary" wire:click="hgSave">Speichern</x-fa::button>
                        @else
                            <span class="shrink-0 pl-0.5">@include('foodalchemist::livewire.settings.partials.reorder-cell', ['id' => $hg->id, 'upMethod' => 'hgHoch', 'downMethod' => 'hgRunter', 'first' => $loop->first, 'last' => $loop->last])</span>
                            <a href="#" role="button" wire:click.prevent="waehleHg({{ $hg->id }})" @if($aktiv) aria-current="true" @endif
                                    class="flex-1 min-w-0 flex items-center gap-1.5 text-left px-1.5 py-1.5 text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium' : '' }}">
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $hg->code }}</span>
                                <span class="min-w-0 truncate" title="{{ $hg->label }}">{{ $hg->label }}</span>
                                @if($hg->is_inactive)<x-fa::badge class="shrink-0">inaktiv</x-fa::badge>@endif
                            </a>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" title="Gerichte in dieser Hauptgruppe">{{ number_format($nKl, 0, ',', '.') }}</span>
                            @if($darfEditHg)
                                <span class="shrink-0 flex opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                                    <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Hauptgruppe umbenennen" wire:click="startHgEdit({{ $hg->id }}, @js($hg->label))" />
                                    @if($nKl > 0)
                                        <x-fa::icon-button size="sm" icon="heroicon-o-trash" label="Gerichte hängen daran, erst umhängen" disabled class="opacity-40 cursor-not-allowed" />
                                    @else
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Hauptgruppe löschen" wire:click="hgDelete({{ $hg->id }})" wire:confirm="Diese Hauptgruppe löschen?" />
                                    @endif
                                </span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                <label for="vk-hg-neu" class="sr-only">Neue Hauptgruppe</label>
                <x-fa::input id="vk-hg-neu" size="sm" wire:model="neuHg" wire:keydown.enter="createHg" placeholder="Neue Hauptgruppe" class="flex-1 min-w-0" />
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="createHg">Anlegen</x-fa::button>
            </div>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                Die Zahl rechts zählt die Gerichte je Hauptgruppe. Preisklassen, Schreibstile und Behälter haben eigene Seiten.
            </p>
        </x-fa::section>

        {{-- Klassen der gewählten HG rechts --}}
        <x-fa::section title="Diätformen" meta="gelten für alle Hauptgruppen" class="flex-1 basis-[26rem] min-w-0" data-taxo-klassen
            description="Fleisch, Fisch, Vegetarisch, Vegan. Die Diätform wird am Gericht gewählt.">
            @if($klassen->isNotEmpty())
                <div class="overflow-x-auto -mx-4">
                    <table class="fa-table">
                        <thead>
                            <tr>
                                <th class="w-full">Klasse</th>
                                <th>Diätform</th>
                                <th>Geeignet für</th>
                                <th class="num">Gerichte</th>
                                <th><span class="sr-only">Aktionen</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($klassen as $k)
                                @php($darfEditK = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $k))
                                @php($nRez = $klassenZaehler[$k->id] ?? 0)
                                <tr wire:key="tk-{{ $k->id }}">
                                    @if($klasseEditId === $k->id)
                                        <td colspan="3"><x-fa::input size="sm" wire:model="klasseEditName" wire:keydown.enter="klasseSave" wire:keydown.escape="$set('klasseEditId', null)" aria-label="Name der Klasse" class="w-full min-w-40" autofocus /></td>
                                        <td class="num text-[var(--fa-ink-2)]">{{ number_format($nRez, 0, ',', '.') }}</td>
                                        <td class="whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-fa::button size="sm" variant="ghost" wire:click="$set('klasseEditId', null)">Abbrechen</x-fa::button>
                                                <x-fa::button size="sm" variant="primary" wire:click="klasseSave">Speichern</x-fa::button>
                                            </div>
                                        </td>
                                    @else
                                        <td>
                                            <span class="font-medium">{{ $k->label }}</span>
                                            <span class="ml-1 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $k->code }}</span>
                                        </td>
                                        <td><x-fa::badge>{{ ucfirst((string) $k->diet_form) }}</x-fa::badge></td>
                                        <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] whitespace-nowrap">
                                            @php($m = $merkmale($k))
                                            @if($m->isEmpty())<span class="text-[var(--fa-ink-3)]">keine Angabe</span>@else{{ $m->implode(' · ') }}@endif
                                        </td>
                                        <td class="num text-[var(--fa-ink-2)]">{{ number_format($nRez, 0, ',', '.') }}</td>
                                        <td class="whitespace-nowrap">
                                            @if($darfEditK)
                                                <div class="flex items-center justify-end gap-1">
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil" wire:click="startKlasseEdit({{ $k->id }}, @js($k->label))">Umbenennen</x-fa::button>
                                                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                        <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $k->label }}"
                                                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                        <div class="hidden w-64 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                            @if($nRez > 0)
                                                                <p class="flex items-start gap-2 px-3 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                                                                    @svg('heroicon-o-lock-closed', 'w-4 h-4 shrink-0 mt-px') Löschen gesperrt: Gerichte nutzen diese Klasse.
                                                                </p>
                                                            @else
                                                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="klasseDelete({{ $k->id }})" wire:confirm="Diese Klasse löschen?"
                                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                                                    @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Klasse löschen
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-fa::empty compact icon="heroicon-o-squares-2x2" title="Keine Diätformen gefunden">Fleisch, Fisch, Vegetarisch und Vegan gehören zum Grundbestand und erscheinen hier, sobald er eingespielt ist.</x-fa::empty>
            @endif
        </x-fa::section>
    </div>
    </fieldset>
</div>
