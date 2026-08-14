{{-- Erstell-Tab (Basisrezept ODER Gericht): Brief + Kreativ-Modus + geteilte Leitplanken + Go + Wissen-vorab.
     Erwartet: $scope (rezept|gericht), $vk (bool), $goLabel, $goIcon. Der Go schaltet auf den Worker-Tab. --}}
<div class="space-y-4">
    <x-foodalchemist::modal-section title="Brief">
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Brief (geht in die Erzeugung)</label>
        <textarea wire:model="form.brief" rows="3" class="{{ $input }} mb-3" placeholder="Was soll entstehen? Constraints, Anlass, Richtung …"></textarea>
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
        <select wire:model="form.creative_mode" class="{{ $input }}">
            @foreach($modeLabel as $val => $lbl)
                <option value="{{ $val }}">{{ $lbl }}</option>
            @endforeach
        </select>
    </x-foodalchemist::modal-section>

    @include('foodalchemist::livewire.planung.partials.leitplanken', ['vk' => $vk])

    <x-foodalchemist::modal-section title="Go — {{ $goLabel }} erzeugen (Draft)">
        <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
            Der Entwurf entsteht im Hintergrund (Draft); der Fortschritt läuft im <b>Worker</b>-Tab sichtbar durch.
        </p>
        <div class="flex flex-wrap gap-2 items-center">
            <button wire:click="goKaskade('{{ $scope }}')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                @svg($goIcon, 'w-4 h-4') {{ $goLabel }} erzeugen
            </button>
            <button type="button" wire:click="wissenVorschau({{ $vk ? 'true' : 'false' }})" @disabled($laeuft)
                    wire:loading.attr="disabled" wire:target="wissenVorschau"
                    class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1" data-planung-wissen-vorab>
                @svg('heroicon-o-magnifying-glass', 'w-3.5 h-3.5')
                <span wire:loading.remove wire:target="wissenVorschau">Wissen vorab prüfen</span>
                <span wire:loading wire:target="wissenVorschau">Wissen wird geladen …</span>
            </button>
        </div>
        @if($wissenVorschau !== null)
            <div class="mt-2 rounded-lg bg-white/5 p-2" data-planung-wissen-vorschau>
                <p class="text-[10px] text-gray-400 mb-1">Das würde die KI nutzen (Vorschau — noch nicht generiert):</p>
                <x-foodalchemist::kontext-inspektor :kontext="$wissenVorschau" />
            </div>
        @endif
    </x-foodalchemist::modal-section>
</div>
