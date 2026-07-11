{{-- #469: Wissens-Kategorien — pflegbares Vokabular (Klassifikation + grobe Routing-Ebene) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-wissenskategorien>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Wissens-Kategorien</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Klassifiziert Wissens-Dokumente (such-/filterbar) und trägt die grobe Routing-Ebene (Feature × Kategorie). Löschen nur, wenn keine Dokumente die Kategorie nutzen — sonst deaktivieren.</p>
    </div>
    @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-kat-fehler>{{ $fehler }}</p>@endif

    <table class="{{ $table }}" data-kat-tabelle>
        <thead><tr class="text-left">@foreach(['Label', 'Slug', 'Beschreibung', 'Docs', 'Sort', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach($kategorien as $kat)
                <tr class="{{ $tr }} {{ $kat->active ? '' : 'opacity-50' }}" wire:key="kat-{{ $kat->id }}">
                    @if($editId === $kat->id)
                        <td class="{{ $td }}"><input type="text" wire:model="form.label" class="{{ $input }} !py-1" /></td>
                        <td class="{{ $td }} text-[10px] font-mono text-gray-400">{{ $kat->slug }}</td>
                        <td class="{{ $td }}"><input type="text" wire:model="form.description" class="{{ $input }} !py-1 w-full" placeholder="Beschreibung (optional)" /></td>
                        <td class="{{ $td }} text-[11px] text-gray-400">{{ $docCounts[$kat->slug] ?? 0 }}</td>
                        <td class="{{ $td }}"><input type="text" wire:model="form.sort_order" class="{{ $input }} !py-1 !w-14 text-right" /></td>
                        <td class="{{ $td }} whitespace-nowrap">
                            <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-kat-save>Speichern</button>
                            <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                        </td>
                    @else
                        <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $kat->label }}</td>
                        <td class="{{ $td }} text-[10px] font-mono text-gray-400">{{ $kat->slug }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-400 max-w-[16rem] truncate">{{ $kat->description ?? '—' }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-500">{{ $docCounts[$kat->slug] ?? 0 }}</td>
                        <td class="{{ $td }} text-[11px] text-gray-400">{{ $kat->sort_order }}</td>
                        <td class="{{ $td }} whitespace-nowrap">
                            <button type="button" wire:click="edit({{ $kat->id }})" class="{{ $btnGhostXs }}" data-kat-edit>Bearbeiten</button>
                            <button type="button" wire:click="toggleActive({{ $kat->id }})" class="{{ $btnGhostXs }}">{{ $kat->active ? 'deaktivieren' : 'aktivieren' }}</button>
                            <button type="button" wire:click="delete({{ $kat->id }})" wire:confirm="Diese Kategorie löschen?" class="{{ $btnGhostXs }} text-red-500" title="löschen (nur wenn ungenutzt)">Löschen</button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Anlegen --}}
    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-1.5" data-kat-anlegen>
        <p class="{{ $dt }}">Neue Kategorie</p>
        <div class="flex flex-wrap items-start gap-2">
            <input type="text" wire:model="neu.label" placeholder="Label (z. B. Regelwerke)" class="{{ $input }} !py-1 w-52" data-kat-neu-label />
            <input type="text" wire:model="neu.description" placeholder="Beschreibung (optional)" class="{{ $input }} !py-1 flex-1 min-w-[16rem]" />
            <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-kat-neu-anlegen>+ Anlegen</button>
        </div>
        <p class="text-[10px] text-gray-400">Slug wird aus dem Label erzeugt.</p>
    </div>
</div>
