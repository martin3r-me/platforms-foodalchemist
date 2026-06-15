{{-- R5: Schreibstile — eigene Seite, CRUD (sprach_duktus = Prompt-Material GL-06) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-schreibstile>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Schreibstile</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Sprach-Duktus je Stil ist Prompt-Material für VK-Wording (GL-06-Feld-Hülle). Löschen nur wenn von keinem Concept/Foodbook genutzt — sonst deaktivieren.</p>
    </div>
    @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-stil-fehler>{{ $fehler }}</p>@endif

    <table class="{{ $table }}" data-stil-tabelle>
        <thead><tr class="text-left">@foreach(['Name', 'Sprach-Duktus', 'Beschreibung', 'Sort', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach($stile as $stil)
                <tr class="{{ $tr }} {{ $stil->is_inactive ? 'opacity-50' : '' }}" wire:key="stil-{{ $stil->id }}">
                    @if($editId === $stil->id)
                        <td class="{{ $td }}"><input type="text" wire:model="form.name" class="{{ $input }} !py-1" /></td>
                        <td class="{{ $td }}" colspan="2">
                            <textarea wire:model="form.sprach_duktus" rows="2" class="{{ $input }} !py-1 w-full" placeholder="Sprach-Duktus (Prompt-Material)"></textarea>
                            <input type="text" wire:model="form.beschreibung" class="{{ $input }} !py-1 w-full mt-1" placeholder="Beschreibung (optional)" />
                        </td>
                        <td class="{{ $td }}"><input type="text" wire:model="form.sort_order" class="{{ $input }} !py-1 !w-14 text-right" /></td>
                        <td class="{{ $td }} whitespace-nowrap">
                            <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-stil-save>Speichern</button>
                            <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                        </td>
                    @else
                        <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $stil->name }} <span class="text-[10px] font-mono text-gray-400">{{ $stil->slug }}</span></td>
                        <td class="{{ $td }} text-[11px] text-gray-500 max-w-md truncate" title="{{ $stil->sprach_duktus }}">{{ $stil->sprach_duktus }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-400 max-w-[12rem] truncate">{{ $stil->beschreibung ?? '—' }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-400">{{ $stil->sort_order }}</td>
                        <td class="{{ $td }} whitespace-nowrap">
                            <button type="button" wire:click="edit({{ $stil->id }})" class="{{ $btnGhostXs }}" data-stil-edit>Bearbeiten</button>
                            <button type="button" wire:click="toggleInactive({{ $stil->id }})" class="{{ $btnGhostXs }}">{{ $stil->is_inactive ? 'aktivieren' : 'deaktivieren' }}</button>
                            <button type="button" wire:click="delete({{ $stil->id }})" wire:confirm="Diesen Schreibstil löschen?" class="{{ $btnGhostXs }} text-red-500" title="löschen (nur wenn ungenutzt)">Löschen</button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Anlegen --}}
    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-1.5" data-stil-anlegen>
        <p class="{{ $dt }}">Neuer Schreibstil</p>
        <div class="flex flex-wrap items-start gap-2">
            <input type="text" wire:model="neu.name" placeholder="Name (z. B. Rustikal)" class="{{ $input }} !py-1 w-44" data-stil-neu-name />
            <textarea wire:model="neu.sprach_duktus" rows="1" placeholder="Sprach-Duktus — wie soll die KI klingen?" class="{{ $input }} !py-1 flex-1 min-w-[16rem]"></textarea>
            <input type="text" wire:model="neu.beschreibung" placeholder="Beschreibung (optional)" class="{{ $input }} !py-1 w-52" />
            <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-stil-neu-anlegen>+ Anlegen</button>
        </div>
    </div>
</div>
