<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 61/75 · Einstellungen → Zugriffsrechte. Zeigt, was jede Plattform-Rolle im Food Alchemist darf
 * (Rollen pflegt der Team-Admin in den Team-Einstellungen der Plattform), und pflegt das eine
 * FA-Zusatzrecht: „darf Rechnungen freigeben" für Mitglieder. Ändern nur als FA-Admin; die Prüfung
 * sitzt in `FaRechte::setzeFreigabe`, die Oberfläche blendet nur aus.
 */
class Zugriffsrechte extends Component
{
    public ?string $fehler = null;

    public ?string $meldung = null;

    public function freigabeSetzen(int $userId, bool $darf, FaRechte $rechte): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $rechte->setzeFreigabe($this->team(), Auth::user(), $userId, $darf);
            $this->meldung = $darf ? 'Darf jetzt Rechnungen freigeben.' : 'Freigaberecht entzogen.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(FaRechte $rechte)
    {
        $team = $this->team();

        return view('foodalchemist::livewire.settings.zugriffsrechte', [
            'mitglieder' => $rechte->mitglieder($team),
            'rollen' => FaRolle::cases(),
            'istAdmin' => $rechte->darf(Auth::user(), $team, FaRolle::Admin),
            'meineRolle' => $rechte->rolle(Auth::user(), $team),
        ]);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
