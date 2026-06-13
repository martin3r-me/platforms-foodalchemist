{{-- M1-04: Rezept-Taxonomie — HG-Baum links, Kategorien rechts (M4 liest dieselben Service-Methoden) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    <div class="flex gap-4 items-start">
        {{-- Hauptgruppen --}}
        <div class="w-80 shrink-0 {{ $card }} p-3 space-y-0.5" data-taxonomie-hg>
            <div class="{{ $label }} px-2 pb-2">Hauptgruppen ({{ $hauptgruppen->count() }})</div>
            @foreach($hauptgruppen as $hg)
                <button type="button" wire:key="hg-{{ $hg->id }}" wire:click="waehleHg({{ $hg->id }})"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppeId === $hg->id
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="min-w-0 truncate text-left">{{ $hg->bezeichnung }}</span>
                    <span class="text-[11px] text-gray-400 shrink-0 ml-2">{{ $hg->kategorie_count }}</span>
                </button>
            @endforeach

            <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10" data-taxonomie-hg-neu>
                <input type="text" wire:model="neueHauptgruppe" wire:keydown.enter="hgNeu"
                       placeholder="Neue Hauptgruppe …" class="{{ $input }} py-0.5" />
                <button type="button" wire:click="hgNeu" class="{{ $btnGhostXs }}" title="Hauptgruppe anlegen">+</button>
            </div>
        </div>

        {{-- Kategorien der gewählten HG --}}
        <div class="flex-1 min-w-0 space-y-4">
            <div class="relative overflow-hidden {{ $card }}" data-taxonomie-kategorien>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kategorien</h3>
                    <span class="{{ $label }}">{{ $kategorien->count() }} in dieser Hauptgruppe</span>
                </div>
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        @foreach(['Bezeichnung', 'Technik', 'Sort', 'Rezepte', ''] as $head)<th class="{{ $th }}">{{ $head }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach($kategorien as $kat)
                            @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $kat))
                            <tr wire:key="kat-{{ $kat->id }}" class="{{ $tr }}">
                                @if($editId === $kat->id)
                                    <td class="{{ $td }}"><input type="text" wire:model="form.bezeichnung" wire:keydown.enter="save" class="{{ $input }} !py-1" /></td>
                                    <td class="{{ $td }}"><input type="text" wire:model="form.technik" wire:keydown.enter="save" class="{{ $input }} !py-1" /></td>
                                    <td class="{{ $td }}"><input type="number" wire:model="form.sort_order" class="{{ $input }} !py-1 w-16" /></td>
                                    <td class="{{ $td }}"></td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        <button type="button" wire:click="save" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                        <button type="button" wire:click="$set('editId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                    </td>
                                @else
                                    <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $kat->bezeichnung }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $kat->technik ?? '—' }}</td>
                                    <td class="{{ $td }} text-gray-400">{{ $kat->sort_order }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $kat->recipe_count }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($darfEdit)
                                            <button type="button" wire:click="edit({{ $kat->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                            <button type="button" wire:click="delete({{ $kat->id }})"
                                                    @if($kat->recipe_count > 0) disabled title="Hat {{ $kat->recipe_count }} Rezepte — erst mergen/umhängen (AT-D1-02)" @endif
                                                    wire:confirm="Kategorie „{{ $kat->bezeichnung }}" löschen?"
                                                    class="{{ $btnGhostXs }} {{ $kat->recipe_count > 0 ? 'opacity-40 cursor-not-allowed' : 'text-red-500' }}">Löschen</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="{{ $card }} p-5" data-taxonomie-neu>
                <h4 class="{{ $label }} mb-3">Neue Kategorie in dieser Hauptgruppe</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <input type="text" wire:model="neu.bezeichnung" placeholder="Bezeichnung" class="{{ $input }}" />
                    <input type="text" wire:model="neu.technik" placeholder="Technik (optional)" class="{{ $input }}" />
                    <input type="number" wire:model="neu.sort_order" placeholder="Sort" class="{{ $input }}" />
                    <button type="button" wire:click="create" class="{{ $btnPrimary }} justify-center">Anlegen</button>
                </div>
            </div>
        </div>
    </div>
</div>
