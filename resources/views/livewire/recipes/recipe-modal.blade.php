{{-- Editor-Parität (Ist-App-Vorbild): EIN Voll-Editor — Stammdaten · Zutaten inline (P-8-Kern)
     · KPI-Leiste · Equipment gruppiert · Eigenschaften · Beschreibung · Zubereitung (Tabs) · Notizen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="recipe-modal" :title="$neu ? 'Basisrezept anlegen' : 'Rezept bearbeiten: ' . $form['name']" size="max-w-6xl">
    {{-- Aktionsleiste (D-5 §4.2.1) --}}
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
        @if(!$neu)
            <button type="button" wire:click="loeschen" wire:confirm="Rezept wirklich löschen? (Als Sub-Rezept referenzierte Rezepte sind geschützt)"
                    class="{{ $btnGhostXs }} text-rose-600 dark:text-rose-400" data-rezept-loeschen>Löschen</button>
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <button type="button" wire:click="allesAnreichern" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400"
                    title="D-5 §4.4: Vorschläge für Beschreibung · Kategorie · Geschmack (Review, nie Auto-Persistenz)" data-alles-anreichern>✨ Alles anreichern</button>
        @endif
    </x-slot:actions>

    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    {{-- ✨-Anreichern-Lauf (M7-06-Mechanik auf EIN Rezept) --}}
    @if($bulkRun !== null)
        <div class="mb-3 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-sm flex items-center gap-2"
             @if($bulkRun->status === 'running') wire:poll.2s @endif data-anreichern-status>
            @if($bulkRun->status === 'running')
                <span>✨ Anreicherung läuft …</span>
            @else
                <span>✨ {{ $bulkOffen }} Vorschläge offen{{ $bulkRun->fehler > 0 ? " · {$bulkRun->fehler} Fehler" : '' }}</span>
                <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-anreichern-uebernehmen>Alle übernehmen</button>
            @endif
        </div>
    @endif

    {{-- STAMMDATEN (§4.2.2) — ✨-Aktionen im Sektions-Header (Ist-App-Pattern) --}}
    <x-foodalchemist::modal-section title="Stammdaten">
        <x-slot:actions>
            <button type="button" wire:click="namePutzen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="§1-Syntax normalisieren">✨ Name putzen</button>
            @if(!$neu)
                <button type="button" wire:click="ai_kategorie" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="D-1-Klassifikation (GL-07-Vorschlag unten)">✨ Kategorie</button>
            @endif
            <button type="button" wire:click="kiFertigung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Fertigungstiefe aus den Zutaten">✨ Fertigung</button>
        </x-slot:actions>

        <div>
            <label class="block {{ $label }} mb-1">Name *</label>
            <input type="text" wire:model.live.debounce.300ms="form.name" placeholder="Schaumsauce: Beurre Blanc" class="{{ $input }} !text-base" data-rezept-name />
            <p class="text-[11px] text-gray-400 mt-1">Syntax §1.2: <code>Typ: Bezeichnung (Variante)</code>, Title Case.
                @if($keyVorschau !== '')<span class="font-mono" data-key-vorschau>recipe_key: {{ $keyVorschau }}{{ $neu ? '' : ' (bleibt beim Edit stabil)' }}</span>@endif
            </p>
        </div>
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block {{ $label }} mb-1">Herkunft / Quelle <span class="normal-case text-gray-400">(nicht im Namen — §1.6)</span></label>
                <input type="text" wire:model="form.herkunft" placeholder="z. B. Broich, nach Paul, nach Omas Art" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Status (§4.2.8)</label>
                <select wire:model="form.status" class="{{ $input }}" data-rezept-status @disabled($neu)>
                    @foreach(['stub' => 'Stub', 'draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigegeben', 'archived' => 'Archiviert'] as $wert => $lbl)
                        <option value="{{ $wert }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Hauptgruppe * <span class="normal-case text-gray-400">({{ $hauptgruppen->count() }} kuratiert)</span></label>
                <select wire:model.live="form.hauptgruppe_id" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach($hauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->bezeichnung }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Kategorie * <span class="normal-case text-gray-400">({{ $kategorien->count() }} in dieser Hauptgruppe)</span></label>
                <select wire:model.live="form.kategorie_id" class="{{ $input }}" @disabled($kategorien->isEmpty())>
                    <option value="">—</option>
                    @foreach($kategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->bezeichnung }}</option>@endforeach
                </select>
            </div>
        </div>
        @if(isset($kiVorschlag['kategorie']))
            <div class="mt-2 text-sm" data-kategorie-vorschlag>
                <span class="{{ $pill }} {{ $variantPill['primary'] }}">✨ Kategorie: {{ $kiVorschlag['kategorie']['werte']['kategorie_name'] ?? $kiVorschlag['kategorie']['werte']['kategorie_id'] ?? '—' }} · {{ round($kiVorschlag['kategorie']['confidence'] * 100) }} %</span>
                <button type="button" wire:click="accept_kategorie" class="{{ $btnGhostXs }} text-emerald-600">Übernehmen</button>
            </div>
        @endif
        <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300 mt-3">
            <input type="checkbox" wire:model="form.ist_verkaufsrezept" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
            Verkaufsrezept (D-6 — VK-Felder im VK-Editor)
        </label>
    </x-foodalchemist::modal-section>

    {{-- ZUTATEN (§4.2.3) — der P-8-Kern eingebettet + KPI-Leiste (Ist-App unten) --}}
    @if(!$neu)
        <x-foodalchemist::modal-section title="Zutaten ({{ $voll?->ingredients?->count() ?? 0 }})">
            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="zutaten-inline-{{ $recipeId }}" />

            @if($voll !== null)
                <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-2" data-editor-kpis>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Yield</span>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') . ' kg' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">EK gesamt</span>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $voll->ek_total_eur !== null ? number_format((float) $voll->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 dark:text-orange-400">EK / kg</span>
                        <p class="text-sm font-bold text-orange-700 dark:text-orange-300">{{ $voll->ek_per_kg_eur !== null ? number_format((float) $voll->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Mit Preis</span>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $voll->ek_n_ingredients_priced ?? 0 }}/{{ $voll->ek_n_ingredients_total ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Allergen-Konf.</span>
                        <p class="text-sm font-semibold {{ ['high' => 'text-emerald-600', 'medium' => 'text-amber-600', 'low' => 'text-rose-600'][$voll->allergene_konfidenz] ?? 'text-gray-400' }}">{{ strtoupper((string) $voll->allergene_konfidenz) }}</p>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="block {{ $label }} mb-1">Yield manuell (kg, A-3 — Vorrang vor Auto-Summe)</label>
                    <input type="text" wire:model="form.yield_kg_manual" placeholder="leer = Auto ({{ $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') : '—' }})" class="{{ $input }} !w-48" data-yield-manual />
                </div>
            @endif
        </x-foodalchemist::modal-section>
    @endif

    {{-- EQUIPMENT (§4.2.6) — gruppiert nach Vokabular-Gruppe (Ist-App-Layout) --}}
    <x-foodalchemist::modal-section title="Equipment">
        <x-slot:actions>
            @if(!$neu)<button type="button" wire:click="kiEquipment" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Set-Vorschlag aus den Zutaten (in die Auswahl, nichts persistiert)">✨ Equipment</button>@endif
        </x-slot:actions>
        <div class="space-y-1.5" data-rezept-equipment>
            @foreach($equipmentListe->groupBy(fn ($g) => $g->gruppe ?? 'sonstig') as $gruppe => $geraete)
                <div class="flex items-start gap-2">
                    <span class="{{ $dt }} w-28 shrink-0 pt-1">{{ $gruppe }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($geraete as $geraet)
                            <label class="inline-flex items-center gap-1 {{ $pill }} cursor-pointer transition-colors
                                          {{ in_array((string) $geraet->id, $form['equipment_ids'], true) ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                   wire:key="eq-{{ $geraet->id }}">
                                <input type="checkbox" wire:model.live="form.equipment_ids" value="{{ $geraet->id }}" class="hidden" />
                                {{ $geraet->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-foodalchemist::modal-section>

    {{-- EIGENSCHAFTEN (§4.2.4) --}}
    <x-foodalchemist::modal-section title="Eigenschaften">
        <x-slot:actions>
            <button type="button" wire:click="kiEigenschaften" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Arbeitszeit/Temperatur/Funktion + Geschmack (in die Felder, nichts persistiert)">✨ Eigenschaften</button>
        </x-slot:actions>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block {{ $label }} mb-1">Arbeitszeit (min)</label>
                <input type="number" wire:model="form.arbeitszeit_min" min="0" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Temperatur</label>
                <input type="text" wire:model="form.temperatur" placeholder="z. B. raumtemperatur, warm, kalt" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Funktion</label>
                <input type="text" wire:model="form.funktion" placeholder="z. B. Sauce, Bindung, Topping" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Geschmacksrichtung <span class="normal-case text-gray-400">(via ✨ oder manuell)</span></label>
                <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="suess">süß</option><option value="herzhaft">herzhaft</option><option value="neutral">neutral</option>
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Fertigungstiefe <span class="normal-case text-gray-400">(via ✨ Fertigung oder manuell)</span></label>
                <select wire:model="form.fertigungstiefe" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="from_scratch">From Scratch</option><option value="teilfertig">teilfertig</option><option value="convenience">Convenience</option>
                </select>
            </div>
        </div>
    </x-foodalchemist::modal-section>

    {{-- BESCHREIBUNG (§8) --}}
    <x-foodalchemist::modal-section title="Beschreibung (§8.3 — 3-5 Sätze nüchtern)">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="ai_beschreibung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-ai-beschreibung>✨ Beschreibung</button>
                <button type="button" wire:click="manual_beschreibung" class="{{ $btnGhostXs }}" title="aktuellen Text als manuell markieren (Override-First-Schutz)">als manuell</button>
                <button type="button" wire:click="clear_beschreibung" class="{{ $btnGhostXs }}" title="Feld + Lineage leeren">Reset</button>
            @endif
        </x-slot:actions>
        <textarea wire:model="form.beschreibung" rows="3" class="{{ $input }}"></textarea>
        @if(isset($kiVorschlag['beschreibung']))
            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-beschreibung-vorschlag>
                <p class="text-xs text-violet-700 dark:text-violet-300 italic">{{ $kiVorschlag['beschreibung']['werte']['beschreibung'] ?? '—' }}</p>
                <button type="button" wire:click="accept_beschreibung" class="{{ $btnGhostXs }} text-emerald-600 mt-1">Übernehmen ({{ round($kiVorschlag['beschreibung']['confidence'] * 100) }} %)</button>
            </div>
        @endif
        @if(!$neu)<p class="text-[10px] text-gray-400 mt-1">Lineage: {{ $zustaende['beschreibung'] }}</p>@endif
    </x-foodalchemist::modal-section>

    {{-- ZUBEREITUNG (§4.2.5) — Schreiben/Vorschau-Tabs (Ist-App) --}}
    <x-foodalchemist::modal-section title="Zubereitung">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="ai_zubereitung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-ai-zubereitung>✨ Zubereitung</button>
                <button type="button" wire:click="manual_zubereitung" class="{{ $btnGhostXs }}" title="als manuell markieren">als manuell</button>
                <button type="button" wire:click="clear_zubereitung" class="{{ $btnGhostXs }}">Reset</button>
            @endif
        </x-slot:actions>
        <div x-data="{ tab: 'schreiben' }" data-zubereitung-tabs>
            <div class="flex items-center gap-1 mb-1.5">
                <button type="button" @click="tab = 'schreiben'" :class="tab === 'schreiben' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'" class="{{ $pill }}">Schreiben</button>
                <button type="button" @click="tab = 'vorschau'; $wire.vorschauZubereitung()" :class="tab === 'vorschau' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'" class="{{ $pill }}" data-tab-vorschau>Vorschau</button>
                <span class="text-[10px] text-gray-400 ml-2">Markdown — <code>##</code> für Phasen (Mise en Place / Finish), nummerierte Schritte</span>
            </div>
            <div x-show="tab === 'schreiben'">
                <textarea wire:model="form.zubereitung" rows="8" class="{{ $input }} font-mono text-xs" data-rezept-zubereitung></textarea>
            </div>
            <div x-show="tab === 'vorschau'" x-cloak class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-4 py-3 prose prose-sm dark:prose-invert max-w-none" data-zubereitung-vorschau>
                {!! $zubereitungVorschau ?? '<p class="text-gray-400">Vorschau lädt …</p>' !!}
            </div>
        </div>
        @if(isset($kiVorschlag['zubereitung']))
            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 max-h-40 overflow-y-auto" data-zubereitung-ki-vorschlag>
                <p class="text-xs text-violet-700 dark:text-violet-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($kiVorschlag['zubereitung']['werte']['zubereitung'] ?? '—', 900) }}</p>
                <button type="button" wire:click="accept_zubereitung" class="{{ $btnGhostXs }} text-emerald-600 mt-1">Übernehmen ({{ round($kiVorschlag['zubereitung']['confidence'] * 100) }} %)</button>
            </div>
        @endif
        @if(!$neu)<p class="text-[10px] text-gray-400 mt-1">Lineage: {{ $zustaende['zubereitung'] }}</p>@endif
    </x-foodalchemist::modal-section>

    {{-- NOTIZEN (§9.1 — manuelle Insel) --}}
    <x-foodalchemist::modal-section title="Notizen (§9.1 — bleibt bei jedem KI-Sync erhalten)">
        <textarea wire:model="form.notizen_manual" rows="3" class="{{ $input }}" data-rezept-notizen
                  placeholder="z. B. Anpassung im Catering-Kontext, Mengen-Korrektur, …"></textarea>
    </x-foodalchemist::modal-section>

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern-footer>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-foodalchemist::modal>
