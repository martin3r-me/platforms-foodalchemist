{{-- M10R-2 / Doc 15 §10.2+§10.4: vereinheitlichter Concepter-Browser (Concepts | Pakete in einem Screen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katById = collect($kategorienFlat)->keyBy('id'))
@php($tabAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($filterHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')
@php($niveauDot = ['klassisch' => 'bg-sky-400', 'gehoben' => 'bg-amber-400', 'haute' => 'bg-violet-500'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Concepter" icon="heroicon-o-square-3-stack-3d" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Concepter" width="w-80">
            <div class="p-3 space-y-3">
                {{-- Umschalter Concepts | Pakete (§10.2) --}}
                <div class="flex gap-1.5 p-0.5 rounded-lg bg-black/[0.03] dark:bg-white/5">
                    <button type="button" wire:click="wechselTab('concepts')"
                            class="flex-1 text-xs py-1 rounded-md transition-all {{ $tab === 'concepts' ? 'bg-white dark:bg-white/10 shadow-sm font-medium text-violet-700 dark:text-violet-300' : 'text-gray-500' }}">Concepts</button>
                    <button type="button" wire:click="wechselTab('pakete')"
                            class="flex-1 text-xs py-1 rounded-md transition-all {{ $tab === 'pakete' ? 'bg-white dark:bg-white/10 shadow-sm font-medium text-violet-700 dark:text-violet-300' : 'text-gray-500' }}">Pakete</button>
                </div>

                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="{{ $tab === 'pakete' ? 'Paket suchen …' : 'Concept suchen …' }}" class="{{ $input }}" />

                @if($tab === 'concepts')
                    <div class="flex gap-1.5">
                        <button type="button" wire:click="$set('showVorlagen', false)"
                                class="{{ $pill }} {{ ! $showVorlagen ? $variantPill['primary'] : $variantPill['secondary'] }}">Concepts</button>
                        <button type="button" wire:click="$set('showVorlagen', true)"
                                class="{{ $pill }} {{ $showVorlagen ? $variantPill['primary'] : $variantPill['secondary'] }}">Vorlagen</button>
                    </div>
                @endif

                {{-- Klasse-Filter (geteilte Dimension §10.3) --}}
                @if(! empty($klassen))
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Klasse</span>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" wire:click="waehleKlasse('')" class="{{ $pill }} {{ $klasse === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                            @foreach($klassen as $k)
                                <button type="button" wire:key="kl-{{ $loop->index }}" wire:click="waehleKlasse(@js($k))"
                                        class="{{ $pill }} {{ $klasse === $k ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $k }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($tab === 'pakete' && ! empty($rollen))
                    {{-- Rollen-Filter (Pakete) --}}
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Rolle</span>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" wire:click="waehleRolle('')" class="{{ $pill }} {{ $rolleFilter === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                            @foreach($rollen as $r)
                                <button type="button" wire:key="ro-{{ $loop->index }}" wire:click="waehleRolle(@js($r))"
                                        class="{{ $pill }} {{ $rolleFilter === $r ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $r }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($tab === 'concepts')
                    {{-- Kategorie-Baum (Filter; Pflege im Concept-Screen / Einstellungen) --}}
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Kategorien</span>
                        <button type="button" wire:click="waehleKategorie('')" class="w-full text-left text-xs px-2 py-0.5 rounded-lg {{ $categoryFilter === '' ? $tabAktiv : $filterHover }}">Alle</button>
                        <button type="button" wire:click="waehleKategorie('none')" class="w-full text-left text-xs px-2 py-0.5 rounded-lg {{ $categoryFilter === 'none' ? $tabAktiv : $filterHover }}">Ohne Kategorie</button>
                        <x-foodalchemist::tree :initial-collapsed="collect($kategorienFlat)->where('has_children', true)->pluck('id')->all()">
                            @foreach($kategorienFlat as $kat)
                                <x-foodalchemist::tree-node :node-id="$kat['id']" :depth="$kat['depth']" :ancestors="$kat['ancestors'] ?? []"
                                    :has-children="$kat['has_children'] ?? false" :count="$kategorienCounts[$kat['id']] ?? null" :active="$categoryFilter === (string) $kat['id']">
                                    <button type="button" wire:click="waehleKategorie('{{ $kat['id'] }}')" class="flex-1 min-w-0 text-left truncate text-xs px-1 py-0.5">{{ $kat['name'] }}</button>
                                </x-foodalchemist::tree-node>
                            @endforeach
                        </x-foodalchemist::tree>
                    </div>
                @endif

                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">
                    + {{ $tab === 'pakete' ? 'Neues Paket' : ($showVorlagen ? 'Neue Vorlage' : 'Neues Concept') }}
                </button>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Detail" width="w-96" :maxWidth="640" storeKey="activityOpen" side="right">
            <livewire:foodalchemist.concepter.detail-panel />
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <table class="{{ $table }}">
                <thead>
                    <tr>
                        <th class="{{ $th }} w-full text-left">Name</th>
                        @if($tab === 'pakete')
                            <th class="{{ $th }} text-left">Rolle</th>
                            <th class="{{ $th }} text-left">Klasse</th>
                            <th class="{{ $th }} text-right">Gerichte</th>
                            <th class="{{ $th }} text-right">€/Person</th>
                            <th class="{{ $th }} text-right">W%</th>
                        @else
                            <th class="{{ $th }} text-left">Klasse</th>
                            <th class="{{ $th }} text-left">Kategorie</th>
                            <th class="{{ $th }} text-left">Status</th>
                            <th class="{{ $th }} text-right">Slots</th>
                            <th class="{{ $th }} text-right">€/Person</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr wire:key="row-{{ $tab }}-{{ $it->id }}" wire:click="waehle({{ $it->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity', 'open', true)"
                            class="{{ $tr }} cursor-pointer {{ $selectedId === $it->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}">
                            <td wire:click.stop="bearbeite({{ $it->id }})" class="{{ $td }} font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 cursor-pointer" title="Editor öffnen">
                                @if($it->niveau)<span class="inline-block w-1.5 h-1.5 rounded-full {{ $niveauDot[$it->niveau] ?? 'bg-gray-300' }} mr-1 align-middle" title="Niveau: {{ $it->niveau }}"></span>@endif
                                {{ $it->name }}
                                @if($tab === 'concepts' && $it->is_vorlage)<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Vorlage</span>@endif
                            </td>
                            @if($tab === 'pakete')
                                <td class="{{ $td }} text-gray-500">{{ $it->rolle ?: '—' }}</td>
                                <td class="{{ $td }} text-gray-500">{{ $it->klasse ?: '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->gerichte_count }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $it->preis_pro_person !== null ? number_format((float) $it->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->wareneinsatz_prozent !== null ? number_format((float) $it->wareneinsatz_prozent, 1, ',', '.') . ' %' : '—' }}</td>
                            @else
                                <td class="{{ $td }} text-gray-500">{{ $it->klasse ?: '—' }}</td>
                                <td class="{{ $td }} text-gray-500">{{ $it->category_id !== null ? ($katById[$it->category_id]['name'] ?? '—') : '—' }}</td>
                                <td class="{{ $td }}">
                                    <span class="{{ $pill }} {{ ['draft' => $variantPill['secondary'], 'aktiv' => $variantPill['success'], 'archiviert' => $variantPill['warning']][$it->status] ?? $variantPill['secondary'] }}">{{ ['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiv'][$it->status] ?? $it->status }}</span>
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->slots_count }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $it->preis_pro_person_cache !== null ? number_format((float) $it->preis_pro_person_cache, 2, ',', '.') . ' €' : '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-400">
                            {{ $tab === 'pakete' ? 'Keine Pakete. Oben „+ Neues Paket".' : ($showVorlagen ? 'Keine Vorlagen.' : 'Keine Concepts. Oben „+ Neues Concept".') }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $items->links() }}</div>
    </x-ui-page-container>

    {{-- Voll-Editor-Modal (M10R-3) — auf Seitenebene, öffnet via concepter-editor.oeffnen --}}
    <livewire:foodalchemist.concepter.editor />

    {{-- Phase 6: Typ-Einsehen — Basisrezept/VK-Gericht als Fenster ÜBER dem Concepter-Editor.
         Nach dem Editor platziert → stapelt obenauf (gleiche z-[100]-Konvention). --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />
</x-ui-page>
