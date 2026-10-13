<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MasterTeamService;

/**
 * Spec 82 §13 B4 — Master-Team festlegen. Eingebettet in die Plattform-Verwaltung des Hosts
 * (`<livewire:foodalchemist.verwaltung.master-team />`). Nur Plattform-Admin. Ein Wechsel ändert die Sichtbarkeit aller Abos:
 * mit Abos erst Vorschau, dann ausdrückliche Bestätigung; ohne Abos geht er direkt. ENV bleibt Fallback.
 */
class MasterTeam extends Component
{
    /** Gewähltes Zielteam (0 = Eintrag entfernen, ENV gilt). */
    public string $zielTeamId = '';

    public bool $vorschauOffen = false;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function vorschau(): void
    {
        $this->fehler = $this->meldung = null;
        if (! $this->istAdmin() || $this->zielTeamId === '') {
            return;
        }
        $this->vorschauOffen = true;
    }

    public function abbrechen(): void
    {
        $this->vorschauOffen = false;
    }

    public function festlegen(): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $id = (int) $this->zielTeamId;
            app(MasterTeamService::class)->setze($id > 0 ? Team::findOrFail($id) : null, Auth::user(), true);
            $this->vorschauOffen = false;
            $this->meldung = $id > 0 ? 'Master-Team gesetzt.' : 'Eintrag entfernt, die Umgebungsvariable gilt wieder.';
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function istAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ! $user->isAiUser() && app(FaRechte::class)->istPlattformAdmin($user);
    }

    public function render()
    {
        $admin = $this->istAdmin();
        $svc = app(MasterTeamService::class);
        $ziel = $this->zielTeamId === '' ? null : (int) $this->zielTeamId;

        return view('foodalchemist::livewire.verwaltung.master-team', [
            'admin' => $admin,
            'master' => $admin ? $svc->aktuell() : null,
            'teams' => $admin ? Team::orderBy('name')->get(['id', 'name']) : collect(),
            'vorschau' => $admin && $this->vorschauOffen && $ziel !== null ? $svc->vorschau($ziel) : null,
        ]);
    }
}
