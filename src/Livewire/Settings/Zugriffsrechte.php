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
 *
 * Spec 82 §13 A: Bereiche und Kontingente werden NUR in der Plattform-Verwaltung (/verwaltung, eingebettet) gepflegt — es gibt eine Stelle
 * zum Steuern. Diese Seite zeigt sie nur lesend (gebucht, genutzt). Es gibt hier bewusst KEINE Schreib-Methoden dafür: Ein Hand-Aufruf
 * (`teamBereichSetzen`, `kontingenteSpeichern`) trifft ins Leere. Die Dienste (`FaRechte::setzeTeamBereich`/`setzeKontingente`) bleiben
 * für Verwaltung und MCP bestehen.
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

    /** Spec 77b: Mitglied, dessen Bereichs-Einschränkungen gerade aufgeklappt sind. */
    public ?int $bereicheFuer = null;

    public function bereicheUmschalten(int $userId): void
    {
        $this->bereicheFuer = $this->bereicheFuer === $userId ? null : $userId;
    }

    public function userBereichSetzen(int $userId, string $bereich, bool $darf, FaRechte $rechte): void
    {
        $this->ausfuehren(fn () => $rechte->setzeUserSperre($this->team(), Auth::user(), $userId, $bereich, ! $darf), 'Gespeichert.');
    }

    // ── Spec 84 §4.4 (E3): Support-Zugang auf Freigabe ────────────────

    public int $supportStunden = 48;

    public string $supportGrund = '';

    public function supportErteilen(\Platform\FoodAlchemist\Services\SupportFreigabeService $svc): void
    {
        $this->ausfuehren(function () use ($svc) {
            $svc->erteilen($this->team(), Auth::user(), $this->supportStunden, null, $this->supportGrund);
            $this->supportGrund = '';
        }, 'Support-Zugang erteilt. Jeder Zugriff wird protokolliert.');
    }

    public function supportWiderrufen(int $id, \Platform\FoodAlchemist\Services\SupportFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->widerrufen($this->team(), Auth::user(), $id), 'Support-Zugang widerrufen.');
    }

    private function ausfuehren(callable $tu, string $ok): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $tu();
            $this->meldung = $ok;
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
            // Spec 77b
            'katalog' => \Platform\FoodAlchemist\Support\FaBereiche::KATALOG,
            'teamBereiche' => $rechte->teamBereiche($team),
            'istPlattformAdmin' => Auth::user() !== null && $rechte->istPlattformAdmin(Auth::user()),
            'kontingente' => $rechte->kontingente($team),
            'nutzung' => $rechte->kontingentNutzung($team),
            'istHauptTeam' => $rechte->hauptTeam($team)->id === $team->id,
            'userSperren' => $this->bereicheFuer !== null ? $rechte->userSperren($team, $this->bereicheFuer) : [],
            // Spec 84 §4.4 (E3): nur Owner/Admin des Hauptteams sieht und pflegt den Support-Zugang
            'supportFreigaben' => $this->supportListe($team),
        ]);
    }

    private function supportListe(Team $team): ?\Illuminate\Support\Collection
    {
        try {
            return app(\Platform\FoodAlchemist\Services\SupportFreigabeService::class)->liste($team, Auth::user());
        } catch (\RuntimeException) {
            return null;   // kein Owner/Admin des Hauptteams oder Master-Team → Abschnitt ausblenden
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
