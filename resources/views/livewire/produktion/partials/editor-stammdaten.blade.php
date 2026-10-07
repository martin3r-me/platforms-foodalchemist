    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 flex flex-col gap-4">
    <x-fa::section title="Auftrag" icon="heroicon-o-clipboard-document-list">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-fa::field label="Name" for="produktion-name" required>
                <x-fa::input id="produktion-name" wire:model="name" placeholder="z. B. Sommerfest Vormittag" data-produktion-name />
            </x-fa::field>
            <x-fa::field label="Liefertag" for="produktion-datum" required hint="An diesem Tag muss alles fertig sein.">
                <x-fa::input id="produktion-datum" type="date" wire:model="productionDate" data-produktion-datum />
            </x-fa::field>
            <x-fa::field label="Anlass" for="produktion-anlass" optional class="sm:col-span-2">
                <x-fa::input id="produktion-anlass" wire:model="reference" placeholder="z. B. Sommer-Buffet" data-produktion-anlass />
            </x-fa::field>
            <x-fa::field label="Notiz" for="produktion-notiz" optional class="sm:col-span-2">
                <x-fa::textarea id="produktion-notiz" wire:model="note" rows="3" placeholder="Hinweise für die Küche" />
            </x-fa::field>
        </div>
    </x-fa::section>

    {{-- Küchen-Manager: Überproduktions-/Puffer-% — skaliert Ansätze + Einkauf, Ziele bleiben im Original --}}
    <x-fa::section title="Puffer" icon="heroicon-o-arrow-trending-up"
                   description="Erhöht Ansätze und Einkauf um diesen Anteil. Die Ziele bleiben unverändert. 0 bedeutet kein Puffer.">
        <x-fa::field label="Überproduktion in Prozent" for="produktion-puffer" class="w-48">
            <x-fa::input id="produktion-puffer" type="number" min="0" max="100" step="1" numeric wire:model.live.debounce.400ms="puffer" data-produktion-puffer />
        </x-fa::field>
    </x-fa::section>
    </div>{{-- /Stammdaten-Panel --}}
