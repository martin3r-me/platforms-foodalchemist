{{-- M10-03/04/05 / Doc 15 §M10: Concept-Editor — Slot-Gerüst, Befüllung Baustein/Gericht, Live-Preis --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Concepts" icon="heroicon-o-rectangle-stack" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Concepts" width="w-80">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Concept suchen …" class="{{ $input }}" />
                <div class="flex gap-1.5">
                    <button type="button" wire:click="$set('showVorlagen', false)"
                            class="{{ $pill }} {{ ! $showVorlagen ? $variantPill['primary'] : $variantPill['secondary'] }}">Concepts</button>
                    <button type="button" wire:click="$set('showVorlagen', true)"
                            class="{{ $pill }} {{ $showVorlagen ? $variantPill['primary'] : $variantPill['secondary'] }}">Vorlagen</button>
                </div>
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ {{ $showVorlagen ? 'Neue Vorlage' : 'Neues Concept' }}</button>

                <div class="space-y-0.5 -mx-1 pt-1">
                    @forelse($concepts as $c)
                        <div wire:key="c-{{ $c->id }}"
                             class="group flex items-center justify-between px-2 py-1 rounded-lg text-xs {{ $selectedId === $c->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                            <button type="button" wire:click="waehle({{ $c->id }})" class="min-w-0 flex-1 text-left truncate">
                                {{ $c->name }}
                                <span class="text-[10px] text-gray-400">· {{ $c->slots_count }} Slots{{ $c->preis_pro_person_cache !== null ? ' · ' . number_format((float) $c->preis_pro_person_cache, 2, ',', '.') . ' €' : '' }}</span>
                            </button>
                            @if($showVorlagen)
                                <button type="button" wire:click="ausVorlage({{ $c->id }})" class="shrink-0 text-[10px] text-violet-500 opacity-0 group-hover:opacity-100" title="Aus Vorlage starten">↧ nutzen</button>
                            @endif
                        </div>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-400">{{ $showVorlagen ? 'Keine Vorlagen.' : 'Keine Concepts. Oben „+ Neues Concept".' }}</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Live-Preis" width="w-80" :maxWidth="520" storeKey="activityOpen" side="right">
            @if($selected && $cockpit)
                <div class="p-4 space-y-3">
                    <div class="text-center py-2">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($cockpit['preis_pro_person'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">pro Person · EK {{ number_format($cockpit['ek_pro_person'], 2, ',', '.') }} €</div>
                    </div>
                    @if($cockpit['hat_leer'])<p class="{{ $pill }} {{ $variantPill['secondary'] }} w-full justify-center">Es gibt noch leere Slots</p>@endif
                    @if($cockpit['hat_stale'])<p class="{{ $pill }} {{ $variantPill['warning'] }} w-full justify-center">Ein Baustein-Preis ist veraltet</p>@endif
                    <div class="space-y-1 pt-1">
                        @foreach($cockpit['zeilen'] as $z)
                            <div class="flex items-center justify-between gap-2 text-xs py-1 border-t border-black/5 dark:border-white/10">
                                <span class="min-w-0 truncate">
                                    <span class="text-[10px] text-gray-400 uppercase mr-1">{{ $z['rolle'] ?? '—' }}</span>{{ $z['label'] }}
                                    @if($z['typ'] === 'baustein')<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Baustein</span>@elseif($z['typ'] === 'leer')<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">leer</span>@endif
                                </span>
                                <span class="shrink-0 tabular-nums {{ $z['preis'] === null ? 'text-gray-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $z['preis'] !== null ? number_format($z['preis'], 2, ',', '.') . ' €' : '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                    @unless($selected->is_vorlage)
                        <button type="button" wire:click="alsVorlage" class="{{ $btnGhost }} w-full justify-center mt-2">Als Vorlage speichern</button>
                    @endunless
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-400">Concept auswählen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($selected)
            {{-- Concept-Stammdaten --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="hdr-{{ $selected->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2">
                        <label class="{{ $label }}">Name {{ $selected->is_vorlage ? '(Vorlage)' : '' }}</label>
                        <input type="text" wire:model="form.name" class="{{ $input }}" />
                    </div>
                    <div>
                        <label class="{{ $label }}">Anlass</label>
                        <input type="text" wire:model="form.anlass" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                    </div>
                    <div>
                        <label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">
                            @foreach(['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                    <button type="button" wire:click="loeschen({{ $selected->id }})" wire:confirm="Concept löschen?" class="{{ $btnGhost }} text-red-600 dark:text-red-400">Löschen</button>
                </div>
            </div>

            {{-- Slot-Gerüst --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Slots</h3>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="neuerSlotRolle" placeholder="Rolle, z. B. Vorspeise" class="{{ $input }} w-48" />
                        <button type="button" wire:click="slotHinzu" class="{{ $btnGhost }}">+ Slot</button>
                    </div>
                </div>

                <div class="space-y-2">
                    @forelse($selected->slots as $slot)
                        <div wire:key="slot-{{ $slot->id }}" class="rounded-xl border border-black/5 dark:border-white/10 p-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.rolle" wire:change="slotSpeichern({{ $slot->id }})"
                                       class="{{ $input }} w-40" placeholder="Rolle" />
                                <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.titel" wire:change="slotSpeichern({{ $slot->id }})"
                                       class="{{ $input }} flex-1" placeholder="Titel (optional)" />
                                <button type="button" wire:click="slotRaus({{ $slot->id }})" class="text-gray-400 hover:text-red-500 px-2" title="Slot entfernen">✕</button>
                            </div>

                            {{-- Befüllung --}}
                            <div class="flex flex-wrap items-center gap-2">
                                @if($slot->baustein_id && $slot->baustein)
                                    <span class="{{ $pill }} {{ $variantPill['info'] }}">Baustein</span>
                                    <span class="text-sm font-medium">{{ $slot->baustein->name }}</span>
                                    <span class="text-gray-400 text-xs tabular-nums">{{ $slot->baustein->preis_pro_person !== null ? number_format((float) $slot->baustein->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</span>
                                @elseif($slot->vk_recipe_id && $slot->gericht)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">festes Gericht</span>
                                    <span class="text-sm font-medium">{{ $slot->gericht->name }}</span>
                                    <span class="text-gray-400 text-xs tabular-nums">{{ $slot->gericht->vk_netto !== null ? number_format((float) $slot->gericht->vk_netto, 2, ',', '.') . ' €' : '—' }}</span>
                                @else
                                    <span class="text-xs text-gray-400">leer — Baustein wählen oder festes Gericht setzen</span>
                                @endif
                                @if($slot->baustein_id || $slot->vk_recipe_id)
                                    <button type="button" wire:click="slotLeeren({{ $slot->id }})" class="text-[11px] text-gray-400 hover:text-red-500">leeren</button>
                                @endif
                            </div>

                            {{-- Steuerung: Baustein (gleiche Rolle) wählen ODER festes Gericht suchen --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <select x-on:change="$wire.fuelleBaustein({{ $slot->id }}, $event.target.value); $event.target.value=''"
                                        class="{{ $input }} w-56">
                                    <option value="">↹ Baustein (Rolle {{ $slot->rolle ?: '–' }}) …</option>
                                    @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}{{ $b->preis_pro_person !== null ? ' (' . number_format((float) $b->preis_pro_person, 2, ',', '.') . ' €)' : '' }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="gerichtPicker({{ $slot->id }})" class="{{ $btnGhostXs }}">festes Gericht …</button>
                            </div>

                            @if($fillSlotId === $slot->id)
                                <div class="space-y-1 pl-1">
                                    <input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen …" class="{{ $input }}" autofocus />
                                    @if($gerichtSuche !== '' && $kandidaten->isNotEmpty())
                                        <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                            @foreach($kandidaten as $k)
                                                <button type="button" wire:key="k-{{ $slot->id }}-{{ $k->id }}" wire:click="fuelleGericht({{ $slot->id }}, {{ $k->id }})"
                                                        class="w-full flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                                    <span class="truncate">{{ $k->name }}</span>
                                                    <span class="text-gray-400 tabular-nums shrink-0">{{ $k->vk_netto !== null ? number_format((float) $k->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 py-4 text-center">Noch keine Slots. Oben eine Rolle eintragen und „+ Slot".</p>
                    @endforelse
                </div>
            </div>
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-400">
                Links ein Concept auswählen oder „+ Neues Concept" anlegen. Vorlagen lassen sich per „↧ nutzen" zu einem eigenständigen Concept forken.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
