{{-- M2-06/07/08: LA-Editor-Modal (P-2/P-6) — read-only für Kette, Edit nur Besitzer --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- Spec 28 / E3.2: LA-Editor auf den Master-Standard (Basisrezepte) gezogen — vorher acht
     Sektionen linear ohne Kopf-Kennzahlen, der datendichteste Editor ohne jede Struktur.
     Voll-Editor nur mit geladenem Artikel; Titel generisch + Designation als Akzent-Chip. --}}
<div>
    <x-foodalchemist::modal name="item-modal" :title="$item !== null ? ($darfEdit ? 'Artikel bearbeiten' : 'Artikel') : 'Artikel'"
        :title-name="$item?->designation" :fullscreen="$item !== null" :dark-canvas="$item !== null">
        @if($item)
            <x-slot:actions>
                @if($darfEdit)
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-la-speichern>Speichern</button>
                @else
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Geerbter Katalog (D1) — Pflege beim Besitzer-Team">read-only</span>
                @endif
            </x-slot:actions>

            {{-- KPI-Kopf statt der alten Kopfzeile (Lieferant · EK · Vergleichspreis · GP-Pill):
                 dieselben Angaben, aber fix im Kopf und bewertet. Leitwert = EK aktuell (accent).
                 GP-Mapping und Allergen-Pflege sind echte Vollständigkeiten → good/warn.
                 Allergene: 14 EU-Pflichtangaben, alles außer 'unbekannt' zählt als gepflegt
                 (GL-01-4-Wert-Modell — fehlende Schlüssel sind ebenfalls unbekannt). --}}
            @php($allergenGepflegt = collect($allergenLabels)->keys()
                ->filter(fn ($k) => ($allergene[$k] ?? 'unbekannt') !== 'unbekannt')->count())
            @php($allergenGesamt = count($allergenLabels))
            @php($gpName = $item->structure?->gp?->name)
            <x-slot:kpiHeader>
                <x-foodalchemist::kpi-tiles marker="la-editor-kpis" :cols="5" :tiles="[
                    ['kpi' => 'ek', 'label' => 'EK aktuell', 'tone' => 'accent',
                     'title' => 'pro ' . ($item->ordering_unit ?? $item->unit_code ?? 'Einheit'),
                     'value' => $aktiverPreis?->price !== null ? number_format((float) $aktiverPreis->price, 2, ',', '.') . ' €' : '—'],
                    ['kpi' => 'vergleichspreis', 'label' => 'Vergleichspreis',
                     'title' => 'Auf die Kalkulationseinheit gerechnet (M2-05)',
                     'value' => $vergleichspreis !== null ? number_format($vergleichspreis['value'], 2, ',', '.') . ' ' . $vergleichspreis['unit'] : '—'],
                    ['kpi' => 'gp', 'label' => 'Grundprodukt', 'tone' => $gpName !== null ? 'good' : 'warn',
                     'title' => $gpName ?? 'Ohne GP läuft dieser Artikel in keine Rezept-Kalkulation.',
                     'value' => $gpName ?? 'nicht gemappt'],
                    ['kpi' => 'allergene', 'label' => 'Allergene',
                     'tone' => $allergenGepflegt >= $allergenGesamt ? 'good' : 'warn',
                     'title' => 'Gepflegt von 14 EU-Pflichtangaben — ungesetzt zählt als unbekannt (GL-01).',
                     'value' => $allergenGepflegt . '/' . $allergenGesamt],
                    ['kpi' => 'lieferant', 'label' => 'Lieferant',
                     'title' => $item->supplier?->name ?? '',
                     'value' => $item->supplier?->name ?? '—'],
                ]" />
            </x-slot:kpiHeader>

            @if($fehler)<p class="text-xs text-red-600 mb-2" data-la-fehler>{{ $fehler }}</p>@endif

            {{-- Vier Tabs statt acht Sektionen am Stück (E1-8: was man am häufigsten ändert links).
                 Alpine-Modus: alle Panels bleiben im DOM, damit das entangle-Binding der
                 Zusatzstoffe und ungespeicherte Eingaben beim Umschalten nicht verloren gehen. --}}
            <x-foodalchemist::editor-tabs marker="la" wire-key="la-tabs-{{ $item->id }}" :init="'stammdaten'"
                :tabs="[
                    'stammdaten' => 'Stammdaten',
                    'deklaration' => 'Deklaration',
                    'gp' => 'GP-Mapping',
                    'preise' => 'Preise',
                ]">

            {{-- ── Tab: STAMMDATEN (Stammdaten · Verpackung · Eigenschaften) ── --}}
            <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 space-y-4">

            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    @foreach([['designation', 'Bezeichnung'], ['article_number', 'Artikel-Nr.'], ['brand', 'Marke'], ['manufacturer', 'Hersteller'], ['origin', 'Herkunft'], ['marketing_name', 'Marketing-Name']] as [$feld, $lbl])
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $lbl }}</label>
                            <input type="text" wire:model="stammdaten.{{ $feld }}" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" />
                        </div>
                    @endforeach
                </div>
                <div class="mt-3"><label class="block {{ $label }} mb-1">Zusatztext</label>
                    <textarea wire:model="stammdaten.additional_text" rows="2" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60"></textarea></div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Verpackung & Mengen">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div><label class="block {{ $label }} mb-1">Gebinde-Menge (qty)</label>
                        <input type="text" wire:model="verpackung.qty" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Kalk-Einheit</label>
                        <select wire:model="verpackung.unit_code" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60">
                            <option value="">—</option>
                            @foreach(['kg', 'l', 'Stk'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                        </select></div>
                    <div><label class="block {{ $label }} mb-1">Verpackungseinheit</label>
                        <input type="text" wire:model="verpackung.packaging_unit" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Bestelleinheit</label>
                        <input type="text" wire:model="verpackung.ordering_unit" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">VPE pro Bestelleinheit</label>
                        <input type="text" wire:model="verpackung.qty_ordering_per_packaging" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">EAN VPE</label>
                        <input type="text" wire:model="verpackung.ean_packaging" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">EAN Bestelleinheit</label>
                        <input type="text" wire:model="verpackung.ean_ordering" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Eigenschaften">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach([['is_organic', 'Bio'], ['is_vegan', 'Vegan'], ['is_vegetarian', 'Vegetarisch'], ['is_alcohol', 'Alkohol'], ['is_halal', 'Halal'], ['is_gmo_free', 'GVO-frei']] as [$feld, $lbl])
                        <div><label class="block {{ $label }} mb-1">{{ $lbl }}</label>
                            <select wire:model="eigenschaften.{{ $feld }}" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60">
                                <option value="">unbekannt</option>
                                <option value="1">ja</option>
                                <option value="0">nein</option>
                            </select></div>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
                    <div><label class="block {{ $label }} mb-1">MwSt %</label>
                        <input type="text" wire:model="eigenschaften.vat" placeholder="7 oder 19" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Ursprungsland</label>
                        <input type="text" wire:model="eigenschaften.origin_country" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Bio-Kontrollnummer</label>
                        <input type="text" wire:model="eigenschaften.organic_control_number" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div data-vorbestellung><label class="block {{ $label }} mb-1">Vorbestellung (V-29)</label>
                        <div class="flex gap-2">
                            <select wire:model="eigenschaften.is_preorder" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60 !w-24">
                                <option value="">—</option><option value="1">ja</option><option value="0">nein</option>
                            </select>
                            <input type="number" wire:model="eigenschaften.preorder_days" placeholder="Tage" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60 !w-20" />
                        </div></div>
                </div>
                <div class="mt-3"><label class="block {{ $label }} mb-1">Zutatenliste (vom Lieferanten)</label>
                    <textarea wire:model="eigenschaften.ingredients_supplier" rows="3" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60"></textarea></div>
            </x-foodalchemist::modal-section>
            </div>{{-- /Tab STAMMDATEN --}}

            {{-- ── Tab: DEKLARATION (Nährwerte · Allergene · Zusatzstoffe) ──── --}}
            <div x-show="tab === 'deklaration'" x-cloak class="pt-4 space-y-4">

            <x-foodalchemist::modal-section title="Nährwerte (je 100 g)">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[11px] text-gray-500">Fließen in die GP-Nährwert-Aggregation (Ø über die LAs, GL-08).</p>
                    @if($darfEdit)
                        <button type="button" wire:click="naehrwerteSpeichern" class="{{ $btnGhostXs }} text-violet-600">Nährwerte speichern</button>
                    @endif
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3" data-naehrwerte>
                    @foreach($naehrwertFelder as $feld => $meta)
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $meta[0] }} ({{ $meta[1] }})</label>
                            <input type="text" inputmode="decimal" wire:model="naehrwerte.{{ $feld }}" placeholder="—"
                                   @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" data-naehr-{{ $feld }} />
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Allergene (14 EU-Pflichtangaben)">
                <div class="flex items-center justify-between mb-2" data-allergen-kopf>
                    <p class="text-[11px] text-gray-500">− nicht enthalten · ≈ Spuren · ✓ enthalten · ungesetzt = unbekannt (GL-01). Quelle:
                        {{-- Lineage GL-07: NULL = Necta-Bulk, manual = hier gepflegt, datei = Kanal-B-Import (Spec 13 · S1c) --}}
                        <span class="{{ $pill }} {{ $allergenQuelle === 'manual' ? $variantPill['success'] : $variantPill['secondary'] }}">{{ ['manual' => 'manuell', 'datei' => 'Datei-Import'][$allergenQuelle] ?? ($allergenQuelle ?: 'Import') }}</span>
                    </p>
                    @if($darfEdit)
                        <button type="button" wire:click="allergeneSpeichern" class="{{ $btnGhostXs }} text-violet-600">Allergene speichern</button>
                    @endif
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                    <x-foodalchemist::tri-state model="allergene" :readonly="! $darfEdit"
                        :items="collect($allergenLabels)->take(7)->all()" />
                    <x-foodalchemist::tri-state model="allergene" :readonly="! $darfEdit"
                        :items="collect($allergenLabels)->skip(7)->all()" />
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Zusatzstoffe (18 deklarationspflichtige Stoffe, LMIV)">
                <div class="flex items-center justify-between mb-2" data-deklaration-kopf>
                    <p class="text-[11px] text-gray-500">− nein · ✓ ja · ungesetzt = keine Angabe (GL-09). Quelle:
                        <span class="{{ $pill }} {{ $deklarationQuelle === 'manual' ? $variantPill['success'] : $variantPill['secondary'] }}">{{ ['manual' => 'manuell', 'datei' => 'Datei-Import'][$deklarationQuelle] ?? ($deklarationQuelle ?: 'Import') }}</span>
                    </p>
                    @if($darfEdit)
                        <button type="button" wire:click="deklarationenSpeichern" class="{{ $btnGhostXs }} text-violet-600">Zusatzstoffe speichern</button>
                    @endif
                </div>
                {{-- R20 (Dominique): zwei Raster nebeneinander statt voller Breite --}}
                <div x-data="{ dekl: $wire.entangle('deklarationen') }" class="grid grid-cols-1 md:grid-cols-2 gap-x-8" data-deklarationen>
                    @foreach(collect($deklarationLabels)->chunk((int) ceil(count($deklarationLabels) / 2)) as $haelfte)
                    <div class="divide-y divide-black/5">
                    @foreach($haelfte as $stoff => $lbl)
                        <div class="flex items-center justify-between gap-3 py-1" data-dekl-row="{{ $stoff }}">
                            <span class="text-xs text-gray-700 min-w-0 truncate">{{ $lbl }}</span>
                            <div class="flex items-center gap-1 shrink-0">
                                @foreach([['nein', '−', 'bg-gray-500/20 text-gray-700 border-gray-500/30'], ['ja', '✓', 'bg-red-500/15 text-red-600 border-red-500/30']] as [$wert, $zeichen, $aktiv])
                                    <button type="button" title="{{ $wert }}"
                                            @if($darfEdit)
                                                @click="dekl['{{ $stoff }}'] = dekl['{{ $stoff }}'] === '{{ $wert }}' ? 'unbekannt' : '{{ $wert }}'"
                                            @else disabled @endif
                                            :class="dekl['{{ $stoff }}'] === '{{ $wert }}' ? @js($aktiv) : 'border-black/5 text-gray-300 {{ $darfEdit ? 'hover:text-gray-500 hover:bg-black/5' : 'opacity-60' }}'"
                                            class="w-5 h-5 inline-flex items-center justify-center text-[10px] font-medium rounded border transition-all duration-150"
                                            data-dekl-btn="{{ $wert }}">{{ $zeichen }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    </div>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>

            </div>{{-- /Tab DEKLARATION --}}

            {{-- ── Tab: GP-MAPPING ──────────────────────────────────────────── --}}
            <div x-show="tab === 'gp'" x-cloak class="pt-4 space-y-4">

            {{-- R9 (Jarvis «GP-MAPPING»): aktuelles Mapping + KI-Vorschlag (MatchService) + manuelle Zuweisung --}}
            <x-foodalchemist::modal-section title="GP-Mapping">
                <x-slot:actions>
                    {{-- E1-11: Emoji raus, Heroicon rein; KI-Chip trägt den btnAi-Stil wie überall --}}
                    <button type="button" wire:click="kiGpVorschlag" class="{{ $btnAi }}"
                            title="MatchService v1: exakte Dubletten (EAN/Art.-Nr) + GL-04-Fuzzy" data-ki-gp-vorschlag>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Vorschlag</button>
                    @if(! $item->structure?->gp)
                        <button type="button" wire:click="gpNeuAnlegen" class="{{ $btnGhostXs }} text-violet-600" data-gp-neu-aus-la>
                            @svg('heroicon-o-plus', 'w-3.5 h-3.5') Neues GP aus Artikel
                        </button>
                    @endif
                </x-slot:actions>

                @if($item->structure?->gp)
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-gp-mapping-aktuell>
                        <p class="text-xs text-gray-900 min-w-0 truncate inline-flex items-center gap-1.5">@svg('heroicon-o-cube', 'w-3.5 h-3.5 shrink-0') {{ $item->structure->gp->name }}</p>
                        <button type="button" wire:click="gpLoesen" wire:confirm="GP-Zuordnung lösen? War das LA Lead, wird sofort neu gewählt (GL-03 I4)."
                                class="{{ $btnGhostXs }} text-rose-500 shrink-0" data-gp-loesen>@svg('heroicon-o-x-mark', 'w-3.5 h-3.5') lösen</button>
                    </div>
                @else
                    <p class="text-xs text-gray-500 italic" data-gp-mapping-leer>— kein GP zugeordnet —</p>
                @endif

                @if($gpVorschlaege !== [])
                    <div class="mt-2 rounded-lg bg-violet-500/5 border border-violet-500/20 px-3 py-2 space-y-1" data-gp-vorschlaege>
                        <p class="text-[11px] font-medium text-violet-700 inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Match-Kandidaten — Klick weist zu:</p>
                        @foreach($gpVorschlaege as $v)
                            <button type="button" wire:key="gpv-{{ $v['gp_id'] }}" wire:click="gpZuweisen({{ $v['gp_id'] }})"
                                    class="flex items-center gap-2 w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10" data-gp-vorschlag>
                                <span class="font-semibold {{ $v['score'] >= 90 ? 'text-green-600' : 'text-amber-500' }} shrink-0">{{ $v['score'] }} %</span>
                                <span class="min-w-0 truncate">{{ $v['name'] }}</span>
                                <span class="text-gray-500 shrink-0">{{ $v['grund'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="mt-2" data-gp-zuweisen>
                    <input type="search" wire:model.live.debounce.300ms="gpSuche"
                           placeholder="+ GP zuweisen — Name suchen …" class="{{ $input }} !py-1" />
                    @foreach($gpKandidaten as $kandidat)
                        <button type="button" wire:key="gpk-{{ $kandidat->id }}" wire:click="gpZuweisen({{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10">{{ $kandidat->name }}</button>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>
            </div>{{-- /Tab GP-MAPPING --}}

            {{-- ── Tab: PREISE ──────────────────────────────────────────────── --}}
            <div x-show="tab === 'preise'" x-cloak class="pt-4 space-y-4">

            <x-foodalchemist::modal-section title="Preise">
                {{-- R12 (Jarvis): EK-aktuell-Box + Tabelle gültig von/bis · Kategorie · Preis (+€/kg) · Notiz · ✎ --}}
                <div class="flex items-center justify-end gap-3 rounded-lg bg-black/[0.03] px-3 py-2 mb-2" data-ek-aktuell>
                    <p class="text-xs text-gray-900">EK aktuell:
                        <span class="font-semibold {{ $aktiverPreis !== null ? 'text-green-600' : 'text-gray-500' }}">{{ $aktiverPreis !== null ? number_format((float) $aktiverPreis->price, 2, ',', '.') . ' €' : '—' }}</span>
                        <span class="text-gray-500">pro {{ $item->ordering_unit ?? $item->unit_code ?? 'Einheit' }}{{ $vergleichspreis !== null ? ' · ' . number_format($vergleichspreis['value'], 2, ',', '.') . ' ' . $vergleichspreis['unit'] : '' }}</span>
                    </p>
                    @if($darfEdit)
                        <button type="button" x-data @click="$el.closest('[data-modal]').querySelector('[data-preis-neu]')?.classList.toggle('hidden')" class="{{ $btnPrimary }}" data-preis-neu-toggle>+ Neuer Preis</button>
                    @endif
                </div>
                @if($darfEdit)
                    <div class="hidden flex items-end gap-2 mb-3" data-preis-neu>
                        <div><label class="block {{ $label }} mb-1">Neuer Preis (€, netto)</label>
                            <input type="text" wire:model="preisNeu.price" placeholder="z. B. 47,50" class="{{ $input }} !w-36" /></div>
                        <div><label class="block {{ $label }} mb-1">Kategorie</label>
                            <select wire:model="preisNeu.status" class="{{ $input }} !w-40">
                                <option value="0">Standard-EK</option>
                                <option value="2">Aktion</option>
                            </select></div>
                        <button type="button" wire:click="preisAnlegen" class="{{ $btnGhostXs }} !px-3 !py-2 text-violet-600">Anlegen (schließt Vorgänger)</button>
                    </div>
                @endif
                <table class="{{ $table }}" data-preis-historie>
                    <thead><tr class="text-left">
                        @foreach(['Gültig von', 'Gültig bis', 'Kategorie', 'Preis', 'Notiz', ''] as $head)<th class="{{ $th }} !px-2 {{ $head === 'Preis' ? 'text-right' : '' }}">{{ $head }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @forelse($historie as $p)
                            <tr wire:key="preis-{{ $p->id }}" class="{{ $tr }}">
                                @if($preisEditId === $p->id)
                                    <td class="{{ $td }} !px-2 text-gray-600" colspan="2">
                                        bis: <input type="date" wire:model="preisEdit.valid_to" class="{{ $input }} !py-1 !w-36 inline-block" />
                                    </td>
                                    <td class="{{ $td }} !px-2"><span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $p->category->label() }}</span></td>
                                    <td class="{{ $td }} !px-2 text-right"><input type="text" wire:model="preisEdit.price" class="{{ $input }} !py-1 !w-24 text-right" /></td>
                                    <td class="{{ $td }} !px-2"><input type="text" wire:model="preisEdit.note" placeholder="Notiz" class="{{ $input }} !py-1 !w-32" /></td>
                                    <td class="{{ $td }} !px-2 text-right whitespace-nowrap">
                                        <button type="button" wire:click="preisUpdate" class="{{ $btnGhostXs }} text-emerald-600" data-preis-update>Speichern</button>
                                        <button type="button" wire:click="preisEditAbbrechen" class="{{ $btnGhostXs }}">Abbrechen</button>
                                    </td>
                                @else
                                    <td class="{{ $td }} !px-2 text-gray-600">{{ $p->status_valid_from ? \Illuminate\Support\Carbon::parse($p->status_valid_from)->format('Y-m-d') : ($p->creation_date ? \Illuminate\Support\Carbon::parse($p->creation_date)->format('Y-m-d') : '—') }}</td>
                                    <td class="{{ $td }} !px-2 text-gray-600">{{ $p->valid_to ? \Illuminate\Support\Carbon::parse($p->valid_to)->format('Y-m-d') : '—' }}</td>
                                    <td class="{{ $td }} !px-2"><span class="{{ $pill }} {{ $p->category->istAktiv() ? $variantPill['success'] : $variantPill['secondary'] }}">{{ $p->category->label() }}</span></td>
                                    <td class="{{ $td }} !px-2 text-right">
                                        <span class="text-gray-900 font-medium tabular-nums">{{ $p->price !== null ? number_format((float) $p->price, 2, ',', '.') . ' €' : '—' }}</span>
                                        @if($p->price !== null && (float) $item->qty > 0 && in_array($item->unit_code, ['kg', 'l', 'Stk'], true))
                                            <span class="block text-[11px] text-gray-500">= {{ number_format((float) $p->price / (float) $item->qty, 2, ',', '.') }} €/{{ $item->unit_code }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} !px-2 text-gray-600 text-[11px] max-w-[10rem] truncate" title="{{ $p->note ?? '' }}">{{ $p->note ?? '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right whitespace-nowrap">
                                        @if($darfEdit)
                                            <button type="button" wire:click="preisBearbeiten({{ $p->id }})" class="{{ $btnGhostXs }}" title="bearbeiten" data-preis-edit>@svg('heroicon-o-pencil', 'w-3.5 h-3.5')</button>
                                            <button type="button" wire:click="preisLoeschen({{ $p->id }})" wire:confirm="Preiszeile löschen?" class="{{ $btnGhostXs }} text-red-500">löschen</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-2 py-6 text-center text-gray-500">Keine Preiszeilen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-foodalchemist::modal-section>
            </div>{{-- /Tab PREISE --}}

            </x-foodalchemist::editor-tabs>
        @endif
    </x-foodalchemist::modal>
</div>
