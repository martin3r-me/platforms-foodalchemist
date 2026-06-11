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

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-foodalchemist::modal>
