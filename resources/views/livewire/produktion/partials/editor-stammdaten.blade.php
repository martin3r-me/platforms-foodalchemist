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
