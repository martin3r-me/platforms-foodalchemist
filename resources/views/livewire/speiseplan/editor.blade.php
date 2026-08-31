{{-- Speiseplan-Editor (Fullscreen-Dark, pro Plan) — Kalender (Wochen-Matrix/Monat + Inline-Picker)
     · Menü-Linien · Stammdaten (+ Zyklus-Ausrollen). Rechts eine Live-Kennzahlen-Rail
     (VK/EK · Veggie-Tagescheck · Wiederholungs-Konflikte), die bei jeder Zellen-Änderung
     mitrechnet. Herausgezogen aus dem Master-Detail-Vollbild (Speiseplan\Index). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($tagKurz = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'])
@php($monatNamen = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'])

<x-foodalchemist::modal name="speiseplan-editor" fullscreen dark-canvas title="Speiseplan bearbeiten"
    :title-name="$sp?->name">

    <x-slot:actions>
        @if($sp)
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-sp-speichern>Speichern</button>
            {{-- P5: Voll-Kaskade — leere Zellen (Mo–Fr × Mittag × Linien) mit erfundenen Gerichten füllen. --}}
            <button type="button" wire:click="vollKaskadeStarten" class="{{ $btnPrimary }}" wire:loading.attr="disabled" data-sp-voll-kaskade>
                <span wire:loading.remove wire:target="vollKaskadeStarten">@svg('heroicon-o-bolt', 'w-4 h-4 inline-block align-middle') Voll-Kaskade</span>
                <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
            </button>
            <button type="button" wire:click="loeschen({{ $sp->id }})" wire:confirm="Speiseplan löschen?" class="{{ $btnGhostXs }} text-red-600" data-sp-loeschen>Löschen</button>
            @if($ausrollenInfo)<span class="text-[12px] text-violet-300 ml-2 self-center" data-sp-ausrollen-info>{{ $ausrollenInfo }}</span>@endif
            @if($kaskadeMeldung)<span class="text-[12px] text-amber-300 ml-2 self-center">{{ $kaskadeMeldung }}</span>@endif
            @if($prodHinweis)<span class="text-[12px] text-emerald-300 ml-2 self-center" data-sp-prod-hinweis>✓ {{ $prodHinweis }}</span>@endif
            @if($prodFehler)<span class="text-[12px] text-rose-300 ml-2 self-center" data-sp-prod-fehler>{{ $prodFehler }}</span>@endif
        @endif
    </x-slot:actions>

    @if($sp && $kosten)
        @php($kfOk = collect($kostformen)->where('erfuellt', true)->count())
        @php($kfN = count($kostformen))
        <x-slot:kpiHeader>
            <x-foodalchemist::kpi-tiles marker="sp-kpis" :tiles="[
                ['kpi' => 'vk', 'label' => 'VK / Person · Woche', 'tone' => 'accent',
                 'value' => number_format($kosten['woche']['vk'], 2, ',', '.') . ' €'],
                ['kpi' => 'ek', 'label' => 'EK / Person · Woche',
                 'value' => number_format($kosten['woche']['ek'], 2, ',', '.') . ' €'],
                ['kpi' => 'kostform', 'label' => 'Kostformen · Woche',
                 'tone' => $kfN > 0 && $kfOk === $kfN ? 'good' : ($kfOk > 0 ? 'warn' : 'neutral'),
                 'value' => $kfN > 0 ? $kfOk . '/' . $kfN . ' abgedeckt' : '—'],
                ['kpi' => 'wdh', 'label' => 'Wdh.-Konflikte',
                 'tone' => count($wiederholungen) > 0 ? 'warn' : 'good',
                 'value' => (string) count($wiederholungen)],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($sp === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Plan geladen.</p>
    @else
        {{-- 2-Spalten: Editor-Tabs (links, breit) + Live-Kennzahlen-Rail (rechts).
             -mx-6 hebt das Body-px-6 auf (Spalten randbündig); die Mitte bekommt px-6 zurück,
             damit die sticky editor-tabs-Leiste (-mx-6) wieder auf Spaltenbreite spannt; die
             Rail behält pr-6. --}}
        <div class="flex gap-4 -mx-6 items-start">
            <div class="flex-1 min-w-0 px-6">
                <x-foodalchemist::editor-tabs marker="sp" wire-key="sp-tabs-{{ $sp->id }}" :init="'kalender'"
                    :tabs="[
                        'kalender' => 'Kalender',
                        'linien' => 'Menü-Linien',
                        'stammdaten' => 'Stammdaten',
                        'praesentation' => 'Branding & Präsentation',
                    ]">

                    {{-- ═══ Tab: KALENDER ═══ --}}
                    <div x-show="tab === 'kalender'" x-cloak class="pt-4 space-y-4" data-sp-tab-kalender>
                        {{-- Toolbar: Ansicht + Mahlzeit + Navigation --}}
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="inline-flex rounded-lg overflow-hidden border border-white/15">
                                @foreach(['woche' => 'Woche', 'monat' => 'Monat'] as $av => $al)
                                    <button type="button" wire:click="ansichtSetzen('{{ $av }}')" class="px-3 py-1.5 text-xs {{ $ansicht === $av ? 'bg-violet-500/20 text-violet-200 font-medium' : 'text-gray-400 hover:bg-white/[0.04]' }}">{{ $al }}</button>
                                @endforeach
                            </span>
                            <span class="flex items-center gap-1 flex-wrap">
                                @foreach($mahlzeiten as $mk => $ml)
                                    <button type="button" wire:click="mahlzeitSetzen('{{ $mk }}')" class="{{ $pill }} {{ $mahlzeit === $mk ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $ml }}</button>
                                @endforeach
                            </span>
                            <a href="{{ route('foodalchemist.speiseplan.dokument', ['id' => $sp->id, 'mahlzeit' => $mahlzeit, 'montag' => $montagDt->format('Y-m-d')]) }}" target="_blank"
                               class="{{ $btnGhostXs }}" title="Wochen-Aushang (Druck/PDF) mit Allergen- & Zusatzstoff-Legende" data-sp-aushang>@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline-block align-middle') Aushang</a>
                            <button type="button" wire:click="anProduktion"
                                    wire:confirm="Diese Woche ({{ $mahlzeiten[$mahlzeit] ?? '' }}) an die Produktion übergeben? Je Werktag mit Belegung wird ein Produktionsauftrag angelegt (Menge = Teilnehmerzahl)."
                                    class="{{ $btnGhostXs }}" title="Woche × Teilnehmerzahl → Produktionsaufträge (je Werktag einer)" data-sp-produktion>@svg('heroicon-o-fire', 'w-3.5 h-3.5 inline-block align-middle') → Produktion</button>
                            <span class="flex items-center gap-2 ml-auto">
                                @if($ansicht === 'woche')
                                    <button type="button" wire:click="wocheVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                                    <span class="text-sm font-medium tabular-nums text-gray-200">KW {{ (int) $montagDt->format('W') }} · {{ $montagDt->format('d.m.') }}–{{ $montagDt->copy()->addDays(4)->format('d.m.Y') }}</span>
                                    <button type="button" wire:click="wocheVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                                    <button type="button" wire:click="heute" class="{{ $btnGhostXs }}">Heute</button>
                                @else
                                    <button type="button" wire:click="monatVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                                    <span class="text-sm font-medium text-gray-200">{{ $monatNamen[(int) $monatStart->month] }} {{ $monatStart->year }}</span>
                                    <button type="button" wire:click="monatVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                                @endif
                            </span>
                        </div>

                        @if($ansicht === 'woche')
                            {{-- Wochen-Matrix: Linien × Mo–Fr --}}
                            <x-foodalchemist::modal-section title="Wochen-Matrix">
                                <div class="overflow-x-auto" data-sp-matrix>
                                    <table class="{{ $table }}" style="table-layout:fixed; width:100%; min-width:600px;">
                                        <thead><tr class="text-left">
                                            <th class="{{ $th }}" style="width:104px">Linie</th>
                                            @foreach($wochenTage as $tag)
                                                <th class="{{ $th }} text-center {{ $tag->isToday() ? 'text-violet-300' : '' }}">{{ $tagKurz[$tag->isoWeekday()] }} <span class="text-gray-400 font-normal">{{ $tag->format('d.m.') }}</span></th>
                                            @endforeach
                                        </tr></thead>
                                        <tbody>
                                            @php($zeilenLinien = $linien->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values())
                                            @if(isset($raster[0]))@php($zeilenLinien->push(['id' => 0, 'name' => 'Ohne Linie', 'color' => null]))@endif
                                            @foreach($zeilenLinien as $zl)
                                                <tr class="border-t border-white/10 align-top">
                                                    <td class="{{ $td }} whitespace-nowrap">
                                                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $zl['color'] ?: '#94a3b8' }}"></span><span class="font-medium text-gray-300">{{ $zl['name'] }}</span></span>
                                                    </td>
                                                    @foreach($wochenTage as $tag)
                                                        @php($ymd = $tag->format('Y-m-d'))
                                                        @php($eintraege = $raster[$zl['id']][$ymd] ?? [])
                                                        <td class="{{ $td }} align-top {{ ($cellDatum === $ymd && $cellLinie === ($zl['id'] ?: null)) ? 'bg-violet-500/10 rounded-lg' : '' }}">
                                                            <div class="space-y-0.5">
                                                                @foreach($eintraege as $e)
                                                                    <div wire:key="e-{{ $e->id }}" class="group flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] text-gray-100"
                                                                         style="background: {{ ($zl['color'] ?? null) ? $zl['color'].'33' : 'rgba(255,255,255,0.07)' }}">
                                                                        <span class="flex-1 min-w-0 truncate" title="{{ $e->inhaltName() }}">{{ $e->inhaltName() }}</span>
                                                                        <input type="number" min="0" value="{{ $e->pax }}" placeholder="{{ $sp->default_pax }}"
                                                                               wire:change="setPax({{ $e->id }}, $event.target.value)"
                                                                               title="Teilnehmer (leer = Plan-Default {{ $sp->default_pax }})"
                                                                               class="w-11 text-right text-[10px] px-1 py-0 rounded bg-white/10 border border-white/15 text-gray-100 shrink-0" />
                                                                        <button type="button" wire:click="eintragRaus({{ $e->id }})" class="opacity-0 group-hover:opacity-100 text-gray-300 hover:text-red-400 shrink-0">✕</button>
                                                                    </div>
                                                                @endforeach
                                                                @if($zl['id'] !== 0)
                                                                    <button type="button" wire:click="zelleOeffnen('{{ $ymd }}', {{ $zl['id'] }})" class="w-full text-[11px] text-gray-400 hover:text-violet-300 rounded border border-dashed border-white/15 hover:border-violet-400/40 py-0.5">+</button>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                            @if($zeilenLinien->isEmpty())
                                                <tr><td colspan="6" class="{{ $td }} text-center text-gray-400 text-xs py-4">Im Tab „Menü-Linien" eine Linie anlegen, dann Gerichte in die Tage setzen.</td></tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Inhalts-Picker für die aktive Zelle (inline, Livewire-sicher) --}}
                                @if($cellDatum !== null)
                                    <div class="mt-3 pt-3 border-t border-white/10 space-y-2" data-sp-picker>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="{{ $label }}">Einfügen · {{ \Illuminate\Support\Carbon::parse($cellDatum)->format('d.m.') }} · {{ $linien->firstWhere('id', $cellLinie)?->name ?? '—' }}:</span>
                                            @foreach(['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'] as $tv => $tl)
                                                <button type="button" wire:click="$set('pickerTyp', '{{ $tv }}')" class="{{ $pill }} {{ $pickerTyp === $tv ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $tl }}</button>
                                            @endforeach
                                            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ ['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'][$pickerTyp] }} suchen …" class="{{ $input }} w-56" />
                                            <button type="button" wire:click="cellSchliessen" class="{{ $btnGhostXs }}">schließen</button>
                                        </div>

                                        {{-- Spec 42: Facetten (Hauptgruppe → Unterklasse) nur für Gerichte — wie Speisekarte/Verkauf-Browser --}}
                                        @if($pickerTyp === 'gericht' && $pickerHauptgruppen->isNotEmpty())
                                            <div class="flex items-center gap-1 flex-wrap" data-sp-picker-facetten>
                                                <button type="button" wire:click="pickerWaehleHg(null)" class="{{ $pill }} {{ $pickerHauptgruppe === null ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                                                @foreach($pickerHauptgruppen as $hg)
                                                    <button type="button" wire:click="pickerWaehleHg({{ $hg->id }})" class="{{ $pill }} {{ (int) $pickerHauptgruppe === (int) $hg->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $hg->label }}</button>
                                                @endforeach
                                            </div>
                                            @if($pickerUntergruppen->isNotEmpty())
                                                <div class="flex items-center gap-1 flex-wrap pl-3" data-sp-picker-unterklassen>
                                                    @foreach($pickerUntergruppen as $uk)
                                                        <button type="button" wire:click="pickerWaehleKlasse({{ $uk->id }})" class="{{ $pill }} {{ (int) $pickerDishClass === (int) $uk->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $uk->label }}</button>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @endif

                                        @if($kandidaten->isNotEmpty())
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-1 max-h-56 overflow-y-auto">
                                                @foreach($kandidaten as $k)
                                                    <button type="button" wire:key="kand-{{ $pickerTyp }}-{{ $k->id }}" wire:click="inhaltHinzu('{{ $pickerTyp }}', {{ $k->id }})"
                                                            class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/15 text-left text-gray-200">
                                                        <span class="truncate">{{ $k->name }}</span>
                                                        <span class="flex items-center gap-1 shrink-0">
                                                            @if($pickerTyp === 'gericht' && ($k->dishClass?->diet_form))
                                                                <span class="text-[10px] px-1 rounded bg-white/10 text-gray-300">{{ $k->dishClass->diet_form }}</span>
                                                            @endif
                                                            @if($pickerTyp === 'gericht' && $k->sales_net)
                                                                <span class="text-[10px] text-gray-400">{{ number_format((float) $k->sales_net, 2, ',', '.') }} €</span>
                                                            @elseif($pickerTyp === 'concept' && ($k->price_per_person_cache ?? null))
                                                                <span class="text-[10px] text-gray-400">{{ number_format((float) $k->price_per_person_cache, 2, ',', '.') }} €/P</span>
                                                            @endif
                                                            <span class="text-violet-300">+</span>
                                                        </span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @else
                                            <p class="text-[11px] text-gray-400">Keine Treffer.</p>
                                        @endif
                                    </div>
                                @endif
                            </x-foodalchemist::modal-section>
                        @else
                            {{-- Monats-Kalender --}}
                            @php($gridStart = $monatStart->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY))
                            <x-foodalchemist::modal-section title="Monat">
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
                                                class="text-left rounded-lg border p-1.5 h-20 transition-colors {{ $imMonat ? 'border-white/10 hover:bg-violet-500/10' : 'border-transparent opacity-40' }} {{ $tag->isToday() ? 'ring-1 ring-violet-400' : '' }}">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[11px] {{ $tag->isToday() ? 'text-violet-300 font-semibold' : 'text-gray-400' }}">{{ $tag->format('j') }}</span>
                                                @if($info)<span class="{{ $pill }} {{ $variantPill['secondary'] }} text-[9px]">{{ $info['count'] }}</span>@endif
                                            </div>
                                            @if($info && $info['vk'] > 0)
                                                <div class="mt-1 text-[10px] text-gray-400 tabular-nums">{{ number_format($info['vk'], 2, ',', '.') }} €</div>
                                            @endif
                                        </button>
                                    @endfor
                                </div>
                                <p class="mt-3 text-[11px] text-gray-400">Tag anklicken → springt in die Wochenansicht. Belegung der Mahlzeit „{{ $mahlzeiten[$mahlzeit] ?? '' }}".</p>
                            </x-foodalchemist::modal-section>
                        @endif
                    </div>

                    {{-- ═══ Tab: MENÜ-LINIEN ═══ --}}
                    <div x-show="tab === 'linien'" x-cloak class="pt-4" data-sp-tab-linien>
                        <x-foodalchemist::modal-section title="Menü-Linien">
                            <x-slot:actions>
                                <span class="text-[11px] text-gray-400">Zeilen der Matrix · pro Plan frei</span>
                            </x-slot:actions>
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach($linien as $linie)
                                    <div wire:key="linie-{{ $linie->id }}" class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded-lg border border-white/10">
                                        <span class="w-3 h-3 rounded-full shrink-0" style="background: {{ $linie->color ?: '#94a3b8' }}"></span>
                                        <span class="text-xs text-gray-200">{{ $linie->name }}</span>
                                        @if($linie->is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg</span>@endif
                                        <button type="button" wire:click="linieVerschieben({{ $linie->id }}, -1)" class="text-gray-500 hover:text-violet-300 text-[10px]" title="hoch">▲</button>
                                        <button type="button" wire:click="linieVerschieben({{ $linie->id }}, 1)" class="text-gray-500 hover:text-violet-300 text-[10px]" title="runter">▼</button>
                                        <button type="button" wire:click="linieEdit({{ $linie->id }})" class="text-gray-400 hover:text-violet-300 text-xs" title="bearbeiten">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                        <button type="button" wire:click="linieRaus({{ $linie->id }})" wire:confirm="Linie entfernen? Einträge bleiben (ohne Linie)." class="text-gray-400 hover:text-red-400 text-xs" title="entfernen">✕</button>
                                    </div>
                                @endforeach
                                <div class="flex items-center gap-1">
                                    <input type="text" wire:model="neueLinie" wire:keydown.enter="linieAdd" placeholder="+ Linie …" class="{{ $input }} w-32 h-8 text-xs" />
                                    <button type="button" wire:click="linieAdd" class="{{ $btnGhostXs }}">+</button>
                                </div>
                            </div>
                            @if($editLinieId !== null)
                                <div class="mt-2 pt-2 border-t border-white/10 flex flex-wrap items-end gap-2">
                                    <div><label class="{{ $label }}">Name</label><input type="text" wire:model="linieForm.name" class="{{ $input }} w-44 h-8" /></div>
                                    <div><label class="{{ $label }}">Farbe</label><input type="color" wire:model="linieForm.color" class="h-8 w-12 rounded border border-white/15 bg-transparent" /></div>
                                    <label class="flex items-center gap-1.5 text-xs pb-1.5 text-gray-300"><input type="checkbox" wire:model="linieForm.is_vegetarian" /> vegetarisch</label>
                                    <button type="button" wire:click="linieSpeichern" class="{{ $btnPrimary }} h-8">OK</button>
                                    <button type="button" wire:click="$set('editLinieId', null)" class="{{ $btnGhost }} h-8">Abbrechen</button>
                                </div>
                            @endif
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Tab: STAMMDATEN ═══ --}}
                    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 space-y-4" data-sp-tab-stammdaten>
                        <x-foodalchemist::modal-section title="Plan-Stammdaten">
                            <x-slot:actions>
                                <button type="button" wire:click="speichern" class="{{ $btnGhostXs }}" data-sp-stammdaten-speichern>Speichern</button>
                            </x-slot:actions>
                            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                                <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                                <div><label class="{{ $label }}">Start (Montag)</label><input type="date" wire:model.live="form.start_date" wire:change="speichern" class="{{ $input }}" /></div>
                                <div><label class="{{ $label }}">Zyklus (Wochen)</label><input type="number" min="1" wire:model.live="form.cycle_weeks" wire:change="speichern" class="{{ $input }} text-right tabular-nums" /></div>
                                <div><label class="{{ $label }}">Min. Abstand (T.)</label><input type="number" min="0" wire:model.live="form.min_abstand_tage" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="0 = keine Wiederholungsregel" /></div>
                            </div>

                            {{-- Spec 33 P5: Status und Zuordnung aus dem geteilten Bauteil. Hier stand
                                 bis dahin ein eigenes Dropdown mit `draft`/`active` — Werte, die weder
                                 Migration noch Service kannten. Ein Gültigkeitsfenster hat der Plan
                                 bewusst nicht: es steht in seinen Einträgen. --}}
                            <div class="mt-3 pt-3 border-t border-black/5">
                                <x-foodalchemist::ausgabe-status
                                    status-model="form.status"
                                    outlet-model="form.outlet_id"
                                    :betriebe="$betriebe" :zustand="$plan->laufZustand()" :grund="$plan->laufGrund()"
                                    :fenster-hinweis="$fensterHinweis" :konflikt="$portfolioKonflikt"
                                    toggle="aktivUmschalten" />
                            </div>
                            <x-foodalchemist::crm-kunde-picker
                                :ausgabe="$plan" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Teilnehmer & Wareneinsatz-Budget">
                            <p class="text-[11px] text-gray-400 mb-2">Default-Kopfzahl für die Produktions-Übergabe (je Zelle überschreibbar) + EK-Zielwert pro Person für die Budget-Ampel.</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <div><label class="{{ $label }}">Teilnehmer (Default)</label><input type="number" min="1" wire:model.live="form.default_pax" wire:change="speichern" class="{{ $input }} text-right tabular-nums" data-sp-default-pax /></div>
                                <div><label class="{{ $label }}">Budget EK/Person (€)</label><input type="text" inputmode="decimal" wire:model.live="form.budget_wareneinsatz" wire:change="speichern" placeholder="z. B. 1,80" class="{{ $input }} text-right tabular-nums" title="Wareneinsatz-Ziel pro Person/Mahlzeit — Ampel in der Rail" /></div>
                            </div>
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Zyklus ausrollen">
                            <p class="text-[11px] text-gray-400 mb-2">Den {{ $sp->cycle_weeks }}-Wochen-Block ab Start auf alle Folgewochen bis zum Zieldatum kopieren (belegte Tage bleiben unberührt).</p>
                            <div class="flex items-center gap-2 flex-wrap">
                                <input type="date" wire:model="ausrollenBis" class="{{ $input }} w-44" title="Zyklus-Vorlage bis zu diesem Datum ausrollen" />
                                <button type="button" wire:click="ausrollen" class="{{ $btnGhost }}" data-sp-ausrollen>⟳ Zyklus ausrollen</button>
                                @if($ausrollenInfo)<span class="text-[11px] text-violet-300">{{ $ausrollenInfo }}</span>@endif
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Spec 43: Tab BRANDING & PRÄSENTATION (digitaler Aushang) ═══ --}}
                    <div x-show="tab === 'praesentation'" x-cloak class="pt-4 space-y-4" data-sp-tab-praesentation>
                        @if($brandingFehler)<div class="rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs px-3 py-2">{{ $brandingFehler }}</div>@endif

                        <x-foodalchemist::modal-section title="Branding">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label class="text-xs text-gray-300">Markenfarbe
                                    <input type="color" wire:model="brandColor" class="block w-full h-8 rounded border border-white/10 bg-transparent">
                                </label>
                                <label class="text-xs text-gray-300">Bandfarbe (optional)
                                    <input type="color" wire:model="bandColor" class="block w-full h-8 rounded border border-white/10 bg-transparent">
                                </label>
                                <label class="text-xs text-gray-300">Footer-Text
                                    <input type="text" wire:model="footerText" class="{{ $input }} w-full" placeholder="z.B. Küche XY">
                                </label>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 mt-3">
                                <button type="button" wire:click="brandingSpeichern" class="{{ $btnGhost }}" data-sp-branding-speichern>Branding speichern</button>
                                <div class="text-xs text-gray-400">Logo
                                    @if($brandingBilder['logo'])<img src="{{ $brandingBilder['logo'] }}" class="inline-block h-6 align-middle ml-1 rounded bg-white/10"> <button type="button" wire:click="brandingLogoEntfernen" class="text-rose-300 text-[11px]">entfernen</button>@endif
                                    <input type="file" wire:model="logoUpload" accept="image/*" class="block text-[11px] mt-1">
                                </div>
                                <div class="text-xs text-gray-400">Coverbild
                                    @if($brandingBilder['cover'])<img src="{{ $brandingBilder['cover'] }}" class="inline-block h-6 align-middle ml-1 rounded bg-white/10"> <button type="button" wire:click="brandingCoverEntfernen" class="text-rose-300 text-[11px]">entfernen</button>@endif
                                    <input type="file" wire:model="coverUpload" accept="image/*" class="block text-[11px] mt-1">
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Präsentation · digitaler Aushang">
                            @if($presentationHinweis)<div class="rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-xs px-3 py-2 mb-2" data-sp-praes-hinweis>{{ $presentationHinweis }}</div>@endif
                            @if($presentationFehler)<div class="rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs px-3 py-2 mb-2" data-sp-praes-fehler>{{ $presentationFehler }}</div>@endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="text-xs text-gray-300">Design
                                    <select wire:model="presentationDesign" class="{{ $input }} w-full" data-sp-praes-design>
                                        @foreach($presentationDesignOptionen as $opt)
                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs text-gray-300">Gültig bis <span class="text-rose-400">*</span>
                                    <input type="date" wire:model="presentationGueltigBis" class="{{ $input }} w-full" data-sp-praes-gueltig>
                                </label>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-gray-300 mt-2"><input type="checkbox" wire:model="presentationPreisAnzeige" data-sp-praes-preis> Preise anzeigen (optional — Default aus)</label>
                            {{-- Ebene 2 · Republish-Preis-Schutz (nur relevant mit Preisen) --}}
                            <label class="flex items-center gap-2 text-xs text-gray-300" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen. An: aktuelle VK ziehen. Nur mit Preisen relevant; Erstveröffentlichung immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren"> Preise aktualisieren</label>
                            <p class="text-[11px] text-gray-400 mt-1">GV-Aushang ist per Default preislos; die LMIV-Kennzeichnung ist immer Pflicht und sichtbar. Preise z.B. für Café-/Bistro-Pläne — folgen dem aktiven Betrieb.</p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <label class="text-xs text-gray-300">CTA-Text (optional)
                                    <input type="text" wire:model="presentationCtaText" class="{{ $input }} w-full" placeholder="z.B. Mehr Infos">
                                </label>
                                <label class="text-xs text-gray-300">CTA-Link (optional)
                                    <input type="url" wire:model="presentationCtaLink" class="{{ $input }} w-full" placeholder="https://…">
                                </label>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 mt-3">
                                <a href="{{ route('foodalchemist.speiseplan.praesentation', ['id' => $sp->id, 'design' => $presentationDesign]) }}" target="_blank" class="{{ $btnGhost }}">Vorschau öffnen</a>
                                <button type="button" wire:click="veroeffentlichen" wire:confirm="Diesen Aushang veröffentlichen? Der Snapshot wird eingefroren." class="{{ $btnPrimary }}" data-sp-praes-publish @disabled(! $presentationGueltigBis)>
                                    {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                                </button>
                                @if($presentationInfo['enabled'] ?? false)
                                    <button type="button" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link liefert dann 404." class="{{ $btnGhost }}" data-sp-praes-withdraw>Zurückziehen</button>
                                @endif
                            </div>
                            @unless($presentationGueltigBis)
                                <p class="text-[11px] text-amber-300 mt-1">Zum Veröffentlichen ein „gültig bis"-Datum setzen (Pflicht).</p>
                            @endunless

                            @if($presentationLink)
                                <div class="rounded-lg bg-white/5 border border-white/10 p-3 text-xs mt-3" x-data>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200" data-sp-praes-link>{{ $presentationLink }}</div>
                                        <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.textContent='Kopiert ✓'">Link kopieren</button>
                                    </div>
                                    <p class="text-[11px] text-gray-400 mt-1">Freigegeben am {{ $presentationInfo['published_at'] ?? '—' }} · gültig bis {{ $presentationInfo['expires_at'] ?? '—' }} · {{ ($presentationInfo['live'] ?? false) ? 'aktiv' : 'inaktiv/abgelaufen' }}</p>
                                </div>
                            @endif

                            {{-- ── Slice F: Betriebs-Links — pro Betrieb ein eigener Aushang-Link (eigene Vorlage + Name) ── --}}
                            <div class="rounded-lg border border-violet-400/25 bg-violet-500/[0.06] p-3 space-y-3 mt-3">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-violet-400"></span>
                                    <h4 class="text-xs font-semibold text-violet-200">Betriebs-Links · eigener Aushang je Betrieb</h4>
                                </div>
                                <p class="text-[11px] text-gray-400">Ein zusätzlicher Aushang-Link pro Betrieb — mit der <strong class="text-gray-200">Vorlage</strong> und dem <strong class="text-gray-200">Namen</strong> dieses Betriebs, eigene Freigabe. Der Standard-Link oben bleibt bestehen.</p>

                                @forelse($betriebsLinks as $bl)
                                    <div class="rounded-lg bg-white/[0.04] border border-white/10 p-2 text-xs" x-data>
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-gray-100">{{ $bl['outlet_name'] }}</span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded {{ $bl['enabled'] ? 'bg-emerald-500/15 text-emerald-300' : 'bg-white/10 text-gray-400' }}">{{ $bl['enabled'] ? 'aktiv' : 'inaktiv' }}</span>
                                            <span class="ml-auto text-[10px] text-gray-500">Vorlage: {{ $bl['design'] }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200">{{ $bl['url'] }}</div>
                                            <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.textContent='Kopiert ✓'">Kopieren</button>
                                            @if($bl['enabled'])
                                                <button type="button" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er liefert dann 404." class="{{ $btnGhost }}">Zurückziehen</button>
                                            @else
                                                <button type="button" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})" class="{{ $btnPrimary }}">Wieder freigeben</button>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-[11px] text-gray-500">Noch kein Betriebs-Link angelegt.</p>
                                @endforelse

                                @if(count($betriebsOptionen) > 0)
                                    <div class="rounded-lg border border-dashed border-violet-400/30 bg-white/[0.02] p-2.5 space-y-2">
                                        <p class="text-[11px] font-medium text-violet-200">Weiteren Betrieb hinzufügen</p>
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Betrieb</label>
                                                <select wire:model="outletPublishId" class="mt-1 block text-sm rounded px-2 py-1">
                                                    <option value="">— wählen —</option>
                                                    @foreach($betriebsOptionen as $o)
                                                        <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">gültig bis (optional)</label>
                                                <input type="date" wire:model="outletPublishGueltigBis" class="mt-1 block text-sm rounded px-2 py-1">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Vorlage (optional)</label>
                                                <select wire:model="outletPublishDesign" class="mt-1 block text-sm rounded px-2 py-1">
                                                    <option value="">— Betriebs-Vorlage / wie Dokument —</option>
                                                    @foreach($presentationDesignOptionen as $opt)
                                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Link-Name (optional)</label>
                                                <input type="text" wire:model="outletPublishSlug" placeholder="z.B. broich-nord-2027" class="mt-1 block text-sm rounded px-2 py-1">
                                            </div>
                                            <button type="button" wire:click="betriebVeroeffentlichen" class="{{ $btnPrimary }}">＋ Betrieb hinzufügen</button>
                                        </div>
                                        <p class="text-[10px] text-gray-500">Beliebig viele Betriebe möglich — je Betrieb ein eigener Link. Ohne eigenes Datum gilt das „gültig bis" des Standard-Links.</p>
                                    </div>
                                @else
                                    <p class="text-[11px] text-amber-300">Noch keine Betriebe angelegt — lege sie unter <em>Einstellungen › Betriebe</em> an.</p>
                                @endif
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </x-foodalchemist::editor-tabs>
            </div>

            {{-- ═══ Live-Kennzahlen-Rail (Cockpit) ═══
                 Rechnet bei jeder Zellen-/Linien-Änderung mit — VK/EK je Person, Veggie-Tagescheck,
                 Wiederholungs-Konflikte. Bewusst auf Tab-Ebene: aus jedem Tab sichtbar. --}}
            <aside class="w-72 shrink-0 pr-6 sticky top-0 self-start max-h-[85vh] overflow-y-auto space-y-3 pt-4" data-sp-kennzahlen>
                <h3 class="{{ $label }} px-1">Kennzahlen · Woche</h3>

                @if($kosten)
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-4 text-center">
                        <div class="text-2xl font-semibold text-gray-100 tabular-nums">{{ number_format($kosten['woche']['vk'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">VK/Person · {{ $mahlzeiten[$mahlzeit] ?? '' }} · EK {{ number_format($kosten['woche']['ek'], 2, ',', '.') }} €</div>
                        <div class="text-[10px] text-gray-500 mt-1">Default {{ $sp->default_pax }} Teilnehmer</div>
                    </div>
                @endif

                {{-- Wareneinsatz-Budget-Ampel (GV): Ø EK/Person/Tag vs. Zielwert --}}
                @if($kosten && $sp->budget_wareneinsatz)
                    @php($tageK = collect($kosten['pro_tag']))
                    @php($nT = $tageK->count())
                    @php($avgEk = $nT > 0 ? round($tageK->avg('ek'), 2) : 0)
                    @php($budget = (float) $sp->budget_wareneinsatz)
                    @php($ueberTage = $tageK->filter(fn ($t) => $t['ek'] > $budget)->count())
                    @php($ampel = $avgEk > $budget ? 'danger' : ($ueberTage > 0 ? 'warning' : 'success'))
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1" data-sp-budget>
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="{{ $label }}">Wareneinsatz-Budget</span>
                            <span class="{{ $pill }} {{ $variantPill[$ampel] }}">{{ $ampel === 'success' ? 'im Ziel' : ($ampel === 'warning' ? $ueberTage . ' Tag(e) drüber' : 'über Ziel') }}</span>
                        </div>
                        <div class="text-[11px] text-gray-400">Ø EK {{ number_format($avgEk, 2, ',', '.') }} € / Ziel {{ number_format($budget, 2, ',', '.') }} € p.P./Tag</div>
                    </div>
                @endif

                {{-- Kostformen-Abdeckung (GV): ist jede Kostform an jedem Werktag vertreten? --}}
                <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-kostformen>
                    <div class="{{ $label }}">Kostformen-Abdeckung</div>
                    @foreach($kostformen as $kf)
                        <div class="flex items-center justify-between gap-2 text-[11px]">
                            <span class="text-gray-300 truncate">{{ $kf['label'] }}</span>
                            @if($kf['erfuellt'])
                                <span class="{{ $pill }} {{ $variantPill['success'] }} shrink-0" title="an jedem Werktag vertreten">✓ täglich</span>
                            @elseif($kf['abgedeckt'] > 0)
                                <span class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0" title="fehlt: {{ implode(', ', array_map(fn($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m.'), $kf['fehltage'])) }}">{{ $kf['abgedeckt'] }}/{{ $kf['tage'] }}</span>
                            @else
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">—</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- LMIV-Kennzeichnung der Woche (deklarationspflichtig, ALL-MAXIMAL über alle Gerichte) --}}
                @if($kennzeichnung)
                    @php($algEnth = collect($kennzeichnung['woche']['allergene'])->whereIn('status', ['enthalten', 'spuren']))
                    @php($zusJa = collect($kennzeichnung['woche']['zusatzstoffe'])->where('status', 'ja'))
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-kennzeichnung>
                        <div class="{{ $label }}">Kennzeichnung · Woche</div>
                        @if($algEnth->isEmpty())
                            <p class="text-[11px] text-gray-400">Keine Allergene deklariert (oder unbekannt).</p>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach($algEnth as $a)
                                    <span class="{{ $pill }} {{ $a['status'] === 'enthalten' ? $variantPill['danger'] : $variantPill['warning'] }}" title="{{ $a['status'] === 'spuren' ? 'Spuren' : 'enthalten' }}">{{ $a['label'] }}{{ $a['status'] === 'spuren' ? ' (Sp.)' : '' }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($zusJa->isNotEmpty())
                            <div class="flex flex-wrap gap-1 pt-1.5 border-t border-white/10">
                                @foreach($zusJa as $z)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Zusatzstoff (LMIV)">{{ $z['label'] }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[10px] text-gray-500">Vollständige Tages-Kennzeichnung im Aushang-Export.</p>
                    </div>
                @endif

                {{-- DGE-Nährwert-Wochenbilanz (Ø je Person/Tag) --}}
                @if($naehrwerte && $naehrwerte['tage_mit_daten'] > 0)
                    @php($n = $naehrwerte['schnitt'])
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1" data-sp-naehrwerte>
                        <div class="{{ $label }}">Nährwerte · Ø/Person/Tag</div>
                        <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-[11px] text-gray-300">
                            <div class="flex justify-between"><span class="text-gray-500">kcal</span><span class="tabular-nums">{{ $n['kcal'] !== null ? number_format($n['kcal'], 0, ',', '.') : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Eiweiß</span><span class="tabular-nums">{{ $n['protein_g'] !== null ? number_format($n['protein_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Fett</span><span class="tabular-nums">{{ $n['fett_g'] !== null ? number_format($n['fett_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">ges. Fett</span><span class="tabular-nums">{{ $n['gesfett_g'] !== null ? number_format($n['gesfett_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Salz</span><span class="tabular-nums">{{ $n['salz_g'] !== null ? number_format($n['salz_g'], 2, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Zucker</span><span class="tabular-nums">{{ $n['zucker_g'] !== null ? number_format($n['zucker_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                        </div>
                        @if($naehrwerte['confidence'] !== 'high')<p class="text-[10px] text-amber-300/80">Konfidenz {{ $naehrwerte['confidence'] }} — nicht alle Gerichte mit Nährwert/Portionsgramm.</p>@endif
                    </div>
                @endif

                {{-- Abwechslung/Häufigkeit: Diät-Mix + Warengruppen der Woche --}}
                @if($abwechslung)
                    @php($dm = $abwechslung['diaet'])
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-abwechslung>
                        <div class="{{ $label }}">Abwechslung · Woche</div>
                        <div class="flex flex-wrap gap-1 text-[11px]">
                            <span class="{{ $pill }} {{ $variantPill['success'] }}">Vegan {{ $dm['vegan'] }}</span>
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">Vegetarisch {{ $dm['vegetarisch'] }}</span>
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">mit Fleisch/Fisch {{ $dm['omnivor'] }}</span>
                        </div>
                        @if(!empty($abwechslung['warengruppen']))
                            <div class="flex flex-wrap gap-1 pt-1.5 border-t border-white/10">
                                @foreach($abwechslung['warengruppen'] as $w)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $w['name'] }} ×{{ $w['count'] }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($abwechslung['hinweis'])<p class="text-[10px] text-amber-300/80">{{ $abwechslung['hinweis'] }}</p>@endif
                    </div>
                @endif

                <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1">
                    @if(!empty($wiederholungen))
                        <div class="{{ $label }} text-amber-300">Wiederholungen ({{ count($wiederholungen) }})</div>
                        @foreach($wiederholungen as $w)
                            <p class="text-[11px] {{ $variantPill['warning'] }} {{ $pill }} w-full justify-between"><span class="truncate">{{ $w['name'] }}</span><span class="shrink-0 ml-2">{{ $w['vorkommen'] }}× · {{ $w['min_abstand'] }} T.</span></p>
                        @endforeach
                    @else
                        <p class="text-[11px] text-gray-400 text-center">Keine Wiederholungs-Konflikte.</p>
                    @endif
                </div>
            </aside>
        </div>
    @endif
</x-foodalchemist::modal>
