{{-- „Als Vorlage speichern": nimmt den AKTUELLEN Tab-Stand (Brief + Kreativ-Modus + alle Leitplanken)
     als team-eigene Schnellstart-Vorlage auf — genau hier, wo die Regler eingestellt sind. Danach ★-Chip.
     Erwartet: $scope, $input, $btnGhost, $laeuft. --}}
{{-- Cockpit-Optik (Paket K, Rollout): die Zeile lag als nacktes div zwischen den Leitplanken-Karten
     direkt auf dem dunklen Editor-Canvas — das borderless $input (bg-black/[0.03]) war dort kaum als
     Feld erkennbar. Jetzt in einer eigenen Karte wie alles andere. Felder/Bindings/Anker unverändert. --}}
<x-foodalchemist::modal-section icon="heroicon-o-bookmark" title="Als Vorlage speichern">
    <div class="flex items-center gap-2 max-w-2xl" data-vorlage-speichern>
        <input type="text" wire:model="vorlageName"
               class="{{ $input }} flex-1 text-[11px]" placeholder="Aktuellen Stand als eigene Vorlage speichern — Name …" data-vorlage-name />
        <button type="button" wire:click="alsVorlageSpeichern('{{ $scope }}')" @disabled($laeuft)
                class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1 whitespace-nowrap text-[11px]" data-vorlage-speichern-btn>
            @svg('heroicon-o-bookmark', 'w-3.5 h-3.5') Als Vorlage speichern
        </button>
    </div>
    <p class="text-[10px] text-gray-500 mt-1">Nimmt Briefing, Kreativ-Modus und den kompletten Leitplanken-Stand dieses Tabs auf — erscheint danach als ★-Chip in der Eingabe-Karte.</p>
</x-foodalchemist::modal-section>
