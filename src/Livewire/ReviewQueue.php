<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
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

    /** „Reinschauen": welches Signal hat seine Liste betroffener Objekte offen (read-only). */
    public ?int $detailPanelId = null;

    /** KI-Assistenz-Entwurf (transient): ['signal_id','draft','confidence'] fürs offene Panel. */
    public ?array $kiDraft = null;

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
        $this->detailPanelId = null;
        $this->kiDraft = null;
        $this->resetPage();
    }

    /** KI-Steuer-Rahmen auf-/zuklappen (Panel mit Plan + „Ausführen"). */
    public function toggleKiPanel(int $id): void
    {
        $this->kiPanelId = $this->kiPanelId === $id ? null : $id;
        $this->detailPanelId = null;
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
        $plan = \Platform\FoodAlchemist\Support\SignalCockpit::planFor($sig);
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

    /** „Reinschauen": Liste der betroffenen Objekte auf-/zuklappen (read-only). */
    public function toggleDetail(int $id): void
    {
        $this->detailPanelId = $this->detailPanelId === $id ? null : $id;
        $this->kiPanelId = null;
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

    /**
     * Löst die betroffenen Objekte hinter einem Signal auf („reinschauen"). Read-only.
     * DataQuality-Signale → Live-Query je Metrik-Key; Detektor-Signale → payload.beispiele
     * bzw. ref_type/ref_id. Nur fürs offene Detail-Panel aufgerufen.
     *
     * @return array{items:list<array<string,mixed>>,total:int,gezeigt:int}|null
     */
    private function betroffeneFuer(Team $team, int $signalId): ?array
    {
        $sig = FoodAlchemistSignal::visibleToTeam($team)->find($signalId);
        if ($sig === null) {
            return null;
        }
        $pl = is_array($sig->payload) ? $sig->payload : [];

        // DataQuality-Signale: Metrik-Key → exakt dieselbe Query wie der Zähler, als Liste.
        if ($sig->source === 'data-quality' && ! empty($pl['metrik'])) {
            $items = app(DataQualityService::class)->betroffene($team, (string) $pl['metrik']);

            return ['items' => $items, 'total' => (int) ($pl['anzahl'] ?? count($items)), 'gezeigt' => count($items)];
        }

        // Detektor-Signale mit Beispielen im Payload (Struktur variiert je Detektor — best effort).
        if (! empty($pl['beispiele']) && is_array($pl['beispiele'])) {
            $items = [];
            foreach (array_slice($pl['beispiele'], 0, 50) as $b) {
                if (is_array($b)) {
                    $id = (int) ($b['recipe_id'] ?? $b['id'] ?? 0);
                    $items[] = [
                        'kind' => isset($b['recipe_id']) ? 'recipe' : ($b['kind'] ?? 'text'),
                        'id' => $id,
                        'name' => (string) ($b['name'] ?? $b['label'] ?? ('#' . $id)),
                        'is_sales_recipe' => (bool) ($b['is_sales_recipe'] ?? true),
                    ];
                } else {
                    $items[] = ['kind' => 'text', 'id' => 0, 'name' => (string) $b, 'is_sales_recipe' => false];
                }
            }

            return ['items' => $items, 'total' => (int) ($pl['anzahl'] ?? count($pl['beispiele'])), 'gezeigt' => count($items)];
        }

        // Einzelobjekt-Signal (ref_type/ref_id).
        if ($sig->ref_type === 'recipe' && $sig->ref_id) {
            $r = FoodAlchemistRecipe::visibleToTeam($team)->find($sig->ref_id);
            if ($r !== null) {
                return ['items' => [['kind' => 'recipe', 'id' => (int) $r->id, 'name' => (string) $r->name, 'is_sales_recipe' => (bool) $r->is_sales_recipe]], 'total' => 1, 'gezeigt' => 1];
            }
        }
        if ($sig->ref_type === 'gp' && $sig->ref_id) {
            $g = FoodAlchemistGp::visibleToTeam($team)->find($sig->ref_id);
            if ($g !== null) {
                return ['items' => [['kind' => 'gp', 'id' => (int) $g->id, 'name' => (string) $g->name, 'is_sales_recipe' => false]], 'total' => 1, 'gezeigt' => 1];
            }
        }

        return ['items' => [], 'total' => (int) ($pl['anzahl'] ?? 0), 'gezeigt' => 0];
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

        // Überblick-Kacheln: offene Signale nach Schweregrad (read-only, Präsentation).
        $severitySplit = FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity')->all();
        // „Kritischste Signale" — Severity-Rang zuerst (SQLite+MySQL-sicher, kein FIELD()).
        $kritischste = FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->orderByRaw("CASE severity WHEN 'kritisch' THEN 0 WHEN 'warnung' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->limit(6)->get();

        // „Reinschauen": betroffene Objekte NUR für das gerade geöffnete Signal auflösen.
        $detailData = $this->detailPanelId !== null ? $this->betroffeneFuer($team, $this->detailPanelId) : null;

        return view('foodalchemist::livewire.review-queue', [
            'severitySplit' => $severitySplit,
            'kritischste' => $kritischste,
            'detailData' => $detailData,
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
