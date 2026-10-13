<?php

namespace Platform\FoodAlchemist\Livewire\Projekte;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\CrmKunden;
use Platform\FoodAlchemist\Support\FaShell;

/**
 * Spec 82 §9 · Projekte (Agentur-Akten im Master-Team): Liste und „Projekt anlegen“. Der Bereich „projekte“ ist nur im
 * Master-Team freigeschaltet (Middleware/Hook); anlegen darf ab Rolle „Kuratieren“. Die CRM-Firma wird nur verknüpft.
 */
class Index extends Component
{
    public string $firmaSuche = '';

    public ?int $firmaId = null;

    public ?string $firmaName = null;

    public string $name = '';

    public string $ziel = '';

    public bool $anlegenOffen = false;

    public ?string $fehler = null;

    public function firmaWaehlen(int $id): void
    {
        $firma = CrmKunden::firma(CrmKunden::aktuellesTeam(), $id);
        if ($firma === null) {
            $this->fehler = 'Diese Firma gibt es im CRM nicht.';

            return;
        }
        $this->firmaId = (int) $firma->id;
        $this->firmaName = (string) $firma->display_name;
        $this->firmaSuche = '';
        if (trim($this->name) === '') {
            $this->name = (string) $firma->display_name;
        }
    }

    public function firmaLoesen(): void
    {
        $this->firmaId = $this->firmaName = null;
    }

    public function anlegen()
    {
        $this->fehler = null;
        $team = CrmKunden::aktuellesTeam();
        if ($team === null || $this->firmaId === null) {
            $this->fehler = 'Bitte zuerst die CRM-Firma wählen.';

            return null;
        }
        try {
            $p = app(ProjektService::class)->anlegen($team, Auth::user(), $this->firmaId, $this->name, $this->ziel);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('foodalchemist.projekte.show', ['projekt' => $p->id], navigate: true);
    }

    public function render()
    {
        $team = CrmKunden::aktuellesTeam();
        $projekte = $team === null ? collect() : app(ProjektService::class)->liste($team);
        $firmen = $team !== null && CrmKunden::verfuegbar() && $this->firmaId === null ? CrmKunden::firmen($team, $this->firmaSuche) : collect();
        $namen = [];
        if ($projekte->isNotEmpty() && CrmKunden::verfuegbar()) {
            $namen = \Platform\Crm\Models\CrmCompany::whereIn('id', $projekte->pluck('crm_company_id'))->get()->mapWithKeys(fn ($c) => [(int) $c->id => (string) $c->display_name])->all();
        }
        $user = Auth::user();

        return view('foodalchemist::livewire.projekte.index', [
            'projekte' => $projekte, 'firmenNamen' => $namen, 'firmen' => $firmen, 'phasen' => FoodAlchemistProjekt::PHASEN,
            'darfSchreiben' => $team !== null && app(FaRechte::class)->darf($user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren),
            'crmVerfuegbar' => CrmKunden::verfuegbar(),
        ])->layout(FaShell::layout());
    }
}
