{{-- M10R-2 / Doc 15 §10.2+§10.4: vereinheitlichter Concepter-Browser (Concepts | Pakete in einem Screen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($tabAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($filterHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')
@php($niveauDot = ['klassisch' => 'bg-sky-400', 'gehoben' => 'bg-amber-400', 'haute' => 'bg-violet-500'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Concepter" icon="heroicon-o-square-3-stack-3d" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Concepter'],
        ]" />
    </x-slot>

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
                    {{-- Facetten-Filter (Umbau-Spec Phase 4b): Eventtyp · Servierform · Einsatzmoment · Saison --}}
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Eventtyp</span>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" wire:click="waehleFacette('eventtypFilter', '')" class="{{ $pill }} {{ $eventtypFilter === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                            @foreach($facetteEventtypen as $et)
                                <button type="button" wire:key="fev-{{ $et->id }}" wire:click="waehleFacette('eventtypFilter', '{{ $et->id }}')"
                                        class="{{ $pill }} {{ $eventtypFilter === (string) $et->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $et->name }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Servierform</span>
                        <div class="flex flex-wrap gap-1">
                            @foreach($facetteServierformen as $sf)
                                <button type="button" wire:key="fsf-{{ $sf->id }}" wire:click="waehleFacette('servierformFilter', '{{ $sf->id }}')"
                                        class="{{ $pill }} {{ $servierformFilter === (string) $sf->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sf->label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Einsatzmoment</span>
                        <div class="flex flex-wrap gap-1">
                            @foreach($facetteMomente as $em)
                                <button type="button" wire:key="fem-{{ $em->id }}" wire:click="waehleFacette('momentFilter', '{{ $em->id }}')"
                                        class="{{ $pill }} {{ $momentFilter === (string) $em->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $em->name }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="space-y-0.5 pt-2 border-t border-black/5 dark:border-white/10">
                        <span class="{{ $label }}">Saison</span>
                        <div class="flex flex-wrap gap-1">
                            @foreach($facetteSaisons as $sa)
                                <button type="button" wire:key="fsa-{{ $sa->id }}" wire:click="waehleFacette('saisonFilter', '{{ $sa->id }}')"
                                        class="{{ $pill }} {{ $saisonFilter === (string) $sa->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sa->name }}</button>
                            @endforeach
                        </div>
                    </div>

                    {{-- 4c (Umbau-Spec F6, 2026-07-03): Kategorie-Baum abgelöst — Eventtyp/Servierform/
                         Einsatzmoment/Saison-Facetten übernehmen die Filter-Achse. Daten + Settings-Pflege
                         (konzept-taxonomie) bleiben; Foodbook-Picker unberührt. --}}
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
                            <th class="{{ $th }} text-left">Eventtyp · Servierform</th>
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
                                @if($it->level)<span class="inline-block w-1.5 h-1.5 rounded-full {{ $niveauDot[$it->level] ?? 'bg-gray-300' }} mr-1 align-middle" title="Niveau: {{ $it->level }}"></span>@endif
                                {{ $it->name }}
                                @if($tab === 'concepts' && $it->is_template)<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Vorlage</span>@endif
                            </td>
                            @if($tab === 'pakete')
                                <td class="{{ $td }} text-gray-500">{{ $it->role ?: '—' }}</td>
                                <td class="{{ $td }} text-gray-500">{{ $it->class ?: '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->gerichte_count }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $it->price_per_person !== null ? number_format((float) $it->price_per_person, 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->food_cost_percent !== null ? number_format((float) $it->food_cost_percent, 1, ',', '.') . ' %' : '—' }}</td>
                            @else
                                <td class="{{ $td }} text-gray-500">{{ $it->class ?: '—' }}</td>
                                <td class="{{ $td }} text-gray-500">{{ collect([$it->eventType?->name, $it->servingForm?->label])->filter()->join(' · ') ?: '—' }}</td>
                                {{-- Inline-Status-Pflege wie bei GP (Concepts; Server gated canCurate/D1) --}}
                                <td class="{{ $td }} whitespace-nowrap" wire:click.stop @click.stop>
                                    @php($stMap = ['draft' => $variantPill['secondary'], 'active' => $variantPill['success'], 'archiviert' => $variantPill['warning']])
                                    @if(! isset($it->team_id) || \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $it))
                                        <select wire:key="cst-{{ $it->id }}-{{ $it->status }}" wire:change="statusSetzen({{ $it->id }}, $event.target.value)"
                                                class="{{ $pill }} font-medium {{ $stMap[$it->status] ?? $variantPill['secondary'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6" data-status-select>
                                            @foreach(['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'] as $val => $lbl)
                                                <option value="{{ $val }}" @selected($it->status === $val)>{{ $lbl }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="{{ $pill }} {{ $stMap[$it->status] ?? $variantPill['secondary'] }}">{{ ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'][$it->status] ?? $it->status }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->slots_count }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $it->price_per_person_cache !== null ? number_format((float) $it->price_per_person_cache, 2, ',', '.') . ' €' : '—' }}</td>
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
