{{-- M4-06: Rezept-Stammdaten-Modal (P-2) — §1-Syntax-Hint, Name-putzen-KI, HG→Kategorie --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="recipe-modal" :title="$neu ? 'Basisrezept anlegen' : 'Rezept bearbeiten'" size="max-w-2xl">
    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    <x-foodalchemist::modal-section title="Stammdaten (§1)">
        <div>
            <label class="block {{ $label }} mb-1">Name * <span class="normal-case text-gray-400">— Syntax: <code class="text-[10px]">&lt;Typ&gt;: &lt;Bezeichnung&gt;[, Zusatz]</code> (z. B. „Sorbet: Birne")</span></label>
            <div class="flex items-center gap-1.5">
                <input type="text" wire:model.live.debounce.300ms="form.name" placeholder="Schaumsauce: Beurre Blanc" class="{{ $input }}" data-rezept-name />
                <button type="button" wire:click="namePutzen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0"
                        title="§1-Syntax via KI normalisieren (ai_normalize_recipe_name)">✨ putzen</button>
            </div>
            @if($keyVorschau !== '')
                <p class="text-[11px] text-gray-400 font-mono mt-1" data-key-vorschau>recipe_key: {{ $keyVorschau }}{{ $neu ? '' : ' (bleibt beim Edit stabil)' }}</p>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block {{ $label }} mb-1">Hauptgruppe</label>
                <select wire:model.live="form.hauptgruppe_id" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach($hauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->bezeichnung }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Kategorie</label>
                <select wire:model.live="form.kategorie_id" class="{{ $input }}" @disabled($kategorien->isEmpty())>
                    <option value="">—</option>
                    @foreach($kategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->bezeichnung }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Herkunft (§1.6)</label>
                <input type="text" wire:model="form.herkunft" placeholder="z. B. Texas, Klassik, Hausrezept" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Arbeitszeit (min)</label>
                <input type="number" wire:model="form.arbeitszeit_min" min="0" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Geschmack</label>
                <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="suess">süß</option><option value="herzhaft">herzhaft</option><option value="neutral">neutral</option>
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Fertigungstiefe</label>
                <select wire:model="form.fertigungstiefe" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="from_scratch">from scratch</option><option value="teilfertig">teilfertig</option><option value="convenience">Convenience</option>
                </select>
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
                <label class="block {{ $label }} mb-1">Temperatur (Eigenschaft)</label>
                <select wire:model="form.temperatur" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="warm">warm</option><option value="kalt">kalt</option><option value="beides">beides</option>
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Funktion (Eigenschaft)</label>
                <input type="text" wire:model="form.funktion" placeholder="z. B. Saucenbasis, Bindung, Topping" class="{{ $input }}" />
            </div>
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Kalkulation (GL-02)">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block {{ $label }} mb-1">Yield manuell (kg, A-3 — Vorrang vor Auto-Summe)</label>
                <input type="text" wire:model="form.yield_kg_manual" placeholder="leer = Auto" class="{{ $input }}" data-yield-manual />
            </div>
            <label class="inline-flex items-end gap-1.5 text-sm text-gray-600 dark:text-gray-300 pb-2">
                <input type="checkbox" wire:model="form.ist_verkaufsrezept" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                Verkaufsrezept (D-6 — VK-Editor folgt M6)
            </label>
        </div>
        <div class="mt-3">
            <label class="block {{ $label }} mb-1">Beschreibung (§8-Stil)</label>
            <textarea wire:model="form.beschreibung" rows="3" class="{{ $input }}"></textarea>
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Zubereitung (§4.2.5)">
        <textarea wire:model="form.zubereitung" rows="6" class="{{ $input }} font-mono text-xs"
                  placeholder="Markdown — nummerierte Schritte, Temperaturen/Zeiten konkret; ## für Phasen (z. B. ## Finish)" data-rezept-zubereitung></textarea>
        <p class="text-[10px] text-gray-400 mt-1">✨-Vorschlag aus den Zutaten: unten in den KI-Feldern (V-02-Degenerations-Schutz im Gateway).</p>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Equipment (§4.2.6)">
        <div class="flex flex-wrap gap-1.5" data-rezept-equipment>
            @foreach($equipmentListe as $geraet)
                <label class="inline-flex items-center gap-1 {{ $pill }} cursor-pointer transition-colors
                              {{ in_array((string) $geraet->id, $form['equipment_ids'], true) ? $variantPill['primary'] : $variantPill['secondary'] }}"
                       wire:key="eq-{{ $geraet->id }}">
                    <input type="checkbox" wire:model.live="form.equipment_ids" value="{{ $geraet->id }}" class="hidden" />
                    {{ $geraet->name }}
                </label>
            @endforeach
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Notizen (§9.1 — manuelle Insel, kein KI-Accept überschreibt)">
        <textarea wire:model="form.notizen_manual" rows="2" class="{{ $input }}" data-rezept-notizen
                  placeholder="Interne Notizen (bleiben bei jeder KI-Anreicherung unberührt)"></textarea>
    </x-foodalchemist::modal-section>

    {{-- M4-11: KI-Felder (GL-07) — brauchen ein persistiertes Rezept --}}
    @if(!$neu)
        <x-foodalchemist::modal-section title="KI-Felder (GL-07)">
            @php($r = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::find($recipeId))
            <div class="space-y-4">
                <x-foodalchemist::ki-header label="Beschreibung (§8-Stil)" field="beschreibung"
                    :quelle="$zustaende['beschreibung'] === 'unbefüllt' ? null : $zustaende['beschreibung']"
                    :confidence="$r?->beschreibung_ai_confidence !== null ? (float) $r->beschreibung_ai_confidence : null"
                    :hasProposal="isset($kiVorschlag['beschreibung'])">
                    @if(isset($kiVorschlag['beschreibung']))
                        <p class="text-xs text-violet-600 dark:text-violet-400 italic" data-beschreibung-vorschlag>{{ $kiVorschlag['beschreibung']['werte']['beschreibung'] ?? '—' }}</p>
                    @endif
                </x-foodalchemist::ki-header>
                <x-foodalchemist::ki-header label="Zubereitung" field="zubereitung"
                    :quelle="$zustaende['zubereitung'] === 'unbefüllt' ? null : $zustaende['zubereitung']"
                    :confidence="$r?->zubereitung_ai_confidence !== null ? (float) $r->zubereitung_ai_confidence : null"
                    :hasProposal="isset($kiVorschlag['zubereitung'])">
                    @if(isset($kiVorschlag['zubereitung']))
                        <p class="text-xs text-violet-600 dark:text-violet-400 italic whitespace-pre-line max-h-32 overflow-y-auto" data-zubereitung-vorschlag>{{ \Illuminate\Support\Str::limit($kiVorschlag['zubereitung']['werte']['zubereitung'] ?? '—', 600) }}</p>
                    @endif
                </x-foodalchemist::ki-header>
                <x-foodalchemist::ki-header label="Kategorie" field="kategorie"
                    :quelle="$zustaende['kategorie'] === 'unbefüllt' ? null : $zustaende['kategorie']" :confidence="$r?->kategorie_ai_confidence !== null ? (float) $r->kategorie_ai_confidence : null"
                    :begruendung="$r?->kategorie_ai_begruendung" :hasProposal="isset($kiVorschlag['kategorie'])">
                    @if(isset($kiVorschlag['kategorie']))
                        <span class="{{ $pill }} {{ $variantPill['primary'] }}" data-kategorie-vorschlag>Vorschlag: {{ $kiVorschlag['kategorie']['werte']['kategorie_name'] ?? $kiVorschlag['kategorie']['werte']['kategorie_id'] ?? '—' }}</span>
                    @endif
                </x-foodalchemist::ki-header>
            </div>
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-foodalchemist::modal>
