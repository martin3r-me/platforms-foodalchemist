{{-- M14-03 / Speiseplan v2 — Menü-Linien × echte Wochentage × Mahlzeit + Monats-Kalender --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($hover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')
@php($tagKurz = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'])
@php($monatNamen = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Speiseplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speiseplan'],
        ]" />
    </x-slot>

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
                            <span class="text-[10px] text-gray-400">{{ $p->zyklus_wochen }} Wo.-Zyklus · {{ $p->eintraege_count }} Einträge</span>
                        </button>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-400">Noch keine Speisepläne.</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Kennzahlen" width="w-80" :maxWidth="480" storeKey="activityOpen" side="right">
            @if($sp && $kosten)
                <div class="p-4 space-y-3">
                    <div class="text-center py-2">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($kosten['woche']['vk'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">VK/Person · Woche ({{ $mahlzeiten[$mahlzeit] ?? '' }}) · EK {{ number_format($kosten['woche']['ek'], 2, ',', '.') }} €</div>
                    </div>

                    @if($veggie && $veggie['active'])
                        <div class="pt-2 border-t border-black/5 dark:border-white/10">
                            @if($veggie['erfuellt'])
                                <p class="text-[11px] {{ $pill }} {{ $variantPill['success'] }} w-full justify-center">✓ Vegetarisch an jedem Werktag</p>
                            @else
                                <p class="text-[11px] {{ $pill }} {{ $variantPill['warning'] }} w-full justify-between"><span>Veggie fehlt</span><span>{{ count($veggie['fehltage']) }} Tag(e)</span></p>
                            @endif
                        </div>
                    @endif

                    <div class="pt-2 border-t border-black/5 dark:border-white/10 space-y-1">
                        @if(!empty($wiederholungen))
                            <div class="{{ $label }} text-amber-600 dark:text-amber-400">Wiederholungen ({{ count($wiederholungen) }})</div>
                            @foreach($wiederholungen as $w)
                                <p class="text-[11px] {{ $variantPill['warning'] }} {{ $pill }} w-full justify-between"><span class="truncate">{{ $w['name'] }}</span><span class="shrink-0 ml-2">{{ $w['vorkommen'] }}× · {{ $w['min_abstand'] }} T.</span></p>
                            @endforeach
                        @else
                            <p class="text-[11px] text-gray-400 text-center">Keine Wiederholungs-Konflikte.</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-400">Plan auswählen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($sp)
            {{-- Plan-Stammdaten --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="sphdr-{{ $sp->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Start (Montag)</label><input type="date" wire:model.live="form.start_date" wire:change="speichern" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Zyklus (Wochen)</label><input type="number" min="1" wire:model.live="form.zyklus_wochen" wire:change="speichern" class="{{ $input }} text-right tabular-nums" /></div>
                    <div><label class="{{ $label }}">Min. Abstand (T.)</label><input type="number" min="0" wire:model.live="form.min_abstand_tage" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="0 = keine Wiederholungsregel" /></div>
                    <div><label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" wire:change="speichern" class="{{ $input }}">@foreach(['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                    <button type="button" wire:click="loeschen({{ $sp->id }})" wire:confirm="Speiseplan löschen?" class="{{ $btnGhost }} text-red-600 dark:text-red-400">Löschen</button>
                    <span class="flex items-center gap-2 ml-auto">
                        <input type="date" wire:model="ausrollenBis" class="{{ $input }} w-40" title="Zyklus-Vorlage bis zu diesem Datum ausrollen" />
                        <button type="button" wire:click="ausrollen" class="{{ $btnGhost }}" title="Den {{ $sp->zyklus_wochen }}-Wochen-Block ab Start auf alle Folgewochen kopieren">⟳ Zyklus ausrollen</button>
                    </span>
                </div>
                @if($ausrollenInfo)<p class="text-[11px] text-violet-600 dark:text-violet-400">{{ $ausrollenInfo }}</p>@endif
            </div>

            {{-- Menü-Linien-Editor --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-2" wire:key="splinien-{{ $sp->id }}">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Menü-Linien</h3>
                    <span class="text-[11px] text-gray-400">Zeilen der Matrix · pro Plan frei</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach($linien as $linie)
                        <div wire:key="linie-{{ $linie->id }}" class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded-lg border border-black/5 dark:border-white/10">
                            <span class="w-3 h-3 rounded-full shrink-0" style="background: {{ $linie->color ?: '#888780' }}"></span>
                            <span class="text-xs">{{ $linie->name }}</span>
                            @if($linie->is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg</span>@endif
                            <button type="button" wire:click="linieVerschieben({{ $linie->id }}, -1)" class="text-gray-300 hover:text-violet-500 text-[10px]" title="hoch">▲</button>
                            <button type="button" wire:click="linieVerschieben({{ $linie->id }}, 1)" class="text-gray-300 hover:text-violet-500 text-[10px]" title="runter">▼</button>
                            <button type="button" wire:click="linieEdit({{ $linie->id }})" class="text-gray-400 hover:text-violet-500 text-xs" title="bearbeiten">✎</button>
                            <button type="button" wire:click="linieRaus({{ $linie->id }})" wire:confirm="Linie entfernen? Einträge bleiben (ohne Linie)." class="text-gray-400 hover:text-red-500 text-xs" title="entfernen">✕</button>
                        </div>
                    @endforeach
                    <div class="flex items-center gap-1">
                        <input type="text" wire:model="neueLinie" wire:keydown.enter="linieAdd" placeholder="+ Linie …" class="{{ $input }} w-32 h-8 text-xs" />
                        <button type="button" wire:click="linieAdd" class="{{ $btnGhostXs }}">+</button>
                    </div>
                </div>
                @if($editLinieId !== null)
                    <div class="mt-1 pt-2 border-t border-black/5 dark:border-white/10 flex flex-wrap items-end gap-2">
                        <div><label class="{{ $label }}">Name</label><input type="text" wire:model="linieForm.name" class="{{ $input }} w-44 h-8" /></div>
                        <div><label class="{{ $label }}">Farbe</label><input type="color" wire:model="linieForm.color" class="h-8 w-12 rounded border border-black/10 dark:border-white/10 bg-transparent" /></div>
                        <label class="flex items-center gap-1.5 text-xs pb-1.5"><input type="checkbox" wire:model="linieForm.is_vegetarian" /> vegetarisch</label>
                        <button type="button" wire:click="linieSpeichern" class="{{ $btnPrimary }} h-8">OK</button>
                        <button type="button" wire:click="$set('editLinieId', null)" class="{{ $btnGhost }} h-8">Abbrechen</button>
                    </div>
                @endif
            </div>

            {{-- Toolbar: Ansicht + Mahlzeit + Navigation --}}
            <div class="flex items-center gap-3 flex-wrap">
                <span class="inline-flex rounded-lg overflow-hidden border border-black/10 dark:border-white/10">
                    @foreach(['woche' => 'Woche', 'monat' => 'Monat'] as $av => $al)
                        <button type="button" wire:click="ansichtSetzen('{{ $av }}')" class="px-3 py-1.5 text-xs {{ $ansicht === $av ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300 font-medium' : 'text-gray-500 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">{{ $al }}</button>
                    @endforeach
                </span>
                <span class="flex items-center gap-1">
                    @foreach($mahlzeiten as $mk => $ml)
                        <button type="button" wire:click="mahlzeitSetzen('{{ $mk }}')" class="{{ $pill }} {{ $mahlzeit === $mk ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $ml }}</button>
                    @endforeach
                </span>
                <span class="flex items-center gap-2 ml-auto">
                    @if($ansicht === 'woche')
                        <button type="button" wire:click="wocheVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                        <span class="text-sm font-medium tabular-nums">KW {{ (int) $montagDt->format('W') }} · {{ $montagDt->format('d.m.') }}–{{ $montagDt->copy()->addDays(4)->format('d.m.Y') }}</span>
                        <button type="button" wire:click="wocheVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                        <button type="button" wire:click="heute" class="{{ $btnGhostXs }}">Heute</button>
                    @else
                        <button type="button" wire:click="monatVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                        <span class="text-sm font-medium">{{ $monatNamen[(int) $monatStart->month] }} {{ $monatStart->year }}</span>
                        <button type="button" wire:click="monatVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                    @endif
                </span>
            </div>

            @if($ansicht === 'woche')
                {{-- Wochen-Matrix: Linien × Mo–Fr --}}
                <div class="relative overflow-hidden {{ $card }} p-5">
                    <div class="overflow-x-auto">
                        <table class="{{ $table }}" style="table-layout:fixed; width:100%; min-width:600px;">
                            <thead><tr class="text-left">
                                <th class="{{ $th }}" style="width:104px">Linie</th>
                                @foreach($wochenTage as $tag)
                                    <th class="{{ $th }} text-center {{ $tag->isToday() ? 'text-violet-600 dark:text-violet-300' : '' }}">{{ $tagKurz[$tag->isoWeekday()] }} <span class="text-gray-400 font-normal">{{ $tag->format('d.m.') }}</span></th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                @php($zeilenLinien = $linien->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values())
                                @if(isset($raster[0]))@php($zeilenLinien->push(['id' => 0, 'name' => 'Ohne Linie', 'color' => null]))@endif
                                @foreach($zeilenLinien as $zl)
                                    <tr class="border-t border-black/5 dark:border-white/10 align-top">
                                        <td class="{{ $td }} whitespace-nowrap">
                                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $zl['color'] ?: '#888780' }}"></span><span class="font-medium text-gray-600 dark:text-gray-300">{{ $zl['name'] }}</span></span>
                                        </td>
                                        @foreach($wochenTage as $tag)
                                            @php($ymd = $tag->format('Y-m-d'))
                                            @php($eintraege = $raster[$zl['id']][$ymd] ?? [])
                                            <td class="{{ $td }} align-top {{ ($cellDatum === $ymd && $cellLinie === ($zl['id'] ?: null)) ? 'bg-violet-500/5 rounded-lg' : '' }}">
                                                <div class="space-y-0.5">
                                                    @foreach($eintraege as $e)
                                                        <div wire:key="e-{{ $e->id }}" class="group flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px]"
                                                             style="background: {{ ($zl['color'] ?? null) ? $zl['color'].'22' : 'rgba(0,0,0,0.04)' }}">
                                                            <span class="flex-1 min-w-0 truncate" title="{{ $e->inhaltName() }}">{{ $e->inhaltName() }}</span>
                                                            <button type="button" wire:click="eintragRaus({{ $e->id }})" class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 shrink-0">✕</button>
                                                        </div>
                                                    @endforeach
                                                    @if($zl['id'] !== 0)
                                                        <button type="button" wire:click="zelleOeffnen('{{ $ymd }}', {{ $zl['id'] }})" class="w-full text-[11px] text-gray-400 hover:text-violet-500 rounded border border-dashed border-black/10 dark:border-white/10 py-0.5">+</button>
                                                    @endif
                                                </div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                @if($zeilenLinien->isEmpty())
                                    <tr><td colspan="6" class="{{ $td }} text-center text-gray-400 text-xs py-4">Oben eine Menü-Linie anlegen, dann Gerichte in die Tage ziehen.</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>

                    {{-- Inhalts-Picker für die aktive Zelle (inline, Livewire-sicher) --}}
                    @if($cellDatum !== null)
                        <div class="mt-3 pt-3 border-t border-black/5 dark:border-white/10 space-y-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="{{ $label }}">Einfügen · {{ \Illuminate\Support\Carbon::parse($cellDatum)->format('d.m.') }} · {{ $linien->firstWhere('id', $cellLinie)?->name ?? '—' }}:</span>
                                @foreach(['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'] as $tv => $tl)
                                    <button type="button" wire:click="$set('pickerTyp', '{{ $tv }}')" class="{{ $pill }} {{ $pickerTyp === $tv ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $tl }}</button>
                                @endforeach
                                <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ ['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'][$pickerTyp] }} suchen …" class="{{ $input }} w-56" />
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
                            @elseif($pickerSuche !== '')
                                <p class="text-[11px] text-gray-400">Keine Treffer.</p>
                            @endif
                        </div>
                    @endif
                </div>
            @else
                {{-- Monats-Kalender --}}
                @php($gridStart = $monatStart->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY))
                <div class="relative overflow-hidden {{ $card }} p-5">
                    <div style="display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:4px;">
                        @foreach([1,2,3,4,5,6,7] as $wd)
                            <div class="text-center {{ $label }} pb-1">{{ $tagKurz[$wd] }}</div>
                        @endforeach
                        @for($i = 0; $i < 42; $i++)
                            @php($tag = $gridStart->copy()->addDays($i))
                            @php($ymd = $tag->format('Y-m-d'))
                            @php($imMonat = (int) $tag->month === (int) $monatStart->month)
                            @php($info = $monatsRaster[$ymd] ?? null)
                            <button type="button" wire:key="cal-{{ $ymd }}" wire:click="tagOeffnen('{{ $ymd }}')"
                                    class="text-left rounded-lg border p-1.5 h-20 transition-colors {{ $imMonat ? 'border-black/5 dark:border-white/10 hover:bg-violet-500/5' : 'border-transparent opacity-40' }} {{ $tag->isToday() ? 'ring-1 ring-violet-400' : '' }}">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] {{ $tag->isToday() ? 'text-violet-600 dark:text-violet-300 font-semibold' : 'text-gray-500' }}">{{ $tag->format('j') }}</span>
                                    @if($info)<span class="{{ $pill }} {{ $variantPill['secondary'] }} text-[9px]">{{ $info['count'] }}</span>@endif
                                </div>
                                @if($info && $info['vk'] > 0)
                                    <div class="mt-1 text-[10px] text-gray-400 tabular-nums">{{ number_format($info['vk'], 2, ',', '.') }} €</div>
                                @endif
                            </button>
                        @endfor
                    </div>
                    <p class="mt-3 text-[11px] text-gray-400">Tag anklicken → springt in die Wochenansicht. Belegung der Mahlzeit „{{ $mahlzeiten[$mahlzeit] ?? '' }}".</p>
                </div>
            @endif
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-400">
                Links einen Speiseplan wählen oder „+ Neuer Plan". Speiseplan v2 verteilt Gerichte/Concepts/Pakete über <strong>Menü-Linien × echte Wochentage</strong> — mit Monats-Kalender, Kosten/Person, Veggie-Tagescheck und ausrollbarem Wochen-Zyklus.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
