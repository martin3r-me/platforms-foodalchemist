{{-- Erstell-Tab (Basisrezept ODER Gericht): EIGENES Briefing + EIGENE Leitplanken je Scope + Go + Wissen-vorab.
     Erwartet: $scope (rezept|gericht), $vk (bool), $goLabel, $goIcon. Jeder Tab ist unabhängig (eingabe.{scope}
     + regler.{scope}). Der Go schaltet auf den Worker-Tab. --}}
<div class="space-y-4">
    <x-foodalchemist::modal-section title="Eingabe — was soll entstehen">
        {{-- Schnellstart-Vorlagen je Sektor/Anlass (Etappe 4): füllen Briefing + Kontext, statt Blank Page.
             Nur eingeblendet, wenn es für diesen Tab Vorlagen gibt (Teil 1: nur Gericht). --}}
        @php($vorlagen = $this->vorlagenFuer($scope))
        @if(count($vorlagen))
            <div class="mb-3" data-brief-vorlagen>
                <label class="{{ $label ?? 'text-[11px] text-gray-500' }} block mb-1">Schnellstart-Vorlage (optional)</label>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($vorlagen as $vslug => $v)
                        <button type="button" wire:click="briefVorlage('{{ $scope }}', '{{ $vslug }}')"
                                class="px-2.5 py-1 rounded-full border border-black/10 bg-white/5 text-[11px] text-gray-700 hover:bg-violet-500/10 transition-colors"
                                data-brief-vorlage="{{ $vslug }}">{{ $v['label'] }}</button>
                    @endforeach
                </div>
                <p class="text-[11px] text-gray-500 mt-1">Füllt Briefing und Sektor/Anlass als Startpunkt — alles frei anpassbar.</p>
            </div>
        @endif
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
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
        <select wire:model.live="eingabe.{{ $scope }}.creative_mode" class="{{ $input }}">
            @foreach($modeLabel as $val => $lbl)
                <option value="{{ $val }}">{{ $lbl }}</option>
            @endforeach
        </select>
        <p class="text-[11px] text-gray-500 mt-1">{{ ($modeHint ?? [])[$eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
    </x-foodalchemist::modal-section>

    @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => $scope])

    <x-foodalchemist::modal-section title="Go — {{ $goLabel }} erzeugen (Draft)">
        @include('foodalchemist::livewire.planung.partials.worker-praesenz')
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
