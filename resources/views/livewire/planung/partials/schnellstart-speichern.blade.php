{{-- „Als Vorlage speichern": nimmt den AKTUELLEN Stand des Reiters (Brief + Kreativ-Modus + alle Leitplanken)
     als team-eigene Schnellstart-Vorlage auf, genau hier, wo die Regler eingestellt sind. Danach Stern-Chip.
     Erwartet: $scope, $laeuft.
     fa-pass: Feld + Knopf aus den Bausteinen (Tokens, hell + Werkbank). Felder, Bindings, Anker unverändert. --}}
<x-foodalchemist::modal-section icon="heroicon-o-bookmark" title="Als Vorlage speichern">
    <p class="mb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-2xl">Merkt sich Briefing, Kreativ-Modus und alle Leitplanken dieses Reiters. Die Vorlage erscheint danach mit Stern oben bei „Schnellstart mit Vorlage".</p>
    <div class="flex flex-wrap items-center gap-2 max-w-2xl" data-vorlage-speichern>
        <x-fa::input wire:model="vorlageName" class="flex-1 min-w-[14rem]" placeholder="Name der Vorlage" aria-label="Name der Vorlage" data-vorlage-name />
        <x-fa::button icon="heroicon-o-bookmark" wire:click="alsVorlageSpeichern('{{ $scope }}')" :disabled="$laeuft" data-vorlage-speichern-btn>Vorlage speichern</x-fa::button>
    </div>
</x-foodalchemist::modal-section>
