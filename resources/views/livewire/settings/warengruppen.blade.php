{{-- M1-03: Warengruppen (read-mostly, §3-Codes fix) + Sub-Kategorien-Housekeeping --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    {{-- Warengruppen --}}
    <div class="relative overflow-hidden {{ $card }}">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 pt-4 pb-2">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Warengruppen</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">Regelwerk GP §3: Die Codes 01–15 sind <strong>fix</strong> — nur fachliche Produkttypen, keine Zustände/Verarbeitung. Namen pflegt das Besitzer-Team.</p>
        </div>
        <table class="{{ $table }}">
            <thead><tr class="text-left">
                @foreach(['Code', 'Name', 'GPs', ''] as $head)<th class="{{ $th }}">{{ $head }}</th>@endforeach
            </tr></thead>
            <tbody>
                @foreach($warengruppen as $wg)
                    @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $wg))
                    @php($istParagraf3 = in_array($wg->code, $paragraf3, true))
                    <tr wire:key="wg-{{ $wg->id }}" class="{{ $tr }}">
                        <td class="{{ $td }} font-mono text-gray-900 dark:text-gray-100">{{ $wg->code }}
                            @if($istParagraf3)<span class="ml-1.5 {{ $pill }} {{ $variantPill['secondary'] }}" title="Fixer §3-Code — nicht löschbar">§3</span>@endif
                        </td>
                        <td class="{{ $td }}">
                            @if($editId === $wg->id)
                                <input type="text" wire:model="editName" wire:keydown.enter="saveName" wire:keydown.escape="$set('editId', null)" class="{{ $input }} !py-1" />
                            @else
                                <span class="text-gray-700 dark:text-gray-300">{{ $wg->name }}</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-gray-500">{{ $wg->gp_count }}</td>
                        <td class="{{ $td }} text-right whitespace-nowrap">
                            @if($darfEdit)
                                @if($editId === $wg->id)
                                    <button type="button" wire:click="saveName" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                @else
                                    <button type="button" wire:click="startEditName({{ $wg->id }}, '{{ addslashes($wg->name) }}')" class="{{ $btnGhostXs }}">Name ändern</button>
                                    <button type="button" wire:click="deleteWg({{ $wg->id }})" @if($istParagraf3) disabled title="Fixer §3-Code — nicht löschbar (Regelwerk GP §3)" @endif
                                            class="{{ $btnGhostXs }} {{ $istParagraf3 ? 'opacity-40 cursor-not-allowed' : 'text-red-500' }}">Löschen</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Sub-Kategorien --}}
    <div class="{{ $card }} p-5 space-y-3" data-subkat>
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Sub-Kategorien</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Freitext-Feld auf den GPs — Rename propagiert auf alle EIGENEN GPs (geerbte bleiben unberührt, D1).</p>
            </div>
            <select wire:model.live="subWg" class="{{ $input }} !w-64">
                @foreach($warengruppen as $wg)<option value="{{ $wg->code }}">{{ $wg->code }} {{ $wg->name }}</option>@endforeach
            </select>
        </div>

        @forelse($subKategorien as $sub)
            <div wire:key="sub-{{ md5($sub->sub_kategorie) }}" class="flex items-center justify-between gap-3 py-1.5 border-t border-black/5 dark:border-white/5">
                @if($renameAlt === $sub->sub_kategorie)
                    <input type="text" wire:model="renameNeu" wire:keydown.enter="rename" wire:keydown.escape="$set('renameAlt', null)" class="{{ $input }} !py-1 flex-1" />
                    <button type="button" wire:click="rename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0">Umbenennen</button>
                @else
                    <span class="text-xs text-gray-700 dark:text-gray-300 min-w-0 truncate">{{ $sub->sub_kategorie }} <span class="text-[11px] text-gray-400">({{ $sub->n }} GPs)</span></span>
                    <span class="shrink-0 space-x-1">
                        <button type="button" wire:click="startRename('{{ addslashes($sub->sub_kategorie) }}')" class="{{ $btnGhostXs }}">Umbenennen</button>
                        <button type="button" wire:click="clearWert('{{ addslashes($sub->sub_kategorie) }}')" wire:confirm="„{{ $sub->sub_kategorie }}" auf allen eigenen GPs auf NULL setzen?" class="{{ $btnGhostXs }} text-red-500">→ NULL</button>
                    </span>
                @endif
            </div>
        @empty
            <p class="text-xs text-gray-500">Keine Sub-Kategorien in dieser Warengruppe.</p>
        @endforelse
    </div>
</div>
