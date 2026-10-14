<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MandantBeendenService;
use Platform\FoodAlchemist\Services\MandantenService;

/**
 * Spec 82 §13 C3 — Mandant beenden: Vorschau, Bestätigung, Entsperren. Eingebettet in die Plattform-Verwaltung des Hosts
 * (`<livewire:foodalchemist.verwaltung.mandant-beenden />`), nur Plattform-Admin. Zeigt Verwaltungsdaten und Zählungen, nie Inhalte (§13 G).
 */
class MandantBeenden extends Component
{
    /** Mandant, dessen Vorschau offen ist. */
    public ?int $vorschauTeamId = null;

    public bool $bestaetigt = false;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function vorschau(int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        $this->bestaetigt = false;
        $this->vorschauTeamId = $this->istAdmin() ? $teamId : null;
    }

    public function abbrechen(): void
    {
        $this->vorschauTeamId = null;
        $this->bestaetigt = false;
    }

    public function beenden(int $teamId): void
    {
        $this->aktion(function (MandantBeendenService $svc, Team $mandant) {
            $r = $svc->fuehreAus($mandant, Auth::user(), $this->bestaetigt);
            $this->meldung = $r['abgeschlossen']
                ? '„'.$mandant->name.'“ ist beendet und gesperrt.'
                : 'Angehalten bei „'.(MandantBeendenService::TITEL[$r['angehalten_bei']] ?? $r['angehalten_bei']).'“: '.$r['grund'];
            $this->bestaetigt = false;
            if ($r['abgeschlossen']) {
                $this->vorschauTeamId = null;
            }
        }, $teamId);
    }

    public function entsperren(int $teamId): void
    {
        $this->aktion(function (MandantBeendenService $svc, Team $mandant) {
            $svc->entsperren($mandant, Auth::user());
            $this->meldung = '„'.$mandant->name.'“ ist wieder aktiv. Die Bereiche bleiben aus, bis sie neu gebucht werden.';
        }, $teamId);
    }

    private function istAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ! $user->isAiUser() && app(FaRechte::class)->istPlattformAdmin($user);
    }

    /** @param  callable(MandantBeendenService, Team): void  $fn */
    private function aktion(callable $fn, int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $fn(app(MandantBeendenService::class), Team::findOrFail($teamId));
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $admin = $this->istAdmin();
        $vorschau = null;
        if ($admin && $this->vorschauTeamId !== null && ($t = Team::find($this->vorschauTeamId)) !== null) {
            try {
                $vorschau = app(MandantBeendenService::class)->vorschau($t, Auth::user());
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }

        return view('foodalchemist::livewire.verwaltung.mandant-beenden', [
            'admin' => $admin,
            // Das Master-Team lässt sich nicht beenden (MandantBeendenService::pruefeMandant): in der Liste ohne Knopf, mit Hinweis
            'mandanten' => $admin ? array_map(fn (array $m) => $m + ['ist_master' => \Platform\FoodAlchemist\Support\MasterTeam::istMaster(Team::find($m['team_id']))],
                app(MandantenService::class)->uebersicht()) : [],
            'vorschau' => $vorschau,
        ]);
    }
}
