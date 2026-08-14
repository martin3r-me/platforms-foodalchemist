{{-- Erstell-Tab (Basisrezept ODER Gericht): EIGENES Briefing + EIGENE Leitplanken je Scope + Go + Wissen-vorab.
     Erwartet: $scope (rezept|gericht), $vk (bool), $goLabel, $goIcon. Jeder Tab ist unabhängig (eingabe.{scope}
     + regler.{scope}). Der Go schaltet auf den Worker-Tab. --}}
<div class="space-y-4">
    <x-foodalchemist::modal-section title="Eingabe — was soll entstehen">
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Titel</label>
        <input type="text" wire:model="eingabe.{{ $scope }}.titel" class="{{ $input }} mb-3" placeholder="z. B. Tomatensauce" data-planung-titel />
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Beschreibung (geht in die Erzeugung)</label>
        <textarea wire:model="eingabe.{{ $scope }}.brief" rows="3" class="{{ $input }} mb-3" placeholder="Constraints, Anlass, Richtung …"></textarea>
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
        <select wire:model="eingabe.{{ $scope }}.creative_mode" class="{{ $input }}">
            @foreach($modeLabel as $val => $lbl)
                <option value="{{ $val }}">{{ $lbl }}</option>
            @endforeach
        </select>
    </x-foodalchemist::modal-section>

    @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => $scope])

    <x-foodalchemist::modal-section title="Go — {{ $goLabel }} erzeugen (Draft)">
        <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
            Der Entwurf entsteht im Hintergrund (Draft); der Fortschritt läuft im <b>Worker</b>-Tab sichtbar durch.
        </p>
        <div class="flex flex-wrap gap-2 items-center">
            <button wire:click="goKaskade('{{ $scope }}')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                @svg($goIcon, 'w-4 h-4') {{ $goLabel }} erzeugen
            </button>
            <button type="button" wire:click="wissenVorschau('{{ $scope }}')" @disabled($laeuft)
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
