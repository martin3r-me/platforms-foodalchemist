{{-- Schnellstart-Vorlagen (Brief-Templates) verwalten. Angelegt im Planung-Editor (Snapshot);
     hier: eigene umbenennen/aktiv/löschen + kuratierte nur lesen. Auch per MCP (brief_templates.*).
     Häufigste Aufgabe: eigene Vorlage umbenennen oder ausblenden. Darum eigene zuerst. --}}
@php
    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $zeilenGruppen = [
        ['schluessel' => 'eigene', 'zeilen' => $eigene, 'bearbeitbar' => true, 'key' => 'bt'],
        ['schluessel' => 'globals', 'zeilen' => $globals, 'bearbeitbar' => $istMaster, 'key' => 'btg'],
    ];
@endphp

<div class="flex flex-col gap-5" data-settings-brief-vorlagen>
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::notice tone="info">
        Vorlagen entstehen in der <strong>Planung</strong> über „Als Vorlage speichern“. Sie nehmen den Auftrag, den Kreativ-Modus
        und alle Leitplanken mit. Hier benennst du sie um, schaltest sie aktiv oder inaktiv oder löschst sie.
    </x-fa::notice>

    @foreach($zeilenGruppen as $gruppe)
        @php
            $istEigene = $gruppe['schluessel'] === 'eigene';
            $zeilen = $gruppe['zeilen'];
            $bearbeitbar = $gruppe['bearbeitbar'];
        @endphp
        <x-fa::section :title="$istEigene ? 'Eigene Vorlagen' : 'Kuratierte Vorlagen'"
                       :meta="$zeilen->count() > 0 ? $zeilen->count() . ' ' . ($zeilen->count() === 1 ? 'Vorlage' : 'Vorlagen') : null"
                       :description="$istEigene ? 'Vorlagen deines Teams.' : ($istMaster ? 'Mitgelieferte Vorlagen. Dein Team pflegt sie für alle Teams.' : 'Mitgelieferte Vorlagen. Nur zum Verwenden, ändern kann sie nur das Master-Team.')">
            @if($zeilen->isEmpty())
                @if($istEigene)
                    <x-fa::empty icon="heroicon-o-bookmark" title="Noch keine eigene Vorlage" compact>
                        In der Planung einen Auftrag vorbereiten und „Als Vorlage speichern“ wählen. Die Vorlage erscheint dann hier.
                    </x-fa::empty>
                @else
                    <x-fa::empty icon="heroicon-o-bookmark" title="Keine kuratierten Vorlagen vorhanden" compact />
                @endif
            @else
                <div class="overflow-x-auto -mx-4">
                    <table class="fa-table min-w-[640px]">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Bereich</th>
                                <th>Auftrag</th>
                                @if($bearbeitbar)
                                    <th>Status</th>
                                    <th><span class="sr-only">Aktionen</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($zeilen as $t)
                                <tr class="{{ $t->active ? '' : 'opacity-60' }}" wire:key="{{ $gruppe['key'] }}-{{ $t->id }}">
                                    <td class="align-top">
                                        @if($bearbeitbar && $editId === $t->id)
                                            <x-fa::input wire:model="editLabel" wire:keydown.enter="save" size="sm" aria-label="Name der Vorlage" class="min-w-[12rem]" />
                                        @else
                                            <span class="font-medium text-[var(--fa-ink)]">{{ $t->label }}</span>
                                        @endif
                                    </td>
                                    <td class="align-top text-[var(--fa-ink-2)] whitespace-nowrap">{{ $scopeLabel[$t->scope] ?? ucfirst((string) $t->scope) }}</td>
                                    <td class="align-top text-[var(--fa-ink-2)] max-w-[22rem]">
                                        <span class="line-clamp-2" title="{{ $t->brief }}">{{ \Illuminate\Support\Str::limit($t->brief, 140) }}</span>
                                    </td>
                                    @if($bearbeitbar)
                                        <td class="align-top">
                                            <x-fa::badge :tone="$t->active ? 'ok' : 'neutral'">{{ $t->active ? 'Aktiv' : 'Inaktiv' }}</x-fa::badge>
                                        </td>
                                        <td class="align-top">
                                            <div class="flex items-center justify-end gap-1">
                                                @if($editId === $t->id)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="save">Namen speichern</x-fa::button>
                                                @else
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="edit({{ $t->id }})">Umbenennen</x-fa::button>
                                                    <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $t->label }}" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                        <div class="hidden w-52 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="toggleActive({{ $t->id }})" class="{{ $menuPunkt }}">
                                                                @svg($t->active ? 'heroicon-m-eye-slash' : 'heroicon-m-eye', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                                                {{ $t->active ? 'Vorlage deaktivieren' : 'Vorlage aktivieren' }}
                                                            </button>
                                                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="loeschen({{ $t->id }})"
                                                                    wire:confirm="{{ $istEigene ? 'Vorlage „' . $t->label . '“ löschen?' : 'Kuratierte Vorlage „' . $t->label . '“ für alle Teams löschen?' }}"
                                                                    class="{{ $menuGefahr }}">
                                                                @svg('heroicon-m-trash', 'w-4 h-4')
                                                                Vorlage löschen
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endif
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
    @endforeach
</div>
