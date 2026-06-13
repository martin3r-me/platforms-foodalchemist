{{-- M1-03: Warengruppen — Master-Detail (WG-Liste links, Sub-Kategorien-Tabelle rechts), §3-Codes fix --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($katHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    <div class="flex gap-4 items-start">
        {{-- Warengruppen links --}}
        <div class="w-96 shrink-0 {{ $card }} p-3 space-y-0.5" data-warengruppen-liste>
            <div class="{{ $label }} px-2 pb-2">Warengruppen ({{ $warengruppen->count() }})</div>
            @foreach($warengruppen as $wg)
                @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $wg))
                @php($istParagraf3 = in_array($wg->code, $paragraf3, true))
                <div wire:key="wg-{{ $wg->id }}" class="group flex items-center gap-1 rounded-lg {{ $subWg === $wg->code ? $katAktiv : $katHover }}">
                    @if($editId === $wg->id)
                        <span class="font-mono text-[11px] text-gray-400 pl-2">{{ $wg->code }}</span>
                        <input type="text" wire:model="editName" wire:keydown.enter="saveName" wire:keydown.escape="$set('editId', null)" class="{{ $input }} !py-0.5 flex-1" autofocus />
                        <button type="button" wire:click="saveName" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0">OK</button>
                    @else
                        <button type="button" wire:click="waehleWg('{{ $wg->code }}')" class="flex-1 min-w-0 flex items-center gap-1.5 text-left px-2 py-1 text-xs">
                            <span class="font-mono text-[10px] text-gray-400">{{ $wg->code }}</span>
                            <span class="min-w-0 truncate">{{ $wg->name }}</span>
                            @if($istParagraf3)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">§3</span>@endif
                        </button>
                        <span class="text-[11px] text-gray-400 shrink-0">{{ $wg->gp_count }}</span>
                        @if($darfEdit)
                            <button type="button" wire:click="startEditName({{ $wg->id }}, '{{ addslashes($wg->name) }}')" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px] px-1" title="Name ändern">✎</button>
                            <button type="button" wire:click="deleteWg({{ $wg->id }})" @if($istParagraf3) disabled title="Fixer §3-Code — nicht löschbar" @endif
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-[11px] px-1 {{ $istParagraf3 ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-red-500' }}" title="löschen">✕</button>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Sub-Kategorien rechts --}}
        <div class="flex-1 min-w-0">
            <div class="relative overflow-hidden {{ $card }}" data-subkat>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Sub-Kategorien</h3>
                        <p class="text-[11px] text-gray-400 mt-0.5">Freitext-Feld auf den GPs — Rename propagiert auf alle EIGENEN GPs (geerbte bleiben unberührt, D1).</p>
                    </div>
                    <span class="{{ $label }}">{{ optional($warengruppen->firstWhere('code', $subWg))->name }}</span>
                </div>
                <table class="{{ $table }}">
                    <thead><tr class="text-left">@foreach(['Sub-Kategorie', 'GPs', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($subKategorien as $sub)
                            <tr wire:key="sub-{{ md5($sub->sub_kategorie) }}" class="{{ $tr }}">
                                @if($renameAlt === $sub->sub_kategorie)
                                    <td class="{{ $td }}"><input type="text" wire:model="renameNeu" wire:keydown.enter="rename" wire:keydown.escape="$set('renameAlt', null)" class="{{ $input }} !py-1" autofocus /></td>
                                    <td class="{{ $td }} text-gray-500">{{ $sub->n }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        <button type="button" wire:click="rename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Umbenennen</button>
                                        <button type="button" wire:click="$set('renameAlt', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                    </td>
                                @else
                                    <td class="{{ $td }} text-gray-700 dark:text-gray-300">{{ $sub->sub_kategorie }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $sub->n }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        <button type="button" wire:click="startRename('{{ addslashes($sub->sub_kategorie) }}')" class="{{ $btnGhostXs }}">Umbenennen</button>
                                        <button type="button" wire:click="clearWert('{{ addslashes($sub->sub_kategorie) }}')" wire:confirm="„{{ $sub->sub_kategorie }}" auf allen eigenen GPs auf NULL setzen?" class="{{ $btnGhostXs }} text-red-500">→ NULL</button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="3" class="{{ $td }} text-gray-400 py-4 text-center">Keine Sub-Kategorien in dieser Warengruppe.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
