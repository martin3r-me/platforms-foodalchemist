<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Enums\SignalTyp;
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
    public const TABS = ['ueberblick', 'signale', 'vorschlaege', 'pflege'];

    /**
     * Alt-Tab-Schlüssel → neuer Tab (Kompat): die früher getrennten Tabs `ki`
     * (KI-Vorschläge) und `matches` (LA→GP + Terminologie) sind zu `vorschlaege`
     * verschmolzen. Gespeicherte/verlinkte URLs `?tab=ki`/`?tab=matches` landen so
     * weiter auf einer sinnvollen Ansicht statt im Start-Tab-Fallback.
     */
    private const TAB_ALIASES = ['ki' => 'vorschlaege', 'matches' => 'vorschlaege'];

    /**
     * fa-pass 2026-10-05 · reine Darstellung: die Signal-Typen nach Arbeitsbereich der Küche.
     * Vorher standen 35+ Typen als flache Filter-Wand und die Einzelliste lief nach Datum —
     * jetzt gruppiert die Seite nach Bereich, Kritisch zuerst. Die Zuordnung ändert keine
     * Detektor-, Filter- oder Policy-Logik; sie sortiert nur die Anzeige.
     * Eigentlich gehört sie an das Enum (SignalTyp::bereich()) — bis dahin lebt sie hier.
     *
     * @var array<string,array{label:string,icon:string,satz:string}>
     */
    public const BEREICHE = [
        'preise' => ['label' => 'Preise und Einkauf', 'icon' => 'heroicon-o-currency-euro', 'satz' => 'Einkaufspreise, Wareneinsatz, Marge, Verkaufspreise und Lieferantenverträge.'],
        'deklaration' => ['label' => 'Deklaration', 'icon' => 'heroicon-o-shield-check', 'satz' => 'Allergene und Nährwerte, auf die sich der Gast verlässt.'],
        'rezepte' => ['label' => 'Rezepte und Gerichte', 'icon' => 'heroicon-o-book-open', 'satz' => 'Mengen, Zubereitung, Zuordnung der Zutaten und Rückmeldungen aus der Küche.'],
        'konzepte' => ['label' => 'Konzepte und Foodbooks', 'icon' => 'heroicon-o-squares-2x2', 'satz' => 'Menüs, Angebote und Kundendokumente, die in Gebrauch sind.'],
        'datenqualitaet' => ['label' => 'Datenqualität', 'icon' => 'heroicon-o-cube', 'satz' => 'Grundprodukte, Lieferantenartikel und ihre Verknüpfungen.'],
        'system' => ['label' => 'Wissen und System', 'icon' => 'heroicon-o-cog-6-tooth', 'satz' => 'Wissensbasis und Einstellungen, mit denen die KI arbeitet.'],
    ];

    /** Bereich eines Signal-Typs (nur Anzeige). Die Drift ist kein Bereich, sondern „Entwicklung". */
    public static function bereichFuer(SignalTyp $typ): string
    {
        return match ($typ) {
            SignalTyp::PreisAnomalie, SignalTyp::PreisSprungMargeImpact, SignalTyp::VeraltetePreise,
            SignalTyp::MargeUnterZiel, SignalTyp::WareneinsatzUeberZiel, SignalTyp::WareneinsatzIstAbweichung,
            SignalTyp::EkKetteUnvollstaendig, SignalTyp::VkAnpassungEmpfohlen, SignalTyp::VertragsfristFaellig,
            SignalTyp::SortimentsLuecke => 'preise',
            SignalTyp::RezeptAllergenUnbelastbar, SignalTyp::NaehrwertPlausi => 'deklaration',
            SignalTyp::DatenqualitaetGpLa, SignalTyp::KonformitaetGp, SignalTyp::KonformitaetLa,
            SignalTyp::AnkerFehlt => 'datenqualitaet',
            SignalTyp::ServierformUnbestimmt => 'rezepte',
            SignalTyp::TrendKonzeptVorschlag => 'konzepte',
            SignalTyp::WiderspruchWissenGraph, SignalTyp::SteuerdatenDrift => 'system',
            SignalTyp::QualitaetDrift => 'entwicklung',
            default => match (true) {
                $typ->istRezeptQualitaet() => 'rezepte',
                $typ->istKonzeptQualitaet(), $typ->istFoodbookQualitaet() => 'konzepte',
                default => 'system',
            },
        };
    }

    public static function bereichLabel(SignalTyp $typ): string
    {
        $b = self::bereichFuer($typ);

        return $b === 'entwicklung' ? 'Entwicklung' : self::BEREICHE[$b]['label'];
    }

    /**
     * Ein Drift-Signal zeigt auf den Typ, der sich verschlechtert hat (payload.drift_metric
     * ist bei Signal-Reihen der Typ-Schlüssel). Für Ampel-Metriken ohne Typ-Schlüssel gibt
     * es keinen eindeutigen Bereich — dann null, das Signal steht nur unter „Entwicklung".
     */
    public static function driftBereich(FoodAlchemistSignal $sig): ?string
    {
        $pl = is_array($sig->payload) ? $sig->payload : [];
        $typ = isset($pl['drift_metric']) ? SignalTyp::tryFrom((string) $pl['drift_metric']) : null;

        return $typ !== null && $typ !== SignalTyp::QualitaetDrift ? self::bereichFuer($typ) : null;
    }

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
        $this->tab = self::TAB_ALIASES[$this->tab] ?? $this->tab;
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

    /** Ebene 2: Betrieb-Wechsel im Sidebar → Signale-Werkbank neu rendern (liest die Lane im render()). */
    #[On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
        // no-op: löst nur das Re-Rendering aus; render() liest die aktive Brille frisch.
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
        // Ebene 2: die Signale-Werkbank folgt der Betriebsbrille (Betriebs-Lane + Team-Core-Lane).
        $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->current($team);

        // Spec 21 · E2: Zustands-Sicht (Bestand + Delta + Policy) über der Einzelliste.
        // Ein Aufruf, zwei Verwendungen — die Zeilen selbst und die Typen, die dadurch
        // aus der Einzelliste fallen (kein zweiter Query-Durchlauf).
        $zustand = app(\Platform\FoodAlchemist\Services\SignalPolicyService::class)->zustand($team);
        $aggregierteTypen = array_values(array_map(
            fn (array $z) => $z['type'],
            array_filter($zustand, fn (array $z) => $z['aggregiert'])
        ));

        // Überblick-Kacheln: offene Signale nach Schweregrad (read-only, Präsentation) — Lane-gefiltert.
        $severitySplit = FoodAlchemistSignal::visibleToTeam($team)->offen()->lane($outlet)
            ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity')->all();
        // „Kritischste Signale" — Severity-Rang zuerst (SQLite+MySQL-sicher, kein FIELD()).
        $kritischste = FoodAlchemistSignal::visibleToTeam($team)->offen()->lane($outlet)
            ->orderByRaw("CASE severity WHEN 'kritisch' THEN 0 WHEN 'warnung' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->limit(6)->get();

        // ── fa-pass · reine Darstellung: Bereiche, Kritisch zuerst, Entwicklung unten ──
        $driftTyp = SignalTyp::QualitaetDrift->value;
        $driftGedaempft = in_array($driftTyp, $aggregierteTypen, true);
        $ungefiltert = $this->signalTyp === '';
        $driftListe = fn (string $status) => FoodAlchemistSignal::visibleToTeam($team)->lane($outlet)
            ->where('type', $driftTyp)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(50)->get();
        $driftOffen = $driftListe('offen');

        $signale = $signalSvc->paginate([
            'status' => $this->signalStatus,
            'type' => $this->signalTyp,
            // Spec 21 · E2: Typen mit Rausch-Guard fallen in ihre Zustands-Zeile zusammen —
            // aber nur ungefiltert; ein Klick auf die Zeile (setSignalTyp) klappt sie auf.
            // fa-pass: die Drift steht ungefiltert als eigener Abschnitt „Entwicklung" unten
            // statt als Einzelmeldung zwischen den Befunden (Typ-Filter holt sie zurück).
            'exclude_types' => array_values(array_unique(array_merge($aggregierteTypen, [$driftTyp]))),
        ], $team, 50, $outlet, nurLane: true);

        // Kritisch über alle Seiten: steht oben, egal auf welcher Seite der Liste es läge.
        $kritischOffen = $this->signalStatus === 'offen'
            ? FoodAlchemistSignal::visibleToTeam($team)->offen()->lane($outlet)
                ->where('severity', 'kritisch')
                ->when($ungefiltert, fn ($q) => $q->whereNotIn('type', array_merge($aggregierteTypen, [$driftTyp])))
                ->when(! $ungefiltert, fn ($q) => $q->where('type', $this->signalTyp))
                ->orderByDesc('created_at')->limit(20)->get()
            : collect();
        $kritischIds = $kritischOffen->pluck('id')->all();

        $entwicklung = $ungefiltert
            ? ($driftGedaempft ? collect() : ($this->signalStatus === 'offen' ? $driftOffen : $driftListe($this->signalStatus)))
            : collect();

        $sortiert = collect($signale->items())
            ->sortBy([fn ($a, $b) => $a->severity->rang() <=> $b->severity->rang(), fn ($a, $b) => $b->created_at <=> $a->created_at])
            ->values();

        $driftJeBereich = [];
        foreach ($driftOffen as $d) {
            $db = self::driftBereich($d);
            if ($db !== null) {
                $driftJeBereich[$db] = ($driftJeBereich[$db] ?? 0) + 1;
            }
        }

        $sektionen = [];
        if ($kritischOffen->isNotEmpty()) {
            $sektionen[] = ['key' => 'kritisch', 'label' => 'Zuerst erledigen', 'icon' => 'heroicon-o-exclamation-circle',
                'satz' => 'Kritische Befunde aus allen Bereichen. Diese zuerst ansehen.', 'drift' => 0, 'items' => $kritischOffen->values()];
        }
        foreach (self::BEREICHE as $key => $b) {
            $items = $sortiert->filter(fn ($s) => self::bereichFuer($s->type) === $key && ! in_array($s->id, $kritischIds, true))->values();
            if ($items->isNotEmpty()) {
                $sektionen[] = ['key' => $key, 'label' => $b['label'], 'icon' => $b['icon'], 'satz' => $b['satz'],
                    'drift' => $driftJeBereich[$key] ?? 0, 'items' => $items];
            }
        }
        // Kritische Drift-Befunde stehen schon unter „Zuerst erledigen" (Typ-Filter auf die Drift) — nicht doppelt.
        $entwicklungItems = $entwicklung->merge($sortiert->filter(fn ($s) => $s->type === SignalTyp::QualitaetDrift))
            ->unique('id')->reject(fn ($s) => in_array($s->id, $kritischIds, true))->values();
        if ($entwicklungItems->isNotEmpty()) {
            $sektionen[] = ['key' => 'entwicklung', 'label' => 'Entwicklung', 'icon' => 'heroicon-o-arrow-trending-down',
                'satz' => 'Was sich seit der letzten Prüfung verschlechtert hat. Die Ursache steht am jeweiligen Befund oben.',
                'drift' => 0, 'items' => $entwicklungItems];
        }

        // Kennzahl je Bereich (offen · davon kritisch · Verschlechterungen)
        $bereichUebersicht = [];
        $schwereNachTyp = FoodAlchemistSignal::visibleToTeam($team)->offen()->lane($outlet)
            ->selectRaw('type, severity, COUNT(*) as c')->groupBy('type', 'severity')->toBase()->get();
        foreach ($schwereNachTyp as $zeile) {
            $typ = SignalTyp::tryFrom((string) $zeile->type);
            if ($typ === null || $typ === SignalTyp::QualitaetDrift) {
                continue;
            }
            $bk = self::bereichFuer($typ);
            $bereichUebersicht[$bk] ??= ['offen' => 0, 'kritisch' => 0];
            $bereichUebersicht[$bk]['offen'] += (int) $zeile->c;
            if ((string) $zeile->severity === 'kritisch') {
                $bereichUebersicht[$bk]['kritisch'] += (int) $zeile->c;
            }
        }

        // Typ-Auswahl gruppiert nach Bereich (statt Chip-Wand) — nur Typen mit Bestand + der gewählte.
        $nachTyp = $signalSvc->offeneNachTyp($team, $outlet, nurLane: true);
        $typGruppen = [];
        foreach (SignalTyp::cases() as $t) {
            $n = (int) ($nachTyp[$t->value] ?? 0);
            if ($n === 0 && $this->signalTyp !== $t->value) {
                continue;
            }
            $gk = self::bereichFuer($t);
            $typGruppen[$gk === 'entwicklung' ? 'Entwicklung' : self::BEREICHE[$gk]['label']][$t->value] = $t->label() . ($n > 0 ? ' (' . $n . ')' : '');
        }

        return view('foodalchemist::livewire.review-queue', [
            'signalSektionen' => $sektionen,
            'bereichUebersicht' => $bereichUebersicht,
            'driftJeBereich' => $driftJeBereich,
            'typGruppen' => $typGruppen,
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
            'signale' => $signale,
            'signalOffen' => $signalSvc->offeneCount($team, $outlet, nurLane: true),
            'signalNachTyp' => $nachTyp,
            'aktiverBetrieb' => $outlet?->name,
            'signalTypWerte' => $signalSvc->typWerte(),
            'signalStatusWerte' => $signalSvc->statusWerte(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
