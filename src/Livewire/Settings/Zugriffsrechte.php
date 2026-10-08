<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 61 · Einstellungen → Zugriffsrechte: FA-Rolle je Mitglied. Nur FA-Admins ändern; alle sehen,
 * wer was darf. Inhaber/Admins des Teams sind immer FA-Admin (Team-Rolle in der Verwaltung).
 * Die Prüfung sitzt in `FaRechte::setzeRolle` — die Oberfläche blendet nur aus.
 */
class Zugriffsrechte extends Component
{
    public ?string $fehler = null;

    public ?string $meldung = null;

    public function rolleSetzen(int $userId, string $rolle, FaRechte $rechte): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $ziel = FaRolle::tryFrom($rolle) ?? throw new \RuntimeException('Unbekannte Rolle.');
            $rechte->setzeRolle($this->team(), Auth::user(), $userId, $ziel);
            $this->meldung = 'Rolle gespeichert.';
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
