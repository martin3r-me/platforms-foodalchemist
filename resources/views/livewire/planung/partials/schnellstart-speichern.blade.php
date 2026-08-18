{{-- „Als Vorlage speichern": nimmt den AKTUELLEN Tab-Stand (Brief + Kreativ-Modus + alle Leitplanken)
     als team-eigene Schnellstart-Vorlage auf — genau hier, wo die Regler eingestellt sind. Danach ★-Chip.
     Erwartet: $scope, $input, $btnGhost, $laeuft. --}}
<div class="flex items-center gap-2" data-vorlage-speichern>
    <input type="text" wire:model="vorlageName"
           class="{{ $input }} flex-1 text-[11px]" placeholder="Aktuellen Stand als eigene Vorlage speichern — Name …" data-vorlage-name />
    <button type="button" wire:click="alsVorlageSpeichern('{{ $scope }}')" @disabled($laeuft)
            class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1 whitespace-nowrap text-[11px]" data-vorlage-speichern-btn>
        @svg('heroicon-o-bookmark', 'w-3.5 h-3.5') Als Vorlage speichern
    </button>
</div>
