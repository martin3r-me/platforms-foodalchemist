{{-- D-6 §4.6: VK-Taxonomie — Master-Detail (Speisen-HG links, Klassen-Tabelle rechts); HG + Klassen anlegbar (#372) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($katHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<div class="space-y-4" data-settings-vk-taxonomie>
    @if($meldung !== null)<div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400" data-taxo-meldung>{{ $meldung }}</p></div>@endif
    @if($fehler !== null)<div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400" data-taxo-fehler>{{ $fehler }}</p></div>@endif

    <div class="flex gap-4 items-start">
        {{-- Speisen-Hauptgruppen links --}}
        <div class="w-80 shrink-0 {{ $card }} p-3 space-y-0.5" data-taxo-hgs>
            <div class="{{ $label }} px-2 pb-2">Speisen-Hauptgruppen ({{ $hauptgruppen->count() }})</div>
            @foreach($hauptgruppen as $hg)
                @php($darfEditHg = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $hg))
                @php($nKl = $klassenJeHg[$hg->id] ?? 0)
                <div wire:key="thg-{{ $hg->id }}" class="group flex items-center gap-1 rounded-lg {{ $hauptgruppeId === $hg->id ? $katAktiv : $katHover }} {{ $hg->is_inactive ? 'opacity-50' : '' }}">
                    @if($hgEditId === $hg->id)
                        <input type="text" wire:model="hgEditName" wire:keydown.enter="hgSave" wire:keydown.escape="$set('hgEditId', null)" class="{{ $input }} !py-0.5 flex-1" autofocus />
                        <button type="button" wire:click="hgSave" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0">OK</button>
                    @else
                        <button type="button" wire:click="waehleHg({{ $hg->id }})" class="flex-1 min-w-0 flex items-center gap-1.5 text-left px-2 py-1.5 text-xs">
                            <span class="font-mono text-[10px] text-gray-400">{{ $hg->code }}</span>
                            <span class="min-w-0 truncate">{{ $hg->label }}</span>
                        </button>
                        <span class="text-[11px] text-gray-400 shrink-0">{{ $nKl }}</span>
                        @if($darfEditHg)
                            <button type="button" wire:click="startHgEdit({{ $hg->id }}, @js($hg->label))" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px] px-1" title="Umbenennen">✎</button>
                            <button type="button" wire:click="hgDelete({{ $hg->id }})" wire:confirm="Diese Hauptgruppe löschen?" @disabled($nKl > 0)
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-[11px] px-1 {{ $nKl > 0 ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-red-500' }}"
                                    title="{{ $nKl > 0 ? 'Hat Klassen — erst dort entfernen' : 'löschen' }}">✕</button>
                        @endif
                    @endif
                </div>
            @endforeach
            <div class="pt-2 mt-1 border-t border-black/5 dark:border-white/10 flex items-center gap-1.5">
                <input type="text" wire:model="neuHg" wire:keydown.enter="createHg" placeholder="Neue Hauptgruppe …" class="{{ $input }} flex-1" />
                <button type="button" wire:click="createHg" class="{{ $btnGhostXs }}">+ HG</button>
            </div>
            <p class="text-[10px] text-gray-400 px-2 pt-2 leading-snug">Kategorie = Hauptgruppe (trägt den Aufschlag). Klasse = Diätform (4, global). Zähler = Gerichte je HG. Aufschlagsklassen, Schreibstile, Behälter: eigene Seiten (R5).</p>
        </div>

        {{-- Klassen der gewählten HG rechts --}}
        <div class="flex-1 min-w-0">
            <div class="relative overflow-hidden {{ $card }}" data-taxo-klassen>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Klassen = Diätformen</h3>
                    <span class="{{ $label }}">4 global (HG-unabhängig)</span>
                </div>
                @if($klassen->isNotEmpty())
                    <table class="{{ $table }}">
                        <thead><tr class="text-left">@foreach(['Klasse', 'Diätform', 'Diät-Flags', 'Rezepte', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($klassen as $k)
                                @php($darfEditK = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $k))
                                @php($nRez = $klassenZaehler[$k->id] ?? 0)
                                <tr class="{{ $tr }}" wire:key="tk-{{ $k->id }}">
                                    @if($klasseEditId === $k->id)
                                        <td class="{{ $td }}" colspan="3"><input type="text" wire:model="klasseEditName" wire:keydown.enter="klasseSave" wire:keydown.escape="$set('klasseEditId', null)" class="{{ $input }} !py-1" autofocus /></td>
                                        <td class="{{ $td }} text-gray-500">{{ $nRez }}</td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            <button type="button" wire:click="klasseSave" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">OK</button>
                                            <button type="button" wire:click="$set('klasseEditId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                        </td>
                                    @else
                                        <td class="{{ $td }} text-gray-900 dark:text-gray-100">{{ $k->label }} <span class="text-[10px] font-mono text-gray-400">{{ $k->code }}</span></td>
                                        <td class="{{ $td }}"><span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $k->diet_form }}</span></td>
                                        <td class="{{ $td }} text-[11px] text-gray-400">{{ collect(['vegan' => $k->is_vegan, 'vegi' => $k->is_vegi, 'halal' => $k->is_halal, 'koscher' => $k->is_koscher])->filter()->keys()->implode(' · ') ?: '—' }}</td>
                                        <td class="{{ $td }} text-gray-500">{{ $nRez }}</td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            @if($darfEditK)
                                                <button type="button" wire:click="startKlasseEdit({{ $k->id }}, @js($k->label))" class="{{ $btnGhostXs }}">Umbenennen</button>
                                                <button type="button" wire:click="klasseDelete({{ $k->id }})" wire:confirm="Diese Klasse löschen?" @disabled($nRez > 0)
                                                        class="{{ $btnGhostXs }} {{ $nRez > 0 ? 'opacity-40 cursor-not-allowed' : 'text-red-500' }}"
                                                        title="{{ $nRez > 0 ? 'Wird von Gerichten genutzt' : 'löschen' }}">Löschen</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-5 pb-5 text-xs text-gray-400">Links eine Speisen-Hauptgruppe wählen, um ihre Klassen zu sehen.</div>
                @endif
                <div class="px-5 py-3 border-t border-black/5 dark:border-white/10 text-[11px] text-gray-400">
                    Die Diätform wird am Gericht gewählt (Modell A) — Klassen sind fix: Fleisch · Fisch · Vegetarisch · Vegan.
                </div>
            </div>
        </div>
    </div>
</div>
