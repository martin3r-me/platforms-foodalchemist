{{-- Erstell-Tab (Basisrezept ODER Gericht): EIGENES Briefing + EIGENE Leitplanken je Scope + Go + Wissen-vorab.
     Erwartet: $scope (rezept|gericht), $vk (bool), $goLabel, $goIcon. Jeder Tab ist unabhängig (eingabe.{scope}
     + regler.{scope}). Der Go schaltet auf den Worker-Tab. --}}
<div class="space-y-4">
    <x-foodalchemist::modal-section title="Eingabe — was soll entstehen">
        {{-- Schnellstart-Vorlagen (geteiltes Partial — auch im Concept-Tab): füllen Brief + Kreativ-Modus + Leitplanken. --}}
        @include('foodalchemist::livewire.planung.partials.schnellstart-chips', ['scope' => $scope])
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Titel</label>
        <div class="flex items-center gap-2 mb-3">
            <input type="text" wire:model="eingabe.{{ $scope }}.titel" class="{{ $input }} flex-1" placeholder="z. B. Tomatensauce" data-planung-titel />
            {{-- Et.4 Teil 3: nüchterner §-konformer Titelvorschlag aus dem Briefing (nur wenn Titelfeld leer). Kein Go. --}}
            <button type="button" wire:click="titelVorschlagen('{{ $scope }}')" @disabled($laeuft)
                    wire:loading.attr="disabled" wire:target="titelVorschlagen"
                    class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1 whitespace-nowrap" data-planung-titel-vorschlag>
                @svg('heroicon-o-sparkles', 'w-3.5 h-3.5')
                <span wire:loading.remove wire:target="titelVorschlagen">Titel vorschlagen</span>
                <span wire:loading wire:target="titelVorschlagen">…</span>
            </button>
        </div>
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Beschreibung (geht in die Erzeugung)</label>
        <textarea wire:model="eingabe.{{ $scope }}.brief" rows="3" class="{{ $input }} mb-3" placeholder="Constraints, Anlass, Richtung …"></textarea>
        @if($scope === 'rezept')
            <p class="text-[11px] text-gray-500">Basisrezepte haben keinen Kreativ-Modus. Vorhandene Basisrezepte und Grundprodukte werden zuerst geprüft; neu entsteht nur eine echte Lücke.</p>
        @else
            <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
            <select wire:model.live="eingabe.{{ $scope }}.creative_mode" class="{{ $input }}">
                @foreach($modeLabel as $val => $lbl)
                    <option value="{{ $val }}">{{ $lbl }}</option>
                @endforeach
            </select>
            <p class="text-[11px] text-gray-500 mt-1">{{ ($modeHint ?? [])[$eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
        @endif
    </x-foodalchemist::modal-section>

    @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => $scope])

    @include('foodalchemist::livewire.planung.partials.schnellstart-speichern', ['scope' => $scope])

    <x-foodalchemist::modal-section title="Go — {{ $scope === 'gericht' ? 'Gericht-Bauplan vorschlagen' : $goLabel . ' erzeugen (Draft)' }}">
        @include('foodalchemist::livewire.planung.partials.worker-praesenz')
        <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
            @if($scope === 'gericht')
                Zuerst entsteht nur ein textlicher Bauplan mit Komponenten. Erst nach deiner Annahme wird daraus ein Rezept-Draft und die Kaskade läuft weiter.
            @else
                Der Entwurf entsteht im Hintergrund (Draft); der Fortschritt läuft im <b>Worker</b>-Tab sichtbar durch.
            @endif
        </p>
        {{-- Composer-Übernahme: sichtbar machen, dass der Go auf die gewählten Foodpairing-Anker erdet. --}}
        @if(($composerSeedPin['scope'] ?? null) === $scope && !empty($composerSeedPin['slugs']))
            <div class="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-2 py-1.5" data-composer-seed-hint>
                <span class="text-[11px] text-violet-200">
                    🎯 Composer-Anker aktiv (Go erdet darauf): <b>{{ implode(', ', $composerSeedPin['slugs']) }}</b>
                </span>
                <button type="button" wire:click="$set('composerSeedPin', [])"
                        class="text-[10px] text-violet-300/70 hover:text-violet-200 underline">entfernen</button>
            </div>
        @endif
        <div class="flex flex-wrap gap-2 items-center">
            <button wire:click="goKaskade('{{ $scope }}')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                @svg($goIcon, 'w-4 h-4') {{ $scope === 'gericht' ? 'Bauplan vorschlagen' : $goLabel . ' erzeugen' }}
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
