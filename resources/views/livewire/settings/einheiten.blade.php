{{-- M1-02: Einheiten-Verwaltung — Stück-Default-Gewichte, Inline-Edit, Inaktiv-Lebenszyklus --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($darfNeu = auth()->user()?->current_team_id !== null)

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20">
            <p class="text-sm text-red-600 dark:text-red-400">{{ $fehler }}</p>
        </div>
    @endif

    <div class="relative overflow-hidden {{ $card }}">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Einheiten</h3>
            <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                <input type="checkbox" wire:model.live="includeInactive" class="rounded border-gray-300" />
                Inaktive zeigen
            </label>
        </div>

        <table class="{{ $table }}">
            <thead>
                <tr class="text-left">
                    @foreach(['Slug', 'Anzeige', 'Dimension', 'Default g', 'Default ml', 'Sort', ''] as $head)
                        <th class="{{ $th }}">{{ $head }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($einheiten as $einheit)
                    @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $einheit))
                    <tr wire:key="einheit-{{ $einheit->id }}" class="{{ $tr }} {{ $einheit->is_inactive ? 'opacity-50' : '' }}">
                        @if($editId === $einheit->id)
                            <td class="{{ $td }} text-gray-400">{{ $einheit->slug }}</td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.display_de" wire:keydown.enter="save" wire:keydown.escape="cancel" class="{{ $input }} !py-1" /></td>
                            <td class="{{ $td }}">
                                <select wire:model="form.dimension" class="{{ $input }} !py-1">
                                    <option value="">—</option>
                                    @foreach(['mass', 'volume', 'count'] as $dim)<option value="{{ $dim }}">{{ $dim }}</option>@endforeach
                                </select>
                            </td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.default_in_g" wire:keydown.enter="save" class="{{ $input }} !py-1 w-20" /></td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.default_in_ml" wire:keydown.enter="save" class="{{ $input }} !py-1 w-20" /></td>
                            <td class="{{ $td }}"><input type="number" wire:model="form.sort_order" class="{{ $input }} !py-1 w-16" /></td>
                            <td class="{{ $td }} text-right whitespace-nowrap">
                                <button type="button" wire:click="save" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                            </td>
                        @else
                            <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $einheit->slug }}
                                @unless($darfEdit)<span class="ml-1.5 {{ $pill }} {{ $variantPill['secondary'] }}" title="Geerbter Katalog — Pflege nur Besitzer-Team (D1)">geerbt</span>@endunless
                            </td>
                            <td class="{{ $td }} text-gray-700 dark:text-gray-300">{{ $einheit->display_de }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $einheit->dimension ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $einheit->default_in_g !== null ? rtrim(rtrim((string) $einheit->default_in_g, '0'), '.') : '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $einheit->default_in_ml !== null ? rtrim(rtrim((string) $einheit->default_in_ml, '0'), '.') : '—' }}</td>
                            <td class="{{ $td }} text-gray-400">{{ $einheit->sort_order }}</td>
                            <td class="{{ $td }} text-right whitespace-nowrap" data-einheit-aktionen="{{ $darfEdit ? 'edit' : 'readonly' }}">
                                @if($darfEdit)
                                    <button type="button" wire:click="edit({{ $einheit->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                    <button type="button" wire:click="toggleInactive({{ $einheit->id }}, {{ $einheit->is_inactive ? 'false' : 'true' }})" class="{{ $btnGhostXs }}">{{ $einheit->is_inactive ? 'Aktivieren' : 'Inaktiv' }}</button>
                                    <button type="button" wire:click="delete({{ $einheit->id }})" wire:confirm="Einheit „{{ $einheit->display_de }}" wirklich löschen?" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Neu anlegen (eigenes Team — Kind-Teams ergänzen Eigenes, D1) --}}
    <div class="{{ $card }} p-5" data-einheit-neu>
        <h4 class="{{ $label }} mb-3">Neue Einheit (für dein Team)</h4>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-2">
            <input type="text" wire:model="neu.slug" placeholder="slug (z. B. el)" class="{{ $input }}" />
            <input type="text" wire:model="neu.display_de" placeholder="Anzeige (z. B. EL)" class="{{ $input }}" />
            <select wire:model="neu.dimension" class="{{ $input }}">
                <option value="">Dimension…</option>
                @foreach(['mass', 'volume', 'count'] as $dim)<option value="{{ $dim }}">{{ $dim }}</option>@endforeach
            </select>
            <input type="text" wire:model="neu.default_in_g" placeholder="g" class="{{ $input }}" />
            <input type="text" wire:model="neu.default_in_ml" placeholder="ml" class="{{ $input }}" />
            <button type="button" wire:click="create" class="{{ $btnPrimary }} justify-center">Anlegen</button>
        </div>
    </div>
</div>
