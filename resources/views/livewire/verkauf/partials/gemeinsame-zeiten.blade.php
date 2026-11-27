<x-fa::card as="details" class="p-4" data-vk-eigenschaften="">
    <summary class="cursor-pointer font-semibold text-[length:var(--fa-text-lg)]">Übergreifende Angaben</summary>
    <p class="mt-3 mb-3 {{ $hinweis }}">Gilt für alle Darreichungen. Aktive Arbeitszeiten stehen an der jeweiligen Darreichung.</p>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-vk-produktion>
        <x-fa::field label="Passive Standzeit (min)" for="vk-standzeit" hint="Wartezeit ohne Personenlohn">
            <x-fa::input id="vk-standzeit" type="number" min="0" wire:model="form.standzeit_min" numeric />
        </x-fa::field>
        <x-fa::field label="Nebenkosten (€ je Portion)" for="vk-nebenkosten" hint="Gemeinsame Zusatzkosten des Gerichts">
            <x-fa::input id="vk-nebenkosten" type="number" min="0" step="0.01" wire:model="form.additional_costs_eur" numeric data-vk-nebenkosten />
        </x-fa::field>
    </div>
    <p class="mt-3 {{ $hinweis }}">EK der Rezeptur je kg: <x-fa::money :value="$rezept->ek_per_kg_eur" /></p>
    @if($beteiligtePosten->isNotEmpty())<p class="{{ $hinweis }}" data-vk-beteiligte-posten>Beteiligt aus den Basisrezepten: {{ $beteiligtePosten->map(fn ($p) => $p['name'])->implode(' · ') }}</p>@endif
</x-fa::card>
