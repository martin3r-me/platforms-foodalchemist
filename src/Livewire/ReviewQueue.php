<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\QualityRunService;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\TerminologyService;

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

    /** Cockpit-Tabs — Ansicht liegt in der URL (V-17/Kontext-Erhalt). */
    public const TABS = ['ueberblick', 'signale', 'ki', 'matches', 'pflege'];

    #[Url(as: 'tab')]
    public string $tab = 'signale';

    /** KI-Steuer-Rahmen: welches Signal hat sein „so würde die KI das angehen"-Panel offen (nur UI). */
    public ?int $kiPanelId = null;

    /** KI-Assistenz-Entwurf (transient): ['signal_id','draft','confidence'] fürs offene Panel. */
    public ?array $kiDraft = null;

    /**
     * Wie viele fällige Rezepte darf der KI-Befunde-Lauf höchstens prüfen?
     *
     * Steht sichtbar am Knopf, weil jeder Schritt Provider-Geld kostet — ein Limit, das
     * nur im Code steht, ist für den Klickenden kein Limit. Der Service deckelt zusätzlich
     * hart auf {@see RecipeFindingsBatchService::MAX_LIMIT}, damit ein manipulierter
     * Livewire-Payload keine Volllast auslösen kann.
     */
    public int $befundeLimit = RecipeFindingsBatchService::DEFAULT_LIMIT;

    #[Url(as: 'sig_status')]
    public string $signalStatus = 'offen';

    #[Url(as: 'sig_typ')]
    public string $signalTyp = '';

    // E7-c (#507): Terminologie-Lernschleife — der Kurator lehrt beim Review neue
    // Aliase/Anti-Marker, die SOFORT ins Matching einfließen (globaler Master, kein Deploy).
    public string $termAlias = '';

    public string $termTrigger = '';

    public string $termForbid = '';

    public string $termUnless = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'signale';
        }
    }

    /** Cockpit-Tab wechseln (Muster Concepter\Browser) — Panel-State + Pagination zurücksetzen. */
    public function setTab(string $t): void
    {
        if (! in_array($t, self::TABS, true) || $t === $this->tab) {
            return;
        }
        $this->tab = $t;
        $this->kiPanelId = null;
        $this->kiDraft = null;
        $this->resetPage();
    }

    /** KI-Steuer-Rahmen auf-/zuklappen (Panel mit Plan + „Ausführen"). */
    public function toggleKiPanel(int $id): void
    {
        $this->kiPanelId = $this->kiPanelId === $id ? null : $id;
        $this->kiDraft = null;   // frisches Panel, kein alter Entwurf
    }

    /**
     * „KI erledigen lassen" ausführen: deterministisch → Hintergrund-Job über den vollen
     * betroffenen Satz (Signal schließt/aktualisiert danach); assist → ein propose()-Call
     * → Entwurf transient im Panel. Plan-Wahl metrik-fein via SignalCockpit.
     */
    public function kiFixAusfuehren(int $signalId): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $sig = FoodAlchemistSignal::visibleToTeam($team)->find($signalId);
        if ($sig === null) {
            $this->fehler = 'Signal nicht gefunden.';

            return;
        }
        // 22·H4b/V-033: nur die zwei ausführbaren Arten — ein `navigate`-Plan hat keinen
        // Executor und käme sonst im else-Zweig als „kein Assistenz-Schritt" heraus.
        $plan = \Platform\FoodAlchemist\Support\SignalCockpit::kiPlan($sig);
        if ($plan === null) {
            $this->fehler = 'Für dieses Signal gibt es keinen KI-Schritt.';

            return;
        }

        try {
            if ($plan['kind'] === 'deterministic') {
                \Platform\FoodAlchemist\Jobs\SignalFixJob::dispatch((int) $sig->id, (int) $team->id);
                $this->kiDraft = null;
                $this->meldung = 'KI-Fix gestartet — die betroffenen Objekte werden behoben; erledigte Signale verschwinden aus „offen".';
            } else {
                $res = app(\Platform\FoodAlchemist\Services\SignalFixService::class)->assist($team, $sig);
                $this->kiDraft = ['signal_id' => (int) $sig->id, 'draft' => (string) $res['draft'], 'confidence' => (float) $res['confidence']];
                $this->meldung = 'KI-Entwurf erzeugt.';
            }
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function matchUebernehmen(int $proposalId): void
    {
        $this->aktion(fn ($team) => app(MatchService::class)->uebernehmeVorschlag($team, $proposalId), 'Match übernommen — LA ist verknüpft.');
    }

    public function matchVerwerfen(int $proposalId): void
    {
        $this->aktion(fn ($team) => app(MatchService::class)->verwerfeVorschlag($team, $proposalId), 'Match verworfen.');
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

    // Spec 21 · P: nach jeder Lifecycle-Änderung das Signal-Panel anstoßen — seine
    // objekt-zentrische Liste darf kein soeben geschlossenes Signal mehr zeigen.
    public function signalErledigt(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->abschliessen($team, $id), 'Signal erledigt.');
        $this->dispatch('signal-geaendert');
    }

    public function signalIgnorieren(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->ignorieren($team, $id), 'Signal ignoriert.');
        $this->dispatch('signal-geaendert');
    }

    public function signalWiederOeffnen(int $id): void
    {
        $this->aktion(fn ($team) => app(SignalService::class)->wiederOeffnen($team, $id), 'Signal wieder geöffnet.');
        $this->dispatch('signal-geaendert');
    }

    // ── E7-c: Terminologie lernen (Lernschleife-Senke) ─────────────────────

    /** Alias-Gruppe aus kommagetrennten Phrasen anlegen (≥2). */
    public function terminologieAlias(): void
    {
        $members = array_map('trim', explode(',', $this->termAlias));
        $this->aktion(function () use ($members) {
            $row = app(TerminologyService::class)->createAlias($members, null, 'reviewqueue');
            $this->termAlias = '';

            return $row;
        }, 'Alias gelernt — wirkt sofort im Matching.');
    }

    /** Anti-Marker anlegen: bei "trigger" den Kandidaten "forbid" sperren (außer "unless"). */
    public function terminologieAntiMarker(): void
    {
        $this->aktion(function () {
            $row = app(TerminologyService::class)->createAntiMarker($this->termTrigger, $this->termForbid, $this->termUnless, null, 'reviewqueue');
            $this->termTrigger = $this->termForbid = $this->termUnless = '';

            return $row;
        }, 'Anti-Marker gelernt — Verwechslung ist gesperrt.');
    }

    /**
     * „Ampel neu messen" — Detektor-Lauf anstoßen (sonst via Scheduler/Command).
     *
     * ASYNC seit 2026-07-28. Vorher rief das hier `SignalDetektorService::laufen()`
     * **synchron im Livewire-Request**: 11 Detektoren, Voll-Messung der Kaskade, Snapshot
     * und Drift — auf demo über 7.942 Artikel und 2.297 Rezepte. Das war kein Request,
     * das war ein Batch, und er wäre ins Timeout gelaufen. Jetzt gibt es eine `run_id`
     * und damit eine Quittung (`runs.GET`), statt eines Klicks, dessen Ausgang niemand
     * nachlesen kann.
     */
    public function detektorLaufen(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        ['run_id' => $runId, 'bereits_laufend' => $schonDa] = app(QualityRunService::class)
            ->starteAmpelLauf($team, Auth::id());

        $this->meldung = $schonDa
            ? "Es läuft schon eine Messung (Lauf {$runId}) — kein zweiter Lauf gestartet."
            : "Messung gestartet (Lauf {$runId}) — die Signale erscheinen, sobald sie durch ist.";
    }

    /**
     * „KI-Befunde sammeln" — der Copilot-Batch über die fällige Arbeitsmenge.
     *
     * Bewusst ein **eigener** Knopf und nicht in die Ampel-Messung gefaltet: dieser Lauf
     * ruft das Modell pro Rezept und kostet Provider-Geld. Wer die Ampel neu messen will,
     * soll damit keine Rechnung auslösen. Das Limit steht darum am Knopf und ist die
     * Egress-Bremse (V-047), nicht eine Bequemlichkeit.
     */
    public function befundeLaufen(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        ['run_id' => $runId, 'limit' => $limit] = app(QualityRunService::class)
            ->starteBefundeLauf($team, $this->befundeLimit, userId: Auth::id());

        $this->meldung = "KI-Befunde gestartet (Lauf {$runId}) — höchstens {$limit} fällige Rezepte, "
            . 'die Befunde landen im Copilot-Panel des jeweiligen Rezepts.';
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

        // Spec 21 · E2: Zustands-Sicht (Bestand + Delta + Policy) über der Einzelliste.
        // Ein Aufruf, zwei Verwendungen — die Zeilen selbst und die Typen, die dadurch
        // aus der Einzelliste fallen (kein zweiter Query-Durchlauf).
        $zustand = app(\Platform\FoodAlchemist\Services\SignalPolicyService::class)->zustand($team);
        $aggregierteTypen = array_values(array_map(
            fn (array $z) => $z['type'],
            array_filter($zustand, fn (array $z) => $z['aggregiert'])
        ));

        // Überblick-Kacheln: offene Signale nach Schweregrad (read-only, Präsentation).
        $severitySplit = FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity')->all();
        // „Kritischste Signale" — Severity-Rang zuerst (SQLite+MySQL-sicher, kein FIELD()).
        $kritischste = FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->orderByRaw("CASE severity WHEN 'kritisch' THEN 0 WHEN 'warnung' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->limit(6)->get();

        return view('foodalchemist::livewire.review-queue', [
            'severitySplit' => $severitySplit,
            'kritischste' => $kritischste,
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
            'signalZustand' => $zustand,
            'signale' => $signalSvc->paginate([
                'status' => $this->signalStatus,
                'type' => $this->signalTyp,
                // Spec 21 · E2: Typen mit Rausch-Guard fallen in ihre Zustands-Zeile zusammen —
                // aber nur ungefiltert; ein Klick auf die Zeile (setSignalTyp) klappt sie auf.
                'exclude_types' => $aggregierteTypen,
            ], $team, 30),
            'signalOffen' => $signalSvc->offeneCount($team),
            'signalNachTyp' => $signalSvc->offeneNachTyp($team),
            'signalTypWerte' => $signalSvc->typWerte(),
            'signalStatusWerte' => $signalSvc->statusWerte(),
        ])->layout('platform::layouts.app');
    }
}
