<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\StammPaketService;

/**
 * Spec 82 §13 B1 — Stamm-Paket buchen und abbestellen. Eingebettet in die Plattform-Verwaltung des Hosts
 * (`<livewire:foodalchemist.verwaltung.stamm-paket />`): Der Host bettet ein, das Modul besitzt Tabelle, Dienst, Rechteprüfung und Oberfläche.
 * Nur Plattform-Admin. Beim Abbestellen erst die Wirkungs-Vorschau (welche Abos ungültig würden), dann Bestätigung.
 */
class StammPaket extends Component
{
    /** Mandant, dessen Abbestell-Vorschau offen ist. */
    public ?int $vorschauTeamId = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function buchen(int $teamId): void
    {
        $this->aktion(function (StammPaketService $svc, Team $mandant) {
            $svc->buchen($mandant, Auth::user());
            $this->meldung = 'Stamm-Paket für „'.$mandant->name.'“ gebucht.';
        }, $teamId);
    }

    public function vorschau(int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        $this->vorschauTeamId = $this->istAdmin() ? $teamId : null;
    }

    public function abbrechen(): void
    {
        $this->vorschauTeamId = null;
    }

    public function abbestellen(int $teamId): void
    {
        $this->aktion(function (StammPaketService $svc, Team $mandant) {
            $svc->abbestellen($mandant, Auth::user(), true);
            $this->vorschauTeamId = null;
            $this->meldung = 'Stamm-Paket für „'.$mandant->name.'“ abbestellt.';
        }, $teamId);
    }

    private function istAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ! $user->isAiUser() && app(FaRechte::class)->istPlattformAdmin($user);
    }

    /** @param  callable(StammPaketService, Team): void  $fn */
    private function aktion(callable $fn, int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $fn(app(StammPaketService::class), Team::findOrFail($teamId));
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $admin = $this->istAdmin();
        $svc = app(StammPaketService::class);

        return view('foodalchemist::livewire.verwaltung.stamm-paket', [
            'admin' => $admin,
            'mandanten' => $admin ? $svc->uebersicht() : [],
            'vorschau' => $admin && $this->vorschauTeamId !== null && ($t = Team::find($this->vorschauTeamId)) ? ['team' => $t, 'daten' => $svc->vorschauAbbestellen($t)] : null,
        ]);
    }
}
