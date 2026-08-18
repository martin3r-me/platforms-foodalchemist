{{-- Schnellstart-Vorlagen (Brief-Templates) verwalten. Angelegt im Planung-Editor (Snapshot);
     hier: eigene umbenennen/aktiv/löschen + kuratierte read-only. Auch per MCP (brief_templates.*). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-brief-vorlagen>
    @if($fehler)
        <p class="text-[11px] text-red-500">{{ $fehler }}</p>
    @endif

    <p class="text-[11px] text-gray-500">
        Vorlagen werden im <b>Planung-Editor</b> angelegt („Als Vorlage speichern" — nimmt Brief, Kreativ-Modus
        und alle Leitplanken auf). Hier verwaltest du sie: umbenennen, aktiv/inaktiv schalten, löschen.
        Dieselbe Verwaltung geht per MCP (<code>foodalchemist.brief_templates.*</code>).
    </p>

    <div>
        <p class="{{ $dt }} mb-1">Eigene Vorlagen</p>
        @if($eigene->isEmpty())
            <p class="text-[11px] text-gray-400">Noch keine eigenen — im Planung-Editor „Als Vorlage speichern".</p>
        @else
            <table class="{{ $table }}">
                <thead><tr class="text-left">@foreach(['Name', 'Tab', 'Briefing', 'Status', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($eigene as $t)
                        <tr class="{{ $tr }} {{ $t->active ? '' : 'opacity-50' }}" wire:key="bt-{{ $t->id }}">
                            @if($editId === $t->id)
                                <td class="{{ $td }}"><input type="text" wire:model="editLabel" class="{{ $input }} !py-1" /></td>
                                <td class="{{ $td }} text-[11px] text-gray-500">{{ $scopeLabel[$t->scope] ?? $t->scope }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-500 max-w-[18rem] truncate">{{ \Illuminate\Support\Str::limit($t->brief, 60) }}</td>
                                <td class="{{ $td }} text-[11px]">{{ $t->active ? 'aktiv' : 'inaktiv' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    <button type="button" wire:click="save" class="{{ $btnPrimary }}">Speichern</button>
                                    <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                                </td>
                            @else
                                <td class="{{ $td }} font-medium text-gray-900">{{ $t->label }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-500">{{ $scopeLabel[$t->scope] ?? $t->scope }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-500 max-w-[18rem] truncate">{{ \Illuminate\Support\Str::limit($t->brief, 60) }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-600">{{ $t->active ? 'aktiv' : 'inaktiv' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $t->id }})" class="{{ $btnGhostXs }}">Umbenennen</button>
                                    <button type="button" wire:click="toggleActive({{ $t->id }})" class="{{ $btnGhostXs }}">{{ $t->active ? 'deaktivieren' : 'aktivieren' }}</button>
                                    <button type="button" wire:click="loeschen({{ $t->id }})" wire:confirm="Vorlage „{{ $t->label }}“ löschen?" class="{{ $btnGhostXs }} text-red-500">löschen</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <p class="{{ $dt }} mb-1">Kuratierte Vorlagen (BHG-Standard · read-only)</p>
        <table class="{{ $table }}">
            <thead><tr class="text-left">@foreach(['Name', 'Tab', 'Briefing'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($globals as $t)
                    <tr class="{{ $tr }}" wire:key="btg-{{ $t->id }}">
                        <td class="{{ $td }} font-medium text-gray-900">{{ $t->label }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-500">{{ $scopeLabel[$t->scope] ?? $t->scope }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-500 max-w-[20rem] truncate">{{ \Illuminate\Support\Str::limit($t->brief, 70) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
