{{-- M1-03: Warengruppen — Master-Detail (WG-Liste links, Sub-Kategorien-Tabelle rechts), §3-Codes fix
     fa-pass 2026-10-05: Bausteine/Tokens, Anordnung wie bisher (Liste links, Tabelle rechts).
     Häufigste Aufgabe = Warengruppe wählen und ihre Unterkategorien ordnen/benennen.
     Liste und Tabelle stehen nebeneinander, solange Platz ist, sonst untereinander (flex-wrap).
     Umbenennen/Löschen der Warengruppe als Symbolknöpfe an der Zeile; das Leeren einer Unterkategorie
     steht im Menü „Weitere Aktionen" (rot, abgesetzt). --}}
@php
    $gewaehlt = $warengruppen->firstWhere('code', $subWg);
    $zeileBasis = 'group flex items-center gap-1 min-h-9 pr-1 rounded-[var(--fa-radius-control)] transition-colors';
@endphp

<div class="flex flex-col gap-4">
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif

    <div class="flex flex-wrap gap-4 items-start">
        {{-- Warengruppen links --}}
        <x-fa::section title="Warengruppen" :meta="$warengruppen->count()" class="w-80 max-w-full shrink-0" data-warengruppen-liste x-data="{ dragId: null }">
            <div class="flex gap-1.5" data-wg-neu>
                <label for="wg-neu" class="sr-only">Eigene Warengruppe anlegen</label>
                <x-fa::input id="wg-neu" size="sm" wire:model="neuWg" wire:keydown.enter="wgNeu" placeholder="Eigene Warengruppe" class="flex-1 min-w-0" />
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="wgNeu" title="Eigene Warengruppe anlegen. Die Standard-Warengruppen sind nur eine Empfehlung.">Anlegen</x-fa::button>
            </div>

            <div class="flex flex-col gap-0.5 -mx-1">
                @foreach($warengruppen as $wg)
                    @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $wg))
                    @php($istParagraf3 = in_array($wg->code, $paragraf3, true))
                    @php($aktiv = $subWg === $wg->code)
                    <div wire:key="wg-{{ $wg->id }}" class="{{ $zeileBasis }} {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                         @dragover.prevent
                         @drop.prevent="if (dragId !== null && dragId !== {{ $wg->id }}) $wire.wgVerschieben(dragId, {{ $wg->id }}); dragId = null"
                         :class="{ 'ring-1 ring-inset ring-[var(--fa-accent-line)]': dragId !== null && dragId !== {{ $wg->id }} }">
                        @if($editId === $wg->id)
                            <span class="pl-2 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $wg->code }}</span>
                            <x-fa::input size="sm" wire:model="editName" wire:keydown.enter="saveName" wire:keydown.escape="$set('editId', null)" aria-label="Name der Warengruppe" class="flex-1 min-w-0" autofocus />
                            <x-fa::button size="sm" variant="primary" wire:click="saveName">Speichern</x-fa::button>
                        @else
                            <span class="shrink-0 pl-0.5">@include('foodalchemist::livewire.settings.partials.reorder-cell', ['id' => $wg->id, 'upMethod' => 'wgHoch', 'downMethod' => 'wgRunter', 'first' => $loop->first, 'last' => $loop->last])</span>
                            <a href="#" role="button" wire:click.prevent="waehleWg('{{ $wg->code }}')" @if($aktiv) aria-current="true" @endif
                                    class="flex-1 min-w-0 flex items-center gap-1.5 text-left px-1.5 py-1.5 text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium' : '' }}">
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $wg->code }}</span>
                                <span class="min-w-0 truncate" title="{{ $wg->name }}">{{ $wg->name }}</span>
                                @if($istParagraf3)<x-fa::badge class="shrink-0" title="Standard-Warengruppe aus dem Regelwerk, frei änderbar">Standard</x-fa::badge>@endif
                            </a>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" title="Grundprodukte">{{ number_format($wg->gp_count, 0, ',', '.') }}</span>
                            @if($darfEdit)
                                <span class="shrink-0 flex opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                                    <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Warengruppe umbenennen" wire:click="startEditName({{ $wg->id }}, @js($wg->name))" />
                                    @if($wg->gp_count > 0)
                                        <x-fa::icon-button size="sm" icon="heroicon-o-trash" label="Wird von Grundprodukten genutzt, erst umhängen" disabled class="opacity-40 cursor-not-allowed" />
                                    @else
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Warengruppe löschen" wire:click="deleteWg({{ $wg->id }})" wire:confirm="Diese Warengruppe löschen?" />
                                    @endif
                                </span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </x-fa::section>

        {{-- Unterkategorien rechts --}}
        <x-fa::section title="Unterkategorien" :meta="$gewaehlt?->name" class="flex-1 basis-[24rem] min-w-0" data-subkat x-data="{ dragIdx: null }"
            description="Feste Liste je Warengruppe, ergänzt um Werte, die schon an Grundprodukten stehen. Umbenennen gilt für alle eigenen Grundprodukte, geerbte bleiben unberührt.">
            <div class="flex gap-1.5">
                <label for="sub-neu" class="sr-only">Neue Unterkategorie</label>
                <x-fa::input id="sub-neu" wire:model="neuSub" wire:keydown.enter="addSub" placeholder="{{ $subWg === '' ? 'Erst links eine Warengruppe wählen' : 'Neue Unterkategorie' }}" class="flex-1 min-w-0" :disabled="$subWg === ''" />
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="addSub" :disabled="$subWg === ''">Unterkategorie anlegen</x-fa::button>
            </div>

            <div class="overflow-x-auto -mx-4">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th class="w-px"><span class="sr-only">Reihenfolge</span></th>
                            <th class="w-full">Unterkategorie</th>
                            <th class="num">Grundprodukte</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($subKategorien as $sub)
                            <tr wire:key="sub-{{ md5($sub->sub_category) }}"
                                @dragover.prevent
                                @drop.prevent="if (dragIdx !== null && dragIdx !== {{ $loop->index }}) $wire.subVerschieben(dragIdx, {{ $loop->index }}); dragIdx = null"
                                :class="{ 'ring-1 ring-inset ring-[var(--fa-accent-line)]': dragIdx !== null && dragIdx !== {{ $loop->index }} }">
                                {{-- Umsortieren: Ziehgriff (Drag-and-Drop) + Hoch/Runter als zuverlässige Alternative --}}
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center gap-0.5 align-middle">
                                        <span class="inline-flex items-center justify-center w-5 h-6 cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] select-none"
                                              draggable="true"
                                              @dragstart="dragIdx = {{ $loop->index }}; $event.dataTransfer.effectAllowed = 'move'"
                                              @dragend="dragIdx = null"
                                              title="Ziehen zum Umsortieren" aria-hidden="true">@svg('heroicon-m-bars-3', 'w-4 h-4')</span>
                                        <span class="inline-flex flex-col">
                                            <button type="button" wire:click="subHoch({{ $loop->index }})" @disabled($loop->first)
                                                    class="inline-flex items-center justify-center w-5 h-3.5 rounded-sm {{ $loop->first ? 'text-[var(--fa-line-strong)] cursor-not-allowed' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]' }}"
                                                    title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
                                            <button type="button" wire:click="subRunter({{ $loop->index }})" @disabled($loop->last)
                                                    class="inline-flex items-center justify-center w-5 h-3.5 rounded-sm {{ $loop->last ? 'text-[var(--fa-line-strong)] cursor-not-allowed' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]' }}"
                                                    title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
                                        </span>
                                    </span>
                                </td>
                                @if($renameAlt === $sub->sub_category)
                                    <td><x-fa::input size="sm" wire:model="renameNeu" wire:keydown.enter="rename" wire:keydown.escape="$set('renameAlt', null)" aria-label="Neuer Name" class="w-full min-w-40" autofocus /></td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ number_format($sub->n, 0, ',', '.') }}</td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <x-fa::button size="sm" variant="ghost" wire:click="$set('renameAlt', null)">Abbrechen</x-fa::button>
                                            <x-fa::button size="sm" variant="primary" wire:click="rename">Umbenennen</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td>{{ $sub->sub_category }}</td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ number_format($sub->n, 0, ',', '.') }}</td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil" wire:click="startRename('{{ addslashes($sub->sub_category) }}')">Umbenennen</x-fa::button>
                                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $sub->sub_category }}"
                                                    x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-72 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false"
                                                            wire:click="clearWert('{{ addslashes($sub->sub_category) }}')" wire:confirm="„{{ $sub->sub_category }}“ bei allen eigenen Grundprodukten entfernen?"
                                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                                        @svg('heroicon-o-x-circle', 'w-4 h-4 shrink-0') Bei allen eigenen Grundprodukten entfernen
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    @if($subWg === '')
                                        <x-fa::empty compact icon="heroicon-o-cursor-arrow-rays" title="Keine Warengruppe gewählt">Links eine Warengruppe wählen, um ihre Unterkategorien zu sehen.</x-fa::empty>
                                    @else
                                        <x-fa::empty compact icon="heroicon-o-tag" title="Keine Unterkategorien in dieser Warengruppe">Oben die erste Unterkategorie anlegen.</x-fa::empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-fa::section>
    </div>
    </fieldset>
</div>
