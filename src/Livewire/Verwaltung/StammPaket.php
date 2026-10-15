<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
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

    /** Mandant, dessen Lieferanten-Freischaltung offen ist. Stabile Adresse: /verwaltung?tab=freigaben&lieferanten={Mandanten-Team-ID} */
    #[Url(as: 'lieferanten')]
    public ?int $lieferantenTeamId = null;

    public string $lieferantenSuche = '';

    /** @var list<int> Auswahl im offenen Panel (Checkboxen) */
    public array $auswahl = [];

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

    public function lieferantenOeffnen(int $teamId): void
    {
        $this->fehler = $this->meldung = null;
        $this->lieferantenTeamId = $this->istAdmin() ? $teamId : null;
        $this->auswahlLaden();
    }

    public function lieferantenSchliessen(): void
    {
        $this->lieferantenTeamId = null;
        $this->auswahl = [];
    }

    public function alleWaehlen(): void
    {
        $t = $this->lieferantenTeam();
        $this->auswahl = $t ? array_column(app(StammPaketService::class)->lieferanten($t)['lieferanten'], 'id') : [];
    }

    public function keineWaehlen(): void
    {
        $this->auswahl = [];
    }

    public function lieferantenSpeichern(): void
    {
        $this->fehler = $this->meldung = null;
        $t = $this->lieferantenTeam();
        if (! $t) {
            return;
        }
        try {
            $r = app(StammPaketService::class)->setzeLieferanten($t, array_map('intval', $this->auswahl), Auth::user());
            $this->meldung = $r['freigegeben'].' von '.$r['gesamt'].' Lieferanten für „'.$t->name.'“ freigeschaltet.';
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function lieferantenTeam(): ?Team
    {
        return $this->istAdmin() && $this->lieferantenTeamId !== null ? Team::find($this->lieferantenTeamId) : null;
    }

    private function auswahlLaden(): void
    {
        $t = $this->lieferantenTeam();
        $this->auswahl = $t ? array_column(array_filter(app(StammPaketService::class)->lieferanten($t)['lieferanten'], fn ($z) => $z['frei']), 'id') : [];
    }

    public function mount(): void
    {
        // Sprung per Adresse (?lieferanten=ID): Auswahl des Mandanten vorbelegen
        if ($this->lieferantenTeamId !== null) {
            $this->auswahlLaden();
        }
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
            'lieferantenPanel' => ($lt = $this->lieferantenTeam()) ? ['team' => $lt, 'daten' => $svc->lieferanten($lt, $this->lieferantenSuche)] : null,
            'vorschau' => $admin && $this->vorschauTeamId !== null && ($t = Team::find($this->vorschauTeamId)) ? ['team' => $t, 'daten' => $svc->vorschauAbbestellen($t)] : null,
        ]);
    }
}
