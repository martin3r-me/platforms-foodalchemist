<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * M9-03 / V-10: Review-Queue — EINE «Zu prüfen»-Seite für alles, was eine
 * menschliche Entscheidung braucht: offene LA→GP-Match-Vorschläge (M3-11),
 * offene KI-Vorschläge aus Bulk-Läufen (M7-06), VK ohne Speisen-Klasse (V-22),
 * Rezepte im Review-Status, Rezepte mit ungemappten Zutaten (F7.1).
 * Aktionen laufen über die bestehenden Services (eine Regel-Stelle).
 */
class ReviewQueue extends Component
{
    use WithPagination;

    public ?string $meldung = null;

    public ?string $fehler = null;

    #[Url(as: 'sig_status')]
    public string $signalStatus = 'offen';

    #[Url(as: 'sig_typ')]
    public string $signalTyp = '';

    public function matchUebernehmen(int $proposalId): void
    {
        $this->aktion(fn ($team) => app(MatchService::class)->uebernehmeVorschlag($team, $proposalId), 'Match übernommen — LA ist verknüpft.');
    }

    public function matchVerwerfen(int $proposalId): void
    {
        $this->aktion(fn () => app(MatchService::class)->verwerfeVorschlag($proposalId), 'Match verworfen.');
    }

    public function bulkUebernehmen(int $proposalId): void
    {
        $this->aktion(fn ($team) => app(BulkEnrichService::class)->uebernehmen($team, $proposalId), 'KI-Vorschlag übernommen.');
    }

    public function bulkVerwerfen(int $proposalId): void
    {
        $this->aktion(fn ($team) => app(BulkEnrichService::class)->verwerfen($team, $proposalId), 'KI-Vorschlag verworfen.');
    }

    // ── Klasse B: Signale (#378) ───────────────────────────────────────────

    public function signalErledigt(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->abschliessen($team, $id), 'Signal erledigt.');
    }

    public function signalIgnorieren(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->ignorieren($team, $id), 'Signal ignoriert.');
    }

    public function signalWiederOeffnen(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->wiederOeffnen($team, $id), 'Signal wieder geöffnet.');
    }

    /** Detektor manuell anstoßen (sonst via Scheduler/Command). */
    public function detektorLaufen(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $n = app(SignalDetektorService::class)->laufen($team);
        $this->meldung = "Detektor gelaufen — {$n} Signal(e) erzeugt/aktualisiert.";
    }

    public function setSignalStatus(string $s): void
    {
        $this->signalStatus = $s;
        $this->resetPage();
    }

    public function setSignalTyp(string $t): void
    {
        $this->signalTyp = $this->signalTyp === $t ? '' : $t;
        $this->resetPage();
    }

    private function aktion(\Closure $tu, string $erfolg): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $tu($team);
            $this->meldung = $erfolg;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $kette = FoodAlchemistGp::teamAncestryIds($team);

        // #393-Rest: Scope = AKTUELLES Team (Entscheid Dominique 06-19) — vorher Cross-Team-Leak
        $matchOffen = DB::table('foodalchemist_match_proposals AS p')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'p.supplier_item_id')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 'p.gp_id')
            ->where('p.team_id', $team->id)
            ->where('p.status', 'offen')->whereNull('p.deleted_at');

        $bulkOffen = DB::table('foodalchemist_bulk_proposals AS b')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'b.recipe_id')
            ->where('b.status', 'offen')->whereIn('b.team_id', $kette);

        $rezept = fn () => DB::table('foodalchemist_recipes')->whereIn('team_id', $kette)->whereNull('deleted_at');

        $signalSvc = app(SignalService::class);

        return view('foodalchemist::livewire.review-queue', [
            'matchZahl' => (clone $matchOffen)->count(),
            'matches' => (clone $matchOffen)->orderByDesc('p.score')->limit(50)
                ->get(['p.id', 'p.score', 'p.methode', 'i.designation AS la_name', 'g.name AS gp_name']),
            'bulkZahl' => (clone $bulkOffen)->count(),
            'bulks' => (clone $bulkOffen)->orderByDesc('b.id')->limit(50)
                ->get(['b.id', 'b.field', 'b.value', 'b.confidence', 'r.name AS rezept_name', 'r.id AS rezept_id', 'r.is_sales_recipe']),
            'vkOhneKlasse' => (clone $rezept())->where('is_sales_recipe', true)->whereNull('dish_class_id')
                ->orderBy('name')->limit(50)->get(['id', 'name']),
            'imReview' => (clone $rezept())->where('status', 'review')->orderBy('name')->limit(50)
                ->get(['id', 'name', 'is_sales_recipe']),
            'imReviewZahl' => (clone $rezept())->where('status', 'review')->count(),
            'ungemappt' => (clone $rezept())->where('n_ingredients_unmapped', '>', 0)->orderByDesc('n_ingredients_unmapped')
                ->limit(50)->get(['id', 'name', 'is_sales_recipe', 'n_ingredients_unmapped']),
            'ungemapptZahl' => (clone $rezept())->where('n_ingredients_unmapped', '>', 0)->count(),
            // Klasse B: Signale (#378)
            'signale' => $signalSvc->paginate(['status' => $this->signalStatus, 'type' => $this->signalTyp], $team, 30),
            'signalOffen' => $signalSvc->offeneCount($team),
            'signalNachTyp' => $signalSvc->offeneNachTyp($team),
            'signalTypWerte' => $signalSvc->typWerte(),
            'signalStatusWerte' => $signalSvc->statusWerte(),
        ])->layout('platform::layouts.app');
    }
}
