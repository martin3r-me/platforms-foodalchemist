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

    /** Spec 77b: Mitglied, dessen Bereichs-Einschränkungen gerade aufgeklappt sind. */
    public ?int $bereicheFuer = null;

    /** @var array{max_standorte:string, max_user:string, ki_budget_eur_monat:string} */
    public array $kontingentForm = ['max_standorte' => '', 'max_user' => '', 'ki_budget_eur_monat' => ''];

    public function mount(): void
    {
        $k = app(FaRechte::class)->kontingente($this->team());
        $this->kontingentForm = array_map(fn ($v) => $v === null ? '' : str_replace('.', ',', (string) $v), $k);
    }

    public function bereicheUmschalten(int $userId): void
    {
        $this->bereicheFuer = $this->bereicheFuer === $userId ? null : $userId;
    }

    public function userBereichSetzen(int $userId, string $bereich, bool $darf, FaRechte $rechte): void
    {
        $this->ausfuehren(fn () => $rechte->setzeUserSperre($this->team(), Auth::user(), $userId, $bereich, ! $darf), 'Gespeichert.');
    }

    public function teamBereichSetzen(string $bereich, bool $aktiv, FaRechte $rechte): void
    {
        $this->ausfuehren(fn () => $rechte->setzeTeamBereich($this->team(), Auth::user(), $bereich, $aktiv), 'Bereich gespeichert.');
    }

    public function kontingenteSpeichern(FaRechte $rechte): void
    {
        $this->ausfuehren(fn () => $rechte->setzeKontingente($this->team(), Auth::user(), $this->kontingentForm), 'Kontingente gespeichert.');
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
        ]);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
