<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Services\MenuEngineeringService;
use Platform\FoodAlchemist\Services\SalesImportService;
use RuntimeException;

/**
 * Spec 32 · C3 — die Erlösseite: Verkaufs-Ist einlesen, offene Zeilen zuordnen,
 * Menu-Engineering-Matrix lesen.
 *
 * Drei Schritte in einer Fläche, weil sie eine Kette sind: ohne Import keine Matrix, und
 * eine Matrix, die stillschweigend die ungematchten Zeilen weglässt, wäre eine Lüge über
 * den eigenen Umsatz. Die Zahl der offenen Zuordnungen steht darum ständig daneben.
 *
 * Der Import läuft **immer zuerst als Trockenlauf** — der scharfe Lauf ist ein zweiter,
 * ausdrücklicher Klick auf denselben Bericht.
 */
class Erfolg extends Component
{
    use WithFileUploads;

    /** Hochzuladende CSV (landet im festen Ablage-Ordner). */
    public $datei = null;

    /** Gewählte Datei aus dem Ablage-Ordner. */
    public string $dateiname = '';

    /** Spalten-Zuordnung: Feldname => Spalten-Index (als String aus dem Select). */
    public array $mapping = [];

    /** Kopfzeile + Vorschlag + Beispielzeilen der gewählten Datei. */
    public ?array $kopf = null;

    /** Bericht des letzten Laufs (Trockenlauf oder scharf). */
    public ?array $bericht = null;

    /** Zeitraum-Filter der Matrix (leer = alles). */
    public string $von = '';

    public string $bis = '';

    /** Offene Zuordnung, die gerade bearbeitet wird. */
    public ?int $zuordnenId = null;

    public string $zuordnenSuche = '';

    public ?string $hinweis = null;

    public ?string $fehler = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    /** Hochgeladene Datei in den Ablage-Ordner legen — Dateiname, kein freier Pfad. */
    public function hochladen(): void
    {
        $this->hinweis = null;
        $this->fehler = null;

        $this->validate(['datei' => 'required|file|mimes:csv,txt,tsv|max:20480'], [], ['datei' => 'Datei']);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $this->datei->getClientOriginalName()) ?: 'verkauf.csv';
        $this->datei->storeAs(SalesImportService::ORDNER, $name);

        $this->datei = null;
        $this->dateiname = $name;
        $this->hinweis = 'Datei abgelegt: ' . $name;
        $this->kopfLesen(app(SalesImportService::class));
    }

    /** Kopfzeile lesen und die vorgeschlagene Zuordnung übernehmen. */
    public function kopfLesen(SalesImportService $import): void
    {
        $this->fehler = null;
        $this->bericht = null;
        if (trim($this->dateiname) === '') {
            $this->kopf = null;

            return;
        }
        try {
            $this->kopf = $import->kopf($this->dateiname);
            $this->mapping = array_map('strval', $this->kopf['vorschlag']);
        } catch (RuntimeException $e) {
            $this->kopf = null;
            $this->fehler = $e->getMessage();
        }
    }

    public function trockenlauf(SalesImportService $import): void
    {
        $this->lauf($import, false);
    }

    public function scharf(SalesImportService $import): void
    {
        $this->lauf($import, true);
    }

    private function lauf(SalesImportService $import, bool $apply): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet.';

            return;
        }

        // Leere Auswahl heißt „Spalte nicht zugeordnet" — nicht „Spalte 0".
        $mapping = [];
        foreach ($this->mapping as $feld => $idx) {
            if ($idx !== '' && $idx !== null) {
                $mapping[$feld] = (int) $idx;
            }
        }

        try {
            $this->bericht = $import->importiere($team, $this->dateiname, $mapping, $apply);
            $this->hinweis = $apply
                ? 'Import geschrieben: ' . $this->bericht['neu'] . ' neu, ' . $this->bericht['aktualisiert'] . ' aktualisiert.'
                : 'Trockenlauf — es wurde nichts geschrieben.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function zuordnenOeffnen(int $factId): void
    {
        $this->zuordnenId = $factId;
        $this->zuordnenSuche = '';
    }

    public function zuordnenAbbrechen(): void
    {
        $this->zuordnenId = null;
        $this->zuordnenSuche = '';
    }

    public function zuordnen(int $recipeId, SalesImportService $import): void
    {
        $team = $this->team();
        if ($team === null || $this->zuordnenId === null) {
            return;
        }

        $this->hinweis = $import->zuordnen($team, $this->zuordnenId, $recipeId)
            ? 'Zuordnung gesetzt — sie überlebt den nächsten Import.'
            : null;
        $this->fehler = $this->hinweis === null ? 'Zuordnung nicht möglich.' : null;
        $this->zuordnenAbbrechen();
    }

    /** Gericht-Treffer für die Zuordnungs-Suche (max. 8). */
    public function getTrefferProperty(): array
    {
        $q = trim($this->zuordnenSuche);
        $team = $this->team();
        if ($team === null || $this->zuordnenId === null || mb_strlen($q) < 2) {
            return [];
        }

        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)
            ->where('name', 'like', '%' . $q . '%')->orderBy('name')->limit(8)
            ->get(['foodalchemist_recipes.id', 'name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->all();
    }

    public function render(SalesImportService $import, MenuEngineeringService $engineering)
    {
        $team = $this->team();

        $offen = $team === null ? collect() : FoodAlchemistSalesFact::where('team_id', $team->id)
            ->whereNull('recipe_id')
            ->selectRaw('MIN(id) AS id, raw_label, COUNT(*) AS n, SUM(revenue_net) AS umsatz')
            ->groupBy('raw_label')->orderByDesc('umsatz')->limit(30)->get();

        return view('foodalchemist::livewire.controlling.panels.erfolg', [
            'dateien' => $import->dateien(),
            'felder' => SalesImportService::FELDER,
            'matrix' => $team !== null
                ? $engineering->matrix($team, $this->von ?: null, $this->bis ?: null)
                : null,
            'offen' => $offen,
            'treffer' => $this->getTrefferProperty(),
        ]);
    }
}
