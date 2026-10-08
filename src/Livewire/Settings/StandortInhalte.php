<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\StandortService;

/**
 * Spec 77d · Einstellungen → Inhalte für Standorte. Je Standort (Unter-Team): Haken „übernimmt alles vom
 * Oberteam" oder nur Freigegebenes — Sammlungen und fertige Ausgaben. Sammlungen werden hier gepflegt.
 * Die Rechte prüft `InhaltsFreigabeService` (Freigaben FA-Admin, Sammlungen ab Kuratieren).
 */
class StandortInhalte extends Component
{
    public ?string $fehler = null;

    public ?string $meldung = null;

    public string $neueSammlung = '';

    /** @var array<int, string> Standort-ID → „typ:id" der Ausgabe, die freigegeben werden soll */
    public array $freigabeNeu = [];

    /** Sammlung, in die gerade gesucht wird, und der Suchtext. */
    public ?int $sammlungOffen = null;

    public string $suche = '';

    public function erbtAllesSetzen(int $unterTeamId, bool $an, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->setzeErbtAlles($this->team(), $unterTeamId, $an, Auth::user()),
            $an ? 'Der Standort übernimmt wieder alles vom Oberteam.' : 'Der Standort sieht jetzt nur noch, was ihm freigegeben ist.');
    }

    public function freigeben(int $unterTeamId, InhaltsFreigabeService $svc): void
    {
        [$typ, $id] = array_pad(explode(':', (string) ($this->freigabeNeu[$unterTeamId] ?? '')), 2, null);
        if ($typ === null || $id === null || $id === '') {
            $this->fehler = 'Bitte zuerst eine Ausgabe oder Sammlung wählen.';

            return;
        }
        $this->ausfuehren(fn () => $svc->freigeben($this->team(), $typ, (int) $id, $unterTeamId, Auth::user()), 'Freigegeben.');
        unset($this->freigabeNeu[$unterTeamId]);
    }

    public function freigabeEntziehen(int $freigabeId, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->freigabeEntziehen($this->team(), $freigabeId, Auth::user()), 'Freigabe entzogen.');
    }

    public function sammlungAnlegen(InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(function () use ($svc) {
            $this->sammlungOffen = $svc->sammlungAnlegen($this->team(), $this->neueSammlung, null, Auth::user());
            $this->neueSammlung = '';
        }, 'Sammlung angelegt.');
    }

    public function sammlungLoeschen(int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungLoeschen($this->team(), $id, Auth::user()), 'Sammlung gelöscht.');
    }

    public function sammlungOeffnen(int $id): void
    {
        $this->sammlungOffen = $this->sammlungOffen === $id ? null : $id;
        $this->suche = '';
    }

    public function sammlungHinzu(int $sammlungId, string $typ, int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungHinzu($this->team(), $sammlungId, $typ, [$id], Auth::user()), 'Hinzugefügt.');
    }

    public function sammlungEntfernen(int $sammlungId, string $typ, int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungEntfernen($this->team(), $sammlungId, $typ, $id, Auth::user()), 'Entfernt.');
    }

    public function render(InhaltsFreigabeService $svc, StandortService $standorte)
    {
        $team = $this->team();
        $unter = $standorte->unterTeams($team);
        foreach ($unter as &$u) {
            $u['erbt_alles'] = $svc->erbtAlles(Team::find($u['id']));
        }
        unset($u);
        $sammlungen = $svc->sammlungen($team);

        // Eigene Ausgaben (Kunden-IP: nur eigene, nie geerbte) als Freigabe-Auswahl
        $ausgaben = ['Sammlungen' => collect($sammlungen)->mapWithKeys(fn ($s) => ['sammlung:'.$s['id'] => $s['name']])->all()];
        foreach (['foodbook' => ['foodalchemist_foodbooks', 'Foodbooks', 'label'], 'speiseplan' => ['foodalchemist_menu_plans', 'Speisepläne', 'name'], 'speisekarte' => ['foodalchemist_menu_cards', 'Speisekarten', 'name']] as $typ => [$tabelle, $label, $spalte]) {
            $ausgaben[$label] = DB::table($tabelle)->where('team_id', $team->id)->whereNull('deleted_at')->orderBy($spalte)->limit(300)
                ->pluck($spalte, 'id')->mapWithKeys(fn ($n, $id) => [$typ.':'.$id => (string) $n])->all();
        }

        $treffer = [];
        if ($this->sammlungOffen !== null && mb_strlen(trim($this->suche)) >= 2) {
            $s = '%'.trim($this->suche).'%';
            foreach (['recipe' => FoodAlchemistRecipe::class, 'concept' => FoodAlchemistConcept::class, 'format' => FoodAlchemistFormat::class] as $typ => $klasse) {
                foreach ($klasse::visibleToTeam($team)->where('name', 'like', $s)->orderBy('name')->limit(8)->get(['id', 'name']) as $m) {
                    $treffer[] = ['typ' => $typ, 'id' => (int) $m->id, 'name' => (string) $m->name];
                }
            }
        }

        return view('foodalchemist::livewire.settings.standort-inhalte', [
            'standorte' => $unter,
            'freigaben' => collect($svc->freigaben($team))->groupBy('empfaenger_team_id'),
            'sammlungen' => $sammlungen,
            'ausgaben' => $ausgaben,
            'treffer' => $treffer,
            'typLabels' => InhaltsFreigabeService::SAMMLUNG_TYPEN,
            'ausgabeLabels' => InhaltsFreigabeService::AUSGABE_TYPEN,
        ]);
    }

    private function ausfuehren(callable $fn, string $ok): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $fn();
            $this->meldung = $ok;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
