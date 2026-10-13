<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Livewire\Component;

/**
 * Spec 82 §5 · „Unter-Teams & Freigaben" als Einstellungs-Sektion des Oberteams.
 *
 * Der Editor (Haken „übernimmt alles", Freigaben, Sammlungen) ist der frühere Controlling-Reiter
 * „Standorte & Freigaben" — hier eingebettet, nicht doppelt gebaut. Die Rechte prüft weiter
 * `InhaltsFreigabeService`; diese Hülle schreibt nichts selbst. Ein Unter-Team sieht die Sektion
 * nicht (Index::NUR_OBERTEAM), es hat keine Unter-Teams.
 */
class UnterTeams extends Component
{
    public function mount(): void
    {
        // zweite Absicherung neben Settings\Index: die Komponente selbst lädt nur mit freigeschaltetem Bereich
        abort_unless(Index::bereichErlaubt('unter-teams'), 404);
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.unter-teams');
    }
}
