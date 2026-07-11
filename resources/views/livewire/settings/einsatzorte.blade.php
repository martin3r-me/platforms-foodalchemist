{{-- #469: Einsatzorte/Layer — Bindungs-Ziele fürs Wissen (Bereiche grob + Prompts fein) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-einsatzorte>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Einsatzorte</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Bindungs-Ziele fürs Wissen. <b>Bereiche</b> (grob) = ganze KI-Sektion · <b>Prompts</b> (fein) = einzelner KI-Aufruf. Aus der Prompt-Registry abgeleitet (kein Anlegen); Label/Beschreibung + aktiv pflegbar. Gebundenes Wissen wird bei einem Prompt geladen, wenn es an ihn <em>oder</em> seinen Bereich gebunden ist.</p>
    </div>

    @foreach([['Bereiche (grob)', $bereiche], ['KI-Prompts (fein)', $prompts]] as [$titel, $liste])
        <div>
            <p class="{{ $dt }} mb-1">{{ $titel }}</p>
            <table class="{{ $table }}">
                <thead><tr class="text-left">@foreach(['Label', 'Slug', 'Beschreibung', 'Bindungen', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($liste as $l)
                        <tr class="{{ $tr }} {{ $l->active ? '' : 'opacity-50' }}" wire:key="layer-{{ $l->id }}">
                            @if($editId === $l->id)
                                <td class="{{ $td }}"><input type="text" wire:model="form.label" class="{{ $input }} !py-1" /></td>
                                <td class="{{ $td }} text-[10px] font-mono text-gray-400">{{ $l->slug }}</td>
                                <td class="{{ $td }}" colspan="2"><input type="text" wire:model="form.description" class="{{ $input }} !py-1 w-full" placeholder="Beschreibung (optional)" /></td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    <button type="button" wire:click="save" class="{{ $btnPrimary }}">Speichern</button>
                                    <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                                </td>
                            @else
                                <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $l->label }}</td>
                                <td class="{{ $td }} text-[10px] font-mono text-gray-400">{{ $l->slug }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-400 max-w-[16rem] truncate">{{ $l->description ?? '—' }}</td>
                                <td class="{{ $td }} text-[11px] text-gray-500">{{ $bindCounts[$l->slug] ?? 0 }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $l->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                    <button type="button" wire:click="toggleActive({{ $l->id }})" class="{{ $btnGhostXs }}">{{ $l->active ? 'deaktivieren' : 'aktivieren' }}</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
