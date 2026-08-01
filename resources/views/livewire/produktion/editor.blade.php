{{-- Spec 18 — Produktion: Editor-Modal (Stammdaten / Ziele / Vorschau) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@php($pzRezepte = $vorschau['rezepte'] ?? [])
@php($pzAnsaetze = collect($pzRezepte)->sum('ansaetze'))
@php($pzZeit = collect($pzRezepte)->sum(fn ($r) => (int) ($r['arbeitszeit_min'] ?? 0)))
{{-- Spec 29-Rollout: Produktion-Editor auf Editor-Page-Muster (fullscreen · dark · editor-tabs · KPI). --}}
<x-foodalchemist::modal name="produktion-editor" fullscreen dark-canvas
    :title="$orderId === null ? 'Neuer Produktionsauftrag' : 'Produktionsauftrag bearbeiten'"
    :title-name="$orderId === null ? null : ($name ?: null)">
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-produktion-speichern>Speichern</button>
        @if($fehler)<span class="text-[12px] text-rose-600 ml-2 self-center" data-produktion-fehler>{{ $fehler }}</span>@endif
    </x-slot:actions>

    {{-- KPI-Kopf: Ziele + Rezepte + Ansätze (Leitwert) + Arbeitszeit aus der Ansätze-Vorschau --}}
    <x-slot:kpiHeader>
        <x-foodalchemist::kpi-tiles marker="produktion-kpis" :tiles="[
            ['kpi' => 'ziele', 'label' => 'Ziele', 'value' => (string) count($targets)],
            ['kpi' => 'rezepte', 'label' => 'Rezepte', 'value' => (string) count($pzRezepte)],
            ['kpi' => 'ansaetze', 'label' => 'Ansätze', 'tone' => 'accent',
             'value' => $pzAnsaetze > 0 ? rtrim(rtrim(number_format((float) $pzAnsaetze, 2, ',', '.'), '0'), ',') : '—'],
            ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $pzZeit > 0 ? $pzZeit . ' min' : '—'],
        ]" />
    </x-slot:kpiHeader>

    <x-foodalchemist::editor-tabs marker="produktion" wire-key="produktion-tabs-{{ $orderId ?? 'neu' }}" :init="'stammdaten'"
        :tabs="['stammdaten' => 'Stammdaten', 'ziele' => 'Ziele', 'vorschau' => 'Vorschau', 'zeilen' => $orderId ? 'Zeilen' : null, 'einkauf' => $orderId ? 'Einkauf & Status' : null]">

    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4">
    <x-foodalchemist::modal-section title="Stammdaten">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="{{ $label }}">Name <span class="text-rose-500">*</span></label>
                <input type="text" wire:model="name" placeholder="z. B. Sommerfest Vormittag" class="{{ $input }}" data-produktion-name />
            </div>
            <div>
                <label class="{{ $label }}">Produktionsdatum</label>
                <input type="date" wire:model="productionDate" class="{{ $input }}" data-produktion-datum />
            </div>
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Anlass</label>
            <input type="text" wire:model="reference" placeholder="z. B. Sommer-Buffet" class="{{ $input }}" data-produktion-anlass />
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Notiz</label>
            <textarea wire:model="note" rows="2" class="{{ $input }}"></textarea>
        </div>
        {{-- Küchen-Manager: Überproduktions-/Puffer-% — skaliert Ansätze + Einkauf, Ziele bleiben im Original --}}
        <div class="mt-3 flex items-end gap-2 pt-3 border-t border-white/10">
            <div class="w-44">
                <label class="{{ $label }}">Überproduktion / Puffer %</label>
                <input type="number" min="0" max="100" step="1" wire:model.live.debounce.400ms="puffer" class="{{ $input }}" data-produktion-puffer />
            </div>
            <span class="text-[11px] text-gray-500 pb-2">skaliert Ansätze + Einkauf hoch; die Ziele bleiben im Original. 0 = kein Puffer.</span>
        </div>
    </x-foodalchemist::modal-section>
    </div>{{-- /Stammdaten-Panel --}}

    <div x-show="tab === 'ziele'" x-cloak class="pt-4">
    <x-foodalchemist::modal-section title="Ziele">
        <div class="flex items-center gap-2 mb-2">
            <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                <button type="button" wire:click="$set('zielTyp', 'concept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'concept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Konzept</button>
                <button type="button" wire:click="$set('zielTyp', 'recipe')" class="px-3 py-1 rounded-md {{ $zielTyp === 'recipe' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Gericht</button>
                <button type="button" wire:click="$set('zielTyp', 'basisrezept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'basisrezept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-basisrezept>Basisrezept</button>
                <button type="button" wire:click="$set('zielTyp', 'kapitel')" class="px-3 py-1 rounded-md {{ $zielTyp === 'kapitel' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-kapitel>Kapitel</button>
                <button type="button" wire:click="$set('zielTyp', 'angebot')" class="px-3 py-1 rounded-md {{ $zielTyp === 'angebot' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-angebot>Angebot</button>
            </div>
        </div>

        @if($zielTyp === 'kapitel')
            {{-- P2: Foodbook-Kapitel als Ziel → beim Hinzufügen in eingefrorene Einzel-Ziele expandiert (V2). --}}
            <div class="space-y-2 mb-3" data-produktion-kapitel>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="{{ $label }}">Foodbook</label>
                        <select wire:model.live="auswahlFoodbookId" class="{{ $input }}" data-produktion-foodbook>
                            <option value="">— wählen —</option>
                            @foreach($foodbooks as $fb)
                                <option value="{{ $fb->id }}">{{ $fb->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Kapitel</label>
                        <select wire:model.live="auswahlChapterId" @disabled(! $auswahlFoodbookId) class="{{ $input }}" data-produktion-kapitel-select>
                            <option value="">— wählen —</option>
                            @foreach($kapitelBaum as $k)
                                <option value="{{ $k['id'] }}">{!! str_repeat('&nbsp;&nbsp;', $k['depth']) !!}{{ $k['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="w-28">
                    <label class="{{ $label }}">Personen</label>
                    <input type="number" min="1" wire:model="auswahlPersonen" class="{{ $input }}" data-produktion-kapitel-personen />
                </div>
                @if(! empty($variantGroups))
                    <div class="rounded-lg border border-black/5 bg-black/[0.02] p-2 space-y-2" data-produktion-varianten>
                        <p class="{{ $label }}">Varianten-Wahl (Wahl-Gruppen im Kapitel)</p>
                        @foreach($variantGroups as $g)
                            <select wire:model="variantChoices.{{ $g['group_id'] }}" class="{{ $input }}" wire:key="vg-{{ $g['group_id'] }}" data-produktion-variante="{{ $g['group_id'] }}">
                                @foreach($g['options'] as $opt)
                                    <option value="{{ $opt['block_id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                        @endforeach
                    </div>
                @endif
                <button type="button" wire:click="zielHinzufuegen" class="{{ $btnGhost }}" data-produktion-ziel-hinzufuegen>+ Kapitel-Ziele hinzufügen</button>
            </div>
        @else
        {{-- Concepter-/Foodbook-Muster: Menge-Kontext + Suche + browsebare Kandidatenliste mit „+".
             „+" fügt den Kandidaten mit der aktuellen Menge als Ziel hinzu und bleibt offen. --}}
        <div class="space-y-2 mb-3" data-produktion-picker>
            <div class="flex items-end gap-2">
                @if($zielTyp !== 'angebot')
                <div class="w-32">
                    <label class="{{ $label }}">{{ $zielTyp === 'concept' ? 'Personen' : ($zielTyp === 'basisrezept' ? ($basisEinheit === 'kg' ? 'Kilogramm' : 'Ansätze') : 'Portionen') }}</label>
                    <input type="number" min="0" step="{{ $zielTyp === 'basisrezept' ? '0.1' : '1' }}" wire:model="auswahlMenge" class="{{ $input }}" data-produktion-menge />
                </div>
                @endif
                @if($zielTyp === 'basisrezept')
                    <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs mb-0.5" data-produktion-basis-einheit>
                        <button type="button" wire:click="$set('basisEinheit', 'ansaetze')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'ansaetze' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Ansätze</button>
                        <button type="button" wire:click="$set('basisEinheit', 'kg')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'kg' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">kg</button>
                    </div>
                @endif
                <div class="flex-1">
                    <label class="{{ $label }}">{{ $zielTyp === 'concept' ? 'Konzept' : ($zielTyp === 'basisrezept' ? 'Basisrezept' : ($zielTyp === 'angebot' ? 'Angebot' : 'Gericht')) }} suchen</label>
                    <input type="search" wire:model.live.debounce.300ms="suche" placeholder="{{ $zielTyp === 'angebot' ? 'Angebot suchen … (+ = ganzes Angebot als Ziele)' : 'Suchen … (aus der Liste mit + einfügen)' }}" class="{{ $input }}" data-produktion-gericht-suche />
                </div>
            </div>
            <div class="rounded-lg border border-white/10 max-h-56 overflow-y-auto divide-y divide-white/5" data-produktion-kandidaten>
                @forelse($kandidaten as $k)
                    <button type="button" wire:key="pk-{{ $zielTyp }}-{{ $k->id }}" wire:click="zielAusListe({{ $k->id }})"
                        class="w-full flex items-center justify-between gap-2 px-2.5 py-1.5 text-[12px] text-left hover:bg-violet-500/10" data-produktion-kandidat="{{ $k->id }}">
                        <span class="truncate text-gray-900">{{ $k->name ?? $k->label ?? ('#' . $k->id) }}@if($zielTyp === 'angebot' && ($k->personen ?? null)) <span class="text-gray-500 text-[11px]">· {{ $k->personen }} P., ganzes Angebot</span>@endif</span>
                        <span class="text-violet-500 font-medium shrink-0">+</span>
                    </button>
                @empty
                    <p class="text-[12px] text-gray-500 px-2.5 py-3 text-center">{{ trim((string) $suche) !== '' ? 'Kein Treffer.' : 'Nichts vorhanden.' }}</p>
                @endforelse
            </div>
        </div>
        @endif

        <div class="space-y-1">
            @forelse($targets as $t)
                <div class="flex items-center justify-between gap-2 text-[12px] px-2 py-1 rounded-lg bg-black/[0.02]" wire:key="ziel-{{ $t['source_ref'] }}">
                    <span class="text-gray-800">{{ $t['label'] ?? '—' }}</span>
                    <div class="flex items-center gap-2">
                        @unless(str_contains($t['source_ref'], ':c'))
                            <button type="button" wire:click="zielBearbeiten('{{ $t['source_ref'] }}')" class="text-gray-400 hover:text-violet-600" title="Bearbeiten" data-produktion-ziel-bearbeiten>@svg('heroicon-o-pencil', 'w-3.5 h-3.5')</button>
                        @endunless
                        <button type="button" wire:click="zielEntfernen('{{ $t['source_ref'] }}')" class="text-rose-500" title="Entfernen" data-produktion-ziel-entfernen>@svg('heroicon-o-x-mark', 'w-3.5 h-3.5')</button>
                    </div>
                </div>
            @empty
                <p class="text-[12px] text-gray-500">Noch keine Ziele — Konzept, Gericht, Basisrezept oder Foodbook-Kapitel wählen und hinzufügen.</p>
            @endforelse
        </div>
    </x-foodalchemist::modal-section>
    </div>{{-- /Ziele-Panel --}}

    <div x-show="tab === 'vorschau'" x-cloak class="pt-4 space-y-4">
    {{-- Küchen-Manager: Diät-/Allergen-Übersicht über die ganze Produktion (Rollup der Rezepte) --}}
    @if($allergenRollup)
        @php($ja = 'px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-700')
        @php($nein = 'px-2 py-0.5 rounded-md bg-black/10 text-gray-500')
        @php($warn = 'px-2 py-0.5 rounded-md bg-amber-500/15 text-amber-700')
        <x-foodalchemist::modal-section title="Diät & Allergene (über die ganze Produktion)">
            <div class="flex flex-wrap gap-1.5 items-center text-[11px]" data-produktion-allergene>
                <span class="{{ $allergenRollup['is_vegan'] ? $ja : $nein }}">{{ $allergenRollup['is_vegan'] ? '✓ ' : '' }}vegan</span>
                <span class="{{ $allergenRollup['is_vegetarian'] ? $ja : $nein }}">{{ $allergenRollup['is_vegetarian'] ? '✓ ' : '' }}vegetarisch</span>
                <span class="{{ $allergenRollup['is_halal'] ? $ja : $nein }}">{{ $allergenRollup['is_halal'] ? '✓ ' : '' }}halal</span>
                <span class="{{ $allergenRollup['is_gluten_free'] ? $ja : $nein }}">{{ $allergenRollup['is_gluten_free'] ? '✓ ' : '' }}glutenfrei</span>
                <span class="{{ $allergenRollup['is_lactose_free'] ? $ja : $nein }}">{{ $allergenRollup['is_lactose_free'] ? '✓ ' : '' }}laktosefrei</span>
                @if($allergenRollup['contains_pork'])<span class="{{ $warn }}">enthält Schwein</span>@endif
                @if($allergenRollup['contains_beef'])<span class="{{ $warn }}">enthält Rind</span>@endif
                <span class="ml-auto text-gray-400">Konfidenz {{ $allergenRollup['confidence'] }} · {{ $allergenRollup['n_gerichte'] }} Rezepte</span>
            </div>
            <p class="text-[10px] text-gray-500 mt-2">„vegan/…/frei" = trifft auf ALLE Rezepte zu · „enthält" = mind. ein Rezept. Rollup aus den Rezept-Spezifikationen (schwächste Konfidenz gewinnt).</p>
        </x-foodalchemist::modal-section>
    @endif
    <x-foodalchemist::modal-section title="Vorschau">
        @if($vorschau === null)
            <p class="text-[12px] text-gray-500">Ziele hinzufügen, um die Ansätze-Vorschau zu sehen.</p>
        @else
            <table class="{{ $table }}">
                <thead><tr>
                    <th class="{{ $th }} text-left">Rezept</th>
                    <th class="{{ $th }} text-right">Ansätze</th>
                    <th class="{{ $th }} text-right">Portionen/kg</th>
                    <th class="{{ $th }} text-right">Arbeitszeit</th>
                </tr></thead>
                <tbody>
                    @foreach($vorschau['rezepte'] as $r)
                        <tr class="border-t border-black/5">
                            <td class="{{ $td }}">{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Basisrezept</span>@endif</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ rtrim(rtrim(number_format($r['ansaetze'], 2, ',', '.'), '0'), ',') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['portionen'] !== null ? $r['portionen'] . ' Port.' : ($r['produzierte_menge_kg'] !== null ? number_format($r['produzierte_menge_kg'], 2, ',', '.') . ' kg' : '—') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['arbeitszeit_min'] !== null ? $r['arbeitszeit_min'] . ' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @foreach($vorschau['warnungen'] as $w)
                <x-foodalchemist::alert tone="warning" class="mt-2">{{ $w }}</x-foodalchemist::alert>
            @endforeach
        @endif
    </x-foodalchemist::modal-section>
    </div>{{-- /Vorschau-Panel --}}

    {{-- ═══ Tab: EINKAUF & STATUS (aus DetailPanel gemergt — nur bestehender Auftrag) ═══ --}}
    {{-- ── Tab: ZEILEN (Spec 30 E2) — der Auftrag als Arbeitsdokument ──────────
         Die Vorschau zeigt, was gerechnet WURDE. Hier greift der Mensch ein: Ansätze
         überschreiben, Zeilen streichen, freie Positionen ergänzen. Was hier gesetzt wird,
         überlebt jeden Recompute (Overlay), die berechnete Zahl bleibt als Referenz stehen. --}}
    <div x-show="tab === 'zeilen'" x-cloak class="pt-4 space-y-4" data-produktion-zeilen>
        @if($ops === null)
            <p class="text-[12px] text-gray-500">Auftrag zuerst speichern — dann erscheinen die Zeilen.</p>
        @else
            @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif

            {{-- Warnungen des GESPEICHERTEN Auftrags — die standen bisher nirgends im Editor. --}}
            @if(! empty($ops['warnungen']))
                <x-foodalchemist::alert tone="warning" data-produktion-warnungen>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach(array_unique($ops['warnungen']) as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-foodalchemist::alert>
            @endif

            @php($zeilen = collect($ops['zeilen']))
            @php($darfEditieren = $ops['editierbar'] ?? false)
            @php($darfDisponieren = ($ops['is_owned'] ?? false) && in_array($ops['status'], ['planned', 'in_progress'], true))

            {{-- Überlast meldet sich passiv und nur, wenn am Posten wirklich eine Kapazität
                 hinterlegt ist. Kein Modal, kein Blockieren. --}}
            @if(! empty($kapazitaetsWarnungen))
                <x-foodalchemist::alert tone="warning" data-kapazitaet-warnung>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($kapazitaetsWarnungen as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-foodalchemist::alert>
            @endif

            @if(count($postenSummen) > 0)
                <x-foodalchemist::modal-section title="Nach Posten">
                    <div class="flex flex-wrap gap-2" data-posten-summen>
                        @foreach($postenSummen as $ps)
                            <span class="{{ $pill }} {{ $ps['station_id'] === null ? $variantPill['secondary'] : $variantPill['info'] }}" wire:key="ps-{{ $ps['station_id'] ?? 0 }}">
                                {{ $ps['station'] }} · {{ $ps['zeilen'] }} Zeilen · {{ $ps['arbeitszeit_min'] }} min
                                @if($ps['ohne_zeit'] > 0)<span class="text-amber-600" title="Ohne hinterlegte Arbeitszeit — die Summe ist unvollständig."> ({{ $ps['ohne_zeit'] }} ohne Zeit)</span>@endif
                            </span>
                        @endforeach
                        @if($darfDisponieren && $postenListe->isNotEmpty() && collect($postenSummen)->firstWhere('station_id', null))
                            <span class="inline-flex items-center gap-1">
                                <select wire:change="alleUnverplantAufPosten($event.target.value)" class="{{ $input }} !py-0.5 !w-48" data-bulk-posten>
                                    <option value="">Unverplante alle auf …</option>
                                    @foreach($postenListe as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                                </select>
                            </span>
                        @endif
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            <x-foodalchemist::modal-section title="Zeilen ({{ $zeilen->count() }})">
                <x-slot:actions>
                    <span class="text-[11px] text-gray-500">
                        {{ rtrim(rtrim(number_format((float) $ops['ansaetze_gesamt'], 2, ',', '.'), '0'), ',') }} Ansätze
                        @if($ops['arbeitszeit_gesamt_min'] > 0) · {{ $ops['arbeitszeit_gesamt_min'] }} min @endif
                        @php($ohneZeit = $zeilen->reject(fn ($z) => $z['ist_gestrichen'])->filter(fn ($z) => ($z['arbeitszeit_min'] ?? null) === null)->count())
                        @if($ohneZeit > 0)<span class="text-amber-600" title="Diese Zeilen haben keine Arbeitszeit am Rezept — die Summe ist unvollständig."> · {{ $ohneZeit }} ohne Zeit</span>@endif
                    </span>
                </x-slot:actions>

                @if(! $darfEditieren)
                    <p class="text-[11px] text-gray-500 mb-2">
                        Nur ein <strong>geplanter</strong> Auftrag im eigenen Team ist editierbar — der Stand ist eingefroren.
                    </p>
                @endif

                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-full">Rezept / Position</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Ansätze</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Portionen</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Zeit</th>
                            <th class="{{ $th }} whitespace-nowrap">Posten</th>
                            <th class="{{ $th }} whitespace-nowrap">Verantwortlich</th>
                            <th class="{{ $th }} text-right whitespace-nowrap" title="Tage vor dem Liefertag — 0 = am Tag selbst">Vorlauf</th>
                            <th class="{{ $th }}">Notiz</th>
                            <th class="{{ $th }} w-px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($zeilen as $z)
                            <tr class="{{ $tr }} {{ $z['ist_gestrichen'] ? 'opacity-50' : '' }}" wire:key="pz-{{ $z['id'] }}" data-produktion-zeile="{{ $z['id'] }}">
                                <td class="{{ $td }}">
                                    <span class="{{ $z['ist_gestrichen'] ? 'line-through' : '' }}">{{ $z['name'] }}</span>
                                    @if($z['ist_freie_position'])
                                        <span class="{{ $pill }} {{ $variantPill['primary'] }} ml-1">manuell</span>
                                    @elseif($z['ist_basisrezept'])
                                        <span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Basisrezept</span>
                                    @endif
                                    @if($z['ist_gestrichen'])
                                        <span class="{{ $pill }} {{ $variantPill['danger'] ?? $variantPill['secondary'] }} ml-1">gestrichen</span>
                                        @if($z['struck_reason'])<span class="text-[11px] text-gray-500 ml-1">— {{ $z['struck_reason'] }}</span>@endif
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right whitespace-nowrap">
                                    @if($darfEditieren)
                                        <input type="text" inputmode="decimal"
                                               value="{{ $z['ist_manuelle_ansaetze'] ? rtrim(rtrim(number_format($z['ansaetze'], 3, ',', ''), '0'), ',') : '' }}"
                                               placeholder="{{ rtrim(rtrim(number_format($z['ansaetze_berechnet'], 3, ',', ''), '0'), ',') }}"
                                               wire:change="zeileAnsaetze({{ $z['id'] }}, $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-20 text-right tabular-nums"
                                               title="leer = berechneter Wert" data-zeile-ansaetze />
                                    @else
                                        <span class="tabular-nums">{{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }}</span>
                                    @endif
                                    @if($z['override_stale'])
                                        <div class="text-[10px] text-amber-600 mt-0.5" data-override-stale>
                                            berechnet: {{ rtrim(rtrim(number_format($z['ansaetze_berechnet'], 2, ',', '.'), '0'), ',') }}
                                            @if($darfEditieren)
                                                <button type="button" wire:click="zeileAnsaetze({{ $z['id'] }}, '')" class="underline">zurücksetzen</button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $z['portionen'] ?? '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $z['arbeitszeit_min'] !== null ? $z['arbeitszeit_min'] . ' min' : '—' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <select wire:change="zeileZuteilen({{ $z['id'] }}, 'station_id', $event.target.value)"
                                                class="{{ $input }} !py-0.5 !w-36" data-zeile-posten>
                                            <option value="">— kein Posten —</option>
                                            @foreach($postenListe as $p)
                                                <option value="{{ $p->id }}" @selected(($z['station_id'] ?? null) === $p->id)>{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['station'] ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <input type="text" value="{{ $z['assignee'] }}" wire:change="zeileZuteilen({{ $z['id'] }}, 'assignee', $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-28" placeholder="Name" data-zeile-assignee />
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['assignee'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <input type="text" inputmode="numeric" value="{{ $z['vorlauf_tage'] }}"
                                               wire:change="zeileZuteilen({{ $z['id'] }}, 'vorlauf_tage', $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-14 text-right tabular-nums" data-zeile-vorlauf />
                                    @else
                                        <span class="tabular-nums">{{ $z['vorlauf_tage'] }}</span>
                                    @endif
                                    @if(($z['vorlauf_tage'] ?? 0) > 0)
                                        <div class="text-[10px] text-gray-500 mt-0.5">{{ $z['plan_date'] }}</div>
                                    @endif
                                </td>
                                <td class="{{ $td }}">
                                    @if($darfEditieren)
                                        <input type="text" value="{{ $z['note'] }}" wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                               class="{{ $input }} !py-0.5" placeholder="Küchen-Notiz" data-zeile-notiz />
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['note'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfEditieren)
                                        @if($z['ist_freie_position'])
                                            <button type="button" wire:click="freiePositionLoeschen({{ $z['id'] }})" wire:confirm="Freie Position entfernen?"
                                                    class="{{ $btnGhostXs }} text-rose-500" data-zeile-loeschen>✕</button>
                                        @elseif($z['ist_gestrichen'])
                                            <button type="button" wire:click="zeileStreichen({{ $z['id'] }}, false)" class="{{ $btnGhostXs }}" data-zeile-zurueck>wiederherstellen</button>
                                        @else
                                            <button type="button" wire:click="zeileStreichen({{ $z['id'] }}, true)" class="{{ $btnGhostXs }} text-rose-500"
                                                    title="Zählt nicht mehr mit und kommt nicht auf den Zettel — bleibt aber sichtbar" data-zeile-streichen>streichen</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="{{ $td }} text-[12px] text-gray-500">Noch keine Zeilen — erst Ziele setzen.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if($darfEditieren)
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-2 border-t border-black/5" data-freie-position>
                        <span class="{{ $dt }}">＋ Freie Position</span>
                        <input type="text" wire:model="freiTitel" placeholder="z. B. Brot beim Bäcker abholen" class="{{ $input }} !py-1 flex-1 min-w-48" data-frei-titel />
                        <input type="text" inputmode="numeric" wire:model="freiZeit" placeholder="min" class="{{ $input }} !py-1 !w-20" data-frei-zeit />
                        <button type="button" wire:click="freiePositionAnlegen" class="{{ $btnGhostXs }}" data-frei-anlegen>Hinzufügen</button>
                        <span class="text-[10px] text-gray-500">Etwas, das kein Rezept ist. Erscheint nicht im Einkauf.</span>
                    </div>
                @endif
            </x-foodalchemist::modal-section>
        @endif
    </div>{{-- /Tab ZEILEN --}}

    <div x-show="tab === 'einkauf'" x-cloak class="pt-4 space-y-4">
        @if($ops === null)
            <p class="text-[12px] text-gray-500">Auftrag zuerst speichern — dann erscheinen Status, Bestell-Übergabe und Deckungsgrad.</p>
        @else
            @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700" data-produktion-hinweis>✓ {{ $hinweis }}</div>@endif

            @if($ops['is_owned'] && count($erlaubteStatus) > 0)
                <x-foodalchemist::modal-section title="Status">
                    @php($statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Stornieren'])
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $pill }} font-medium {{ $variantPill[\Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($ops['status'])->badgeVariant()] ?? $variantPill['secondary'] }} mr-1">{{ $ops['status_label'] }}</span>
                        @foreach($erlaubteStatus as $z)
                            <button type="button" wire:click="setStatus('{{ $z->value }}')"
                                class="{{ in_array($z->value, ['in_progress', 'done'], true) ? $btnPrimary : $btnGhost }}"
                                @if($z->value === 'cancelled') onclick="return confirm('Produktion stornieren?')" @endif
                                data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? $z->label() }}</button>
                        @endforeach
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            @php($zieleCount = count($ops['targets']))
            @php($uebergebenCount = collect($ops['targets'])->filter(fn ($t) => ! empty($zielUebergaben[$t['source_ref'] ?? '']))->count())
            <x-foodalchemist::modal-section title="Einkauf">
                <x-slot:actions>
                    @if($ops['is_owned'] && in_array($ops['status'], ['planned', 'in_progress'], true))
                        <button type="button" wire:click="anBestellungUebergeben" class="{{ $btnGhostXs }}" data-produktion-uebergeben>→ An Bestellung übergeben</button>
                    @endif
                    @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                        <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $ops['id']]) }}" target="_blank" class="{{ $btnGhostXs }}" title="Produktionsschein + Einkauf">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Doku</a>
                    @endif
                </x-slot:actions>
                @if(! empty($ops['einkauf_veraltet']))
                    <div class="mb-2" data-einkauf-veraltet="1"><x-foodalchemist::alert tone="warning">Bestellung veraltet — Ziele seit der letzten Übergabe geändert. Erneut übergeben.</x-foodalchemist::alert></div>
                @endif
                @if($zieleCount > 0)
                    <div class="flex items-center justify-between gap-2 text-[12px] mb-2" data-einkauf-deckung="{{ $uebergebenCount }}/{{ $zieleCount }}">
                        <span class="text-gray-500">Deckungsgrad</span>
                        <span class="{{ $pill }} font-medium {{ $uebergebenCount === 0 ? $variantPill['secondary'] : ($uebergebenCount >= $zieleCount ? $variantPill['success'] : $variantPill['warning']) }}">{{ $uebergebenCount }}/{{ $zieleCount }} Ziele übergeben</span>
                    </div>
                @endif
                @if($verknuepfteOrders->isNotEmpty())
                    <div class="space-y-1">
                        @foreach($verknuepfteOrders as $o)
                            <a href="{{ route('foodalchemist.orders.index', ['o' => $o->id]) }}" class="flex items-center justify-between gap-2 text-[13px] px-2 py-1.5 rounded-lg bg-black/[0.04] hover:bg-black/[0.08]" data-produktion-bestellung-link="{{ $o->id }}">
                                <span class="text-gray-900">{{ $o->supplier?->name ?? '—' }}</span>
                                <span class="flex items-center gap-2"><span class="text-gray-500 tabular-nums">{{ number_format((float) $o->total_net, 2, ',', '.') }} €</span><span class="{{ $pill }} font-medium {{ $variantPill[$o->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o->status->label() }}</span></span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-[12px] text-gray-500">Noch keine Bestellung — „→ An Bestellung übergeben" oben.</p>
                @endif
            </x-foodalchemist::modal-section>

            @if(! empty($ops['zeilen']))
                <x-foodalchemist::modal-section title="Küchen-Notizen">
                    <div class="space-y-2">
                        @foreach($ops['zeilen'] as $z)
                            <div class="flex items-center gap-2" wire:key="opsnote-{{ $z['id'] }}">
                                <span class="text-[12px] text-gray-900 flex-1 truncate">{{ $z['name'] }}</span>
                                <input type="text" value="{{ $z['note'] }}" placeholder="Küchen-Notiz …" wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)" class="{{ $input }} !py-1 w-64" data-produktion-notiz="{{ $z['id'] }}" />
                            </div>
                        @endforeach
                    </div>
                </x-foodalchemist::modal-section>
            @endif
        @endif
    </div>{{-- /Einkauf-Panel --}}
    </x-foodalchemist::editor-tabs>
</x-foodalchemist::modal>
