<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;

/**
 * Spec 82 §13 I — Paket „Grundeinstellungen" buchen. Eingebettet in die Plattform-Verwaltung des Hosts
 * (`<livewire:foodalchemist.verwaltung.grundeinstellungen />`), wie das Stamm-Paket (B1): Der Host bettet ein, das Modul besitzt
 * Tabelle, Dienst, Rechteprüfung und Oberfläche. Nur Plattform-Admin. Erst Vorschau (was kopiert würde), dann Buchen.
 */
class Grundeinstellungen extends Component
{
    public ?int $vorschauTeamId = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function vorschau(int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        $this->vorschauTeamId = $this->istAdmin() ? $teamId : null;
    }

    public function abbrechen(): void
    {
        $this->vorschauTeamId = null;
    }

    public function buchen(int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $mandant = Team::findOrFail($teamId);
            $r = app(PaketGrundeinstellungenService::class)->buchen($mandant, Auth::user());
            $neu = array_sum(array_column($r['ergebnis'], 'neu'));
            $this->vorschauTeamId = null;
            $this->meldung = 'Paket „Grundeinstellungen“ für „'.$mandant->name.'“ gebucht: '.$neu.' Zeile(n) kopiert.';
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
        $svc = app(PaketGrundeinstellungenService::class);

        return view('foodalchemist::livewire.verwaltung.grundeinstellungen', [
            'admin' => $admin,
            'mandanten' => $admin ? $svc->uebersicht() : [],
            'vorschau' => $admin && $this->vorschauTeamId !== null && ($t = Team::find($this->vorschauTeamId)) ? ['team' => $t, 'daten' => $svc->vorschau($t)] : null,
        ]);
    }
}
