<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec-42-Vollzug S3a — Kapitel-Ebenen-Steuerung IN der Leitstelle. Ersetzt die Kapitel-Planung der
 * Foodbook-`LeitstelleRail`: listet die Kapitel des Owner-Foodbooks, editiert je Kapitel die M3-Ziele
 * (reine Setter {@see FoodbookService::updateKapitel}/`setKapitelZielgruppen`) und startet je Kapitel einen
 * gezielten Kaskaden-Teil-Lauf ({@see PlanningCascadeService::starteKapitelKaskade}) — statt des alten
 * kaskaden-fremden `kapitelFreigeben`-Bypass. Nested-Livewire (Muster {@see \Platform\FoodAlchemist\Livewire\Foodbooks\LeitstelleRail}).
 */
class KapitelRail extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public int $foodbookId;

    /** Aktive Planungs-Session (für den Kaskaden-Lauf-Bezug); optional. */
    public ?int $sessionId = null;

    /** Aktuell zum Ziele-Editieren aufgeklapptes Kapitel (Toggle). */
    public ?int $offenId = null;

    /** M3-Ziel-Formular des offenen Kapitels. */
    public array $ziel = [];

    /** Gestempelte Zielgruppen-IDs des offenen Kapitels (lokaler Spiegel fürs Chip-Toggle). */
    public array $zielgruppenIds = [];

    public ?string $meldung = null;

    /** Kapitel auf-/zuklappen; beim Aufklappen die M3-Ziele laden. */
    public function oeffne(int $kapitelId): void
    {
        if ($this->offenId === $kapitelId) {
            $this->offenId = null;

            return;
        }
        $this->offenId = $kapitelId;
        $this->ladeKapitel();
    }

    private function ladeKapitel(): void
    {
        $k = $this->kapitel($this->offenId);
        if ($k === null) {
            $this->offenId = null;

            return;
        }
        $this->ziel = [
            'niveau' => $k->niveau,
            'serving_form_id' => $k->serving_form_id,
            'service_moment_id' => $k->service_moment_id,
            'pricing_mode' => $k->pricing_mode,
            'target_count' => $k->target_count,
            'price_anchor' => $k->price_anchor,
            'price_min' => $k->price_min,
            'price_max' => $k->price_max,
            'target_food_cost_pct' => $k->target_food_cost_pct,
        ];
        $k->loadMissing('targetGroups:id');
        $this->zielgruppenIds = $k->targetGroups->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** Kapitel-Ziele (M3) speichern — leere Strings → null (numerische/FK-Felder). */
    public function zieleSpeichern(FoodbookService $svc): void
    {
        if ($this->offenId === null) {
            return;
        }
        $in = [];
        foreach ($this->ziel as $feld => $wert) {
            $in[$feld] = ($wert === '' ? null : $wert);
        }
        $svc->updateKapitel($this->team(), $this->offenId, $in);
        $this->savedToast('Kapitel-Ziele gespeichert');
    }

    /** Zielgruppen-Stempel des offenen Kapitels umschalten (PUT-sync auf die volle Liste). */
    public function zielgruppeToggle(int $id, FoodbookService $svc): void
    {
        if ($this->offenId === null) {
            return;
        }
        $this->zielgruppenIds = in_array($id, $this->zielgruppenIds, true)
            ? array_values(array_diff($this->zielgruppenIds, [$id]))
            : [...$this->zielgruppenIds, $id];
        $svc->setKapitelZielgruppen($this->team(), $this->offenId, $this->zielgruppenIds);
    }

    /**
     * Kapitel-Go NEU (S3a): gezielter Kaskaden-Teil-Lauf — die Concept(s) dieses Kapitels werden FRISCH
     * über den Motor generiert und docken ans Kapitel. Meldet dem Eltern-Editor `kaskade-gestartet`
     * (Worker-Tab neu laden). Ersetzt den alten `kapitelFreigeben`-Bypass.
     */
    public function kapitelErzeugen(int $kapitelId, PlanningCascadeService $cascade): void
    {
        $this->meldung = null;
        $team = $this->team();
        try {
            $session = $this->sessionId !== null
                ? FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId)
                : null;
            $cascade->starteKapitelKaskade($team, $session, 'voll_kreativ', $this->foodbookId, $kapitelId);
            $this->dispatch('kaskade-gestartet');
        } catch (\Throwable $e) {
            $this->meldung = $e->getMessage();
        }
    }

    public function render(FoodbookService $svc)
    {
        $team = $this->team();
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find($this->foodbookId);
        $kapitel = $fb !== null ? $svc->kapitelTree($team, $this->foodbookId) : [];
        // „läuft"-Zustand je Kapitel: ein laufender Step mit diesem chapter_id (owner=foodbook, diese Karte).
        $laeuftMap = FoodAlchemistCascadeRunStep::query()
            ->whereNotNull('chapter_id')
            ->where('status', 'running')
            ->whereHas('run', fn ($q) => $q->where('source_owner_type', 'foodbook')->where('source_owner_id', $this->foodbookId))
            ->pluck('chapter_id')->map(fn ($x) => (int) $x)->flip()->all();

        return view('foodalchemist::livewire.planung.kapitel-rail', [
            'fb' => $fb,
            'kapitel' => $kapitel,
            'laeuftMap' => $laeuftMap,
            'servierformen' => FoodAlchemistServierform::where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('label')->get(['id', 'label']),
            'einsatzmomente' => FoodAlchemistEinsatzmoment::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'zielgruppenVokab' => FoodAlchemistTargetGroup::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'niveauLabels' => TeamSettingsService::NIVEAU_LABEL,
            'pricingModes' => FoodAlchemistFoodbookKapitel::PRICING_MODES,
        ]);
    }

    private function kapitel(?int $id): ?FoodAlchemistFoodbookKapitel
    {
        if ($id === null) {
            return null;
        }

        return FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->foodbookId)->find($id);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
