{{-- M10-02 / Doc 15 §M10: Baustein-Browser — Bündel mehrerer Gerichte mit eigenem Per-Person-Preis --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Bausteine" icon="heroicon-o-puzzle-piece" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Rollen" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Baustein suchen …" class="{{ $input }}" />
                <button type="button" wire:click="$set('rolleFilter', '')"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs {{ $rolleFilter === '' ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <span class="font-medium">Alle Rollen</span>
                </button>
                <div class="space-y-0.5 -mx-1">
                    @foreach($rollen as $rolle)
                        <button type="button" wire:key="rolle-{{ $loop->index }}" wire:click="$set('rolleFilter', @js($rolle))"
                                class="w-full flex items-center px-2 py-1 rounded-lg text-xs {{ $rolleFilter === $rolle ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                            <span class="truncate">{{ $rolle }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Baustein" width="w-96" :maxWidth="640" storeKey="activityOpen" side="right">
            @if($selected)
                <div class="p-4 space-y-4" wire:key="edit-{{ $selected->id }}">
                    <div class="space-y-2">
                        <label class="{{ $label }}">Name</label>
                        <input type="text" wire:model="form.name" class="{{ $input }}" />
                        <label class="{{ $label }}">Rolle (frei)</label>
                        <input type="text" wire:model="form.rolle" list="rollen-liste" class="{{ $input }}" placeholder="z. B. Vorspeise" />
                        <datalist id="rollen-liste">@foreach($rollen as $r)<option value="{{ $r }}"></option>@endforeach</datalist>
                        <label class="{{ $label }}">Niveau</label>
                        <select wire:model="form.niveau" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach(['haute' => 'Haute', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'] as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Preis-Block --}}
                    <div class="space-y-2 pt-2 border-t border-black/5 dark:border-white/10">
                        <label class="{{ $label }}">Preis-Modus</label>
                        <select wire:model.live="form.preis_modus" class="{{ $input }}">
                            <option value="manuell">manuell (Per-Person-Preis setzen)</option>
                            <option value="auto">auto (Σ aus den Gerichten)</option>
                        </select>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="{{ $label }}">€ / Person</label>
                                <input type="number" step="0.01" wire:model="form.preis_pro_person" class="{{ $input }} text-right tabular-nums" @disabled($form['preis_modus'] === 'auto') />
                            </div>
                            <div>
                                <label class="{{ $label }}">EK / Person</label>
                                <input type="number" step="0.0001" wire:model="form.ek_pro_person" class="{{ $input }} text-right tabular-nums" @disabled($form['preis_modus'] === 'auto') />
                            </div>
                            <div>
                                <label class="{{ $label }}">W %</label>
                                <input type="number" step="0.1" wire:model="form.wareneinsatz_prozent" class="{{ $input }} text-right tabular-nums" @disabled($form['preis_modus'] === 'auto') />
                            </div>
                        </div>
                        @if($form['preis_modus'] === 'auto')
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-gray-400">Preis wird aus den Gerichten berechnet.</span>
                                <button type="button" wire:click="neuBerechnen" class="{{ $btnGhostXs }}">↻ Neu berechnen</button>
                            </div>
                        @endif
                        @if($selected->preis_stale)
                            <p class="{{ $pill }} {{ $variantPill['warning'] }}">Preis veraltet — neu berechnen</p>
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                        <button type="button" wire:click="loeschen({{ $selected->id }})"
                                wire:confirm="Baustein löschen?" class="{{ $btnGhost }} text-red-600 dark:text-red-400">Löschen</button>
                    </div>

                    {{-- Gerichte im Baustein (B-03: einfügen wie im Gerichte-Screen) --}}
                    @php($sumVk = $selected->gerichte->sum(fn ($g) => (float) ($g->gericht?->vk_netto ?? 0)))
                    <div class="space-y-2 pt-2 border-t border-black/5 dark:border-white/10">
                        {{-- B-07: KPI-Leiste --}}
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px]">
                            <span><span class="{{ $label }}">Gerichte</span> <span class="tabular-nums">{{ $selected->gerichte->count() }}</span></span>
                            <span><span class="{{ $label }}">Σ Gerichte-VK</span> <span class="tabular-nums">{{ number_format($sumVk, 2, ',', '.') }} €</span></span>
                            <span><span class="{{ $label }}">Baustein €/P</span> <span class="tabular-nums">{{ $selected->preis_pro_person !== null ? number_format((float) $selected->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</span></span>
                            <span><span class="{{ $label }}">W%</span> <span class="tabular-nums">{{ $selected->wareneinsatz_prozent !== null ? number_format((float) $selected->wareneinsatz_prozent, 1, ',', '.') . ' %' : '—' }}</span></span>
                        </div>
                        <label class="{{ $label }}">Gerichte in diesem Baustein (nur Gerichte, keine Basisrezepte)</label>
                        <div class="space-y-1">
                            @forelse($selected->gerichte as $g)
                                <div wire:key="bg-{{ $g->id }}" class="flex items-center gap-2 px-2 py-1 rounded-lg bg-black/[0.03] dark:bg-white/5 text-xs">
                                    <span class="flex flex-col -my-0.5 shrink-0">
                                        <button type="button" wire:click="gerichtHoch({{ $g->id }})" class="text-gray-400 hover:text-violet-500 leading-none" title="hoch">▲</button>
                                        <button type="button" wire:click="gerichtRunter({{ $g->id }})" class="text-gray-400 hover:text-violet-500 leading-none" title="runter">▼</button>
                                    </span>
                                    <span class="flex-1 min-w-0 truncate">{{ $g->gericht?->name ?? '—' }}</span>
                                    <input type="number" step="1" min="0" wire:model.blur="mengeForm.{{ $g->id }}" wire:change="gerichtMengeSpeichern({{ $g->id }})"
                                           class="{{ $input }} w-16 text-right tabular-nums py-0.5" placeholder="g/P" title="Menge pro Person" />
                                    <span class="text-gray-400 tabular-nums shrink-0 w-14 text-right">{{ $g->gericht?->vk_netto !== null ? number_format((float) $g->gericht->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" wire:click="gerichtRaus({{ $g->vk_recipe_id }})" class="text-gray-400 hover:text-red-500 shrink-0" title="Entfernen">✕</button>
                                </div>
                            @empty
                                <p class="text-[11px] text-gray-400">Noch keine Gerichte. Unten suchen und hinzufügen.</p>
                            @endforelse
                        </div>
                        <input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen + hinzufügen …" class="{{ $input }}" />
                        @if($gerichtSuche !== '' && $kandidaten->isNotEmpty())
                            <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                @foreach($kandidaten as $k)
                                    <button type="button" wire:key="kand-{{ $k->id }}" wire:click="gerichtHinzu({{ $k->id }})"
                                            class="w-full flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                        <span class="truncate">{{ $k->name }}</span>
                                        <span class="text-gray-400 tabular-nums shrink-0">+ {{ $k->vk_netto !== null ? number_format((float) $k->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-400">Baustein auswählen oder neu anlegen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between pt-1">
            <button type="button" wire:click="neu" class="{{ $btnPrimary }}">+ Neuer Baustein</button>
            <p class="text-[11px] text-gray-400">Baustein = bepreistes Bündel mehrerer Gerichte für eine Rolle. Im Concept tauschbar gegen Bausteine derselben Rolle.</p>
        </div>
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        @foreach([['Name', 'w-full'], ['Rolle', ''], ['Niveau', ''], ['Gerichte', 'text-right'], ['€ / Person', 'text-right'], ['W %', 'text-right'], ['Modus', '']] as [$head, $align])
                            <th class="{{ $th }} {{ $align }}">{{ $head }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse($bausteine as $b)
                            <tr wire:key="b-{{ $b->id }}" wire:click="waehle({{ $b->id }})"
                                class="{{ $tr }} cursor-pointer {{ $selectedId === $b->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}">
                                <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate text-gray-900 dark:text-gray-100">{{ $b->name }}</td>
                                <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $b->rolle ?? '—' }}</td>
                                <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $b->niveau ?? '—' }}</td>
                                <td class="{{ $td }} text-gray-500 text-right tabular-nums">{{ $b->gerichte_count }}</td>
                                <td class="{{ $td }} text-gray-900 dark:text-gray-100 text-right tabular-nums whitespace-nowrap">{{ $b->preis_pro_person !== null ? number_format((float) $b->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-gray-500 text-right tabular-nums">{{ $b->wareneinsatz_prozent !== null ? number_format((float) $b->wareneinsatz_prozent, 1, ',', '.') . ' %' : '—' }}</td>
                                <td class="{{ $td }}"><span class="{{ $pill }} {{ $b->preis_modus === 'auto' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $b->preis_modus }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-10 text-center text-gray-400">Noch keine Bausteine. Oben „+ Neuer Baustein".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $bausteine->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
