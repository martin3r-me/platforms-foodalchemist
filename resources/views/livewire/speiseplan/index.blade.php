{{-- M14-02 / Doc 15 §M14: Speiseplan-Raster (Woche × Wochentag × Mahlzeit) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($hover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Speiseplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisepläne" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Plan suchen …" class="{{ $input }}" />
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ Neuer Plan</button>
                <div class="space-y-0.5 -mx-1">
                    @forelse($plaene as $p)
                        <button type="button" wire:key="sp-{{ $p->id }}" wire:click="waehle({{ $p->id }})"
                                class="w-full text-left px-2 py-1 rounded-lg text-xs {{ $selectedId === $p->id ? $aktiv : $hover }}">
                            <span class="truncate block">{{ $p->name }}</span>
                            <span class="text-[10px] text-gray-400">{{ $p->zyklus_wochen }} Wo. · {{ $p->eintraege_count }} Einträge</span>
                        </button>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-400">Noch keine Speisepläne.</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Kosten & Hinweise" width="w-80" :maxWidth="480" storeKey="activityOpen" side="right">
            @if($sp && $kosten)
                <div class="p-4 space-y-3">
                    <div class="text-center py-2">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($kosten['gesamt']['vk'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">VK/Person über den Zyklus · EK {{ number_format($kosten['gesamt']['ek'], 2, ',', '.') }} €</div>
                    </div>
                    @if(!empty($wiederholungen))
                        <div class="pt-2 border-t border-black/5 dark:border-white/10 space-y-1">
                            <div class="{{ $label }} text-amber-600 dark:text-amber-400">Wiederholungen ({{ count($wiederholungen) }})</div>
                            @foreach($wiederholungen as $w)
                                <p class="text-[11px] {{ $variantPill['warning'] }} {{ $pill }} w-full justify-between"><span class="truncate">{{ $w['name'] }}</span><span class="shrink-0 ml-2">{{ $w['vorkommen'] }}× · {{ $w['min_abstand'] }} T. Abstand</span></p>
                            @endforeach
                        </div>
                    @else
                        <p class="text-[11px] text-gray-400 text-center">Keine Wiederholungs-Konflikte.</p>
                    @endif
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-400">Plan auswählen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($sp)
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="sphdr-{{ $sp->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Zyklus (Wochen)</label><input type="number" min="1" wire:model.live="form.zyklus_wochen" wire:change="speichern" class="{{ $input }} text-right tabular-nums" /></div>
                    <div><label class="{{ $label }}">Min. Abstand (Tage)</label><input type="number" min="0" wire:model.live="form.min_abstand_tage" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="0 = keine Wiederholungsregel" /></div>
                    <div><label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">@foreach(['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                    <button type="button" wire:click="loeschen({{ $sp->id }})" wire:confirm="Speiseplan löschen?" class="{{ $btnGhost }} text-red-600 dark:text-red-400">Löschen</button>
                    @if($sp->zyklus_wochen > 1)
                        <span class="ml-2 flex items-center gap-1">
                            @for($w = 1; $w <= $sp->zyklus_wochen; $w++)
                                <button type="button" wire:click="wocheSetzen({{ $w }})" class="{{ $pill }} {{ $woche === $w ? $variantPill['primary'] : $variantPill['secondary'] }}">Wo {{ $w }}</button>
                            @endfor
                        </span>
                    @endif
                </div>
            </div>

            {{-- Raster Woche {{ $woche }} --}}
            <div class="relative overflow-hidden {{ $card }} p-5">
                <div class="overflow-x-auto">
                    <table class="{{ $table }} min-w-[760px]">
                        <thead><tr class="text-left">
                            <th class="{{ $th }} w-24">Woche {{ $woche }}</th>
                            @foreach($wochentage as $tag => $tagLabel)<th class="{{ $th }} text-center">{{ $tagLabel }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @foreach($mahlzeiten as $mKey => $mLabel)
                                <tr class="border-t border-black/5 dark:border-white/10 align-top">
                                    <td class="{{ $td }} font-medium text-gray-500 whitespace-nowrap">{{ $mLabel }}</td>
                                    @foreach($wochentage as $tag => $tagLabel)
                                        @php($eintraege = $raster[$woche][$tag][$mKey] ?? [])
                                        <td class="{{ $td }} {{ ($cellTag === $tag && $cellMahlzeit === $mKey) ? 'bg-violet-500/5 rounded-lg' : '' }}" style="min-width: 100px">
                                            <div class="space-y-0.5">
                                                @foreach($eintraege as $e)
                                                    <div wire:key="e-{{ $e->id }}" class="group flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/[0.04] dark:bg-white/10 text-[11px]">
                                                        <span class="flex-1 min-w-0 truncate" title="{{ $e->inhaltName() }}">{{ $e->inhaltName() }}</span>
                                                        <button type="button" wire:click="eintragRaus({{ $e->id }})" class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 shrink-0">✕</button>
                                                    </div>
                                                @endforeach
                                                <button type="button" wire:click="zelleOeffnen({{ $tag }}, '{{ $mKey }}')" class="w-full text-[11px] text-gray-400 hover:text-violet-500 rounded border border-dashed border-black/10 dark:border-white/10 py-0.5">+</button>
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Inhalts-Picker für die aktive Zelle --}}
                @if($cellTag !== null && $cellMahlzeit !== null)
                    <div class="mt-3 pt-3 border-t border-black/5 dark:border-white/10 space-y-2">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="{{ $label }}">Einfügen in {{ $wochentage[$cellTag] ?? '' }} · {{ $mahlzeiten[$cellMahlzeit] ?? '' }}:</span>
                            @foreach(['concept' => 'Concept', 'paket' => 'Paket', 'gericht' => 'Gericht'] as $tv => $tl)
                                <button type="button" wire:click="$set('pickerTyp', '{{ $tv }}')" class="{{ $pill }} {{ $pickerTyp === $tv ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $tl }}</button>
                            @endforeach
                            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ ['concept' => 'Concept', 'paket' => 'Paket', 'gericht' => 'Gericht'][$pickerTyp] }} suchen …" class="{{ $input }} w-56" />
                            <button type="button" wire:click="cellSchliessen" class="{{ $btnGhostXs }}">schließen</button>
                        </div>
                        @if($kandidaten->isNotEmpty())
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-1">
                                @foreach($kandidaten as $k)
                                    <button type="button" wire:key="kand-{{ $pickerTyp }}-{{ $k->id }}" wire:click="inhaltHinzu('{{ $pickerTyp }}', {{ $k->id }})"
                                            class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                        <span class="truncate">{{ $k->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-400">
                Links einen Speiseplan wählen oder „+ Neuer Plan". Der Speiseplan verteilt dieselben Bausteine (Concepts/Pakete/Gerichte) über die Zeitachse — Tag × Mahlzeit, optional als Wochen-Zyklus.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
