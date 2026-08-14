<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Planungs-/Kreativ-Cockpit (Doppel-Diamant, Spec 08). Haus-Layout: links Kategorie→Klasse +
 * Session-Liste, Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor mit
 * Tabs Analyse · Skizzen · Planung + Go-Leiste. Read-mostly Container VOR dem Grounding — erst
 * „Go" erzeugt Basisrezept/Gericht/Concept (Draft), Lineage zurück in die Session.
 */
class Index extends Component
{
    public ?string $fehler = null;

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    /** Neue-Planung-Formular (Mitte-Dashboard). */
    public string $neuTitel = '';

    /** Editor-Felder der aktiven Session. */
    public array $form = ['title' => '', 'brief' => '', 'analysis' => '', 'creative_mode' => 'voll_kreativ'];

    /** Skizzen-Eingaben. */
    public string $ideeTitel = '';

    public string $paketName = '';

    /** Composer-Tab: gewählte Anker (Einträge {id, slug, label}), Cap = INNER_ANKER_MAX (12). */
    public array $composerAnker = [];

    /** Composer-Suchfeld (live). */
    public string $composerTerm = '';

    /** Composer-Picker: Kategorie-Filter (leer = alle). */
    public string $composerCategory = '';

    /** Composer-Fokus: aktiv fokussierter Anker (Klick im Netz) — Netz dimmt auf ihn, Picker rankt relativ zu ihm. */
    public ?int $composerFocus = null;

    // ── Leitstelle: Richtungs-Regler (Leitplanken) für den Go ──────────────
    /**
     * Die Regler des Planung-Go — Union aus Basisrezept- + Gericht-Achsen (die VK-
     * eigenen occasion/serviceform/kompositions_stil/reglerZielVk greifen NUR bei
     * scope=gericht). Spiegelt {@see GeneratorModal::$parameter}/{@see VkGeneratorModal::$parameter},
     * damit die Leitstelle dieselbe Steuerung bietet — inline, ohne Modal. Am Go
     * werden die gesetzten Werte in `generation_params` der Session persistiert, sodass
     * der Kaskaden-Fan-out sie erbt (siehe PlanningCascadeService).
     */
    public array $regler = [
        'convenience' => '', 'frische' => 'frisch', 'bestand' => 'hybrid',
        'bio_praeferenz' => 'konventionell', 'level' => '', 'sektor' => '',
        'diaet_hart' => [], 'aroma' => '',
        'occasion' => '', 'serviceform' => '', 'kompositions_stil' => '',
    ];

    /** 06·H4: opt-in Favoriten-Modus (Default aus → keine Versteifung). */
    public bool $reglerFavoriten = false;

    /** 06·H4b: Favoriten-Block auf Convenience-getaggte verengen. */
    public bool $reglerFavoritenConvenienceOnly = false;

    /** Ziel-VK (nur scope=gericht): Freitext „8,50", normalisiert via zielVkEur(). */
    public string $reglerZielVk = '';

    /**
     * Recipe-first (Default AUS): die Vollanreicherung ist ein bewusster Schritt NACH
     * dem Review, nicht automatisch mit dem Go. Bindet an den oneshot-toggle, fließt als
     * `voll_anreichern` in die Kaskade (starteKaskade default true → hier explizit setzen).
     */
    public bool $vollAnreichern = false;

    /** #1b Grounding-Preview: welches Wissen/Pairing/Template ein Basisrezept-Lauf ziehen würde (on-demand, ohne Generierung). */
    public ?array $wissenVorschau = null;

    /**
     * A: Welche Draft-Steps ihren Inline-Zutaten-Editor offen haben (step_id-Liste).
     * Kontrolliertes On-Demand-Mounten statt N eingebetteter Editoren beim Fan-out —
     * erst beim Aufklappen wird der IngredientEditor für diesen Draft gerendert.
     */
    public array $zutatenOffen = [];

    /**
     * Pill-Gruppen fürs Cockpit-View (Parität zu GeneratorModal::RICHTUNGEN). Inline
     * gehalten statt aus dem Modal referenziert — die Leitstelle ist der neue Ort der
     * Steuerung; die Modal-Knöpfe der Browser-Seiten entfallen.
     */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Convenience (Eigenleistung)', 'optionen' => ['' => '(egal)', 'from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Keine Vorgabe', 'from_scratch' => 'alles selbst — Pool dreht auf Roh/Sub-Rezepte', 'teil_convenience' => 'Halbfabrikate erlaubt', 'voll_convenience' => 'Fertigprodukte bevorzugt']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Keine Vorgabe']],
        ['field' => 'bestand', 'label' => 'Bestand-Nutzung', 'optionen' => ['hybrid' => 'Hybrid', 'nur_bestand' => 'Nur Bestand', 'komplett_neu' => 'Komplett neu'], 'hint' => ['hybrid' => 'Default — Bestand zuerst reusen, Neues nur für echte Lücken', 'nur_bestand' => 'ausschließlich vorhandene GPs/Rezepte', 'komplett_neu' => 'Bestand ignorieren']],
        ['field' => 'bio_praeferenz', 'label' => 'Bio-Präferenz', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Egal'], 'hint' => ['konventionell' => 'Standard — kein Bio erzwungen (Default)', 'bio' => 'Bio bevorzugt (nur auf Ansage)', 'egal' => 'keine Präferenz']],
        ['field' => 'frische', 'label' => 'Frische-Hook', 'optionen' => ['frisch' => 'Frisch', 'tk' => 'Alles aus TK', 'konserve' => 'Konserve/haltbar'], 'hint' => ['frisch' => 'fresh_first (Default)']],
    ];

    public ?string $meldung = null;

    /** Aktiver Kaskaden-Lauf (in-place „Go") — Ziel des wire:poll. */
    public ?int $laufId = null;

    /** true, solange der Lauf im Hintergrund rechnet (steuert das Polling). */
    public bool $laeuft = false;

    /**
     * Queue-Watchdog (2026-08): gesetzt, wenn der Lauf ungewöhnlich lange auf `running` steht,
     * OHNE dass ein Schritt je Fortschritt machte — fast sicher kein Queue-Worker aktiv (ein echter
     * Fehler ruft markStepFailed → status=failed). Kein Abbruch, nur ein sichtbarer Hinweis statt
     * endlosem Spinner. Die Leitstelle ist der EINZIGE KI-Erstell-Pfad → derselbe Schutz, den die
     * Modals in Phase 0 bekamen (HatGeneratorLauf), gehört auch hierher.
     */
    public ?string $hinweis = null;

    /** Sekunden auf `running` ohne jeden Step-Fortschritt, ab denen der Watchdog anschlägt (über der realistischen Erst-Dauer). */
    protected const WATCHDOG_SEKUNDEN = 90;

    /** Deep-Link `?session=X&open=1` (z.B. vom Trendradar-Carry-in) öffnet den Editor direkt. */
    public function mount(): void
    {
        if (request()->boolean('open') && $this->sessionId !== null && $this->aktiveSession() !== null) {
            $this->ladeForm();
            $this->dispatch('modal.open', name: 'planung-editor');
        }
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    // ── Session-Lifecycle ──────────────────────────────────────────────

    public function neuePlanung(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Planung kann nicht angelegt werden.';

            return;
        }
        // „+" ohne Titel legt trotzdem eine Planung an (Default = Placeholder) und öffnet sie
        // sofort — sonst reagiert der Button bei leerem Feld still gar nicht (Bug 2026-08-03).
        // Umbenennen geht danach im Editor-Kopf (form.title).
        $titel = trim($this->neuTitel) !== '' ? trim($this->neuTitel) : 'Neue Planung';
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'ui']);
        $this->neuTitel = '';
        $this->fehler = null;
        $this->oeffne($session->id);
    }

    /**
     * Freie 1-Klick-Erstellung (Leitstelle, de-trend): legt eine leichte Session an
     * (created_via=cockpit_frei, kein Trend) und öffnet den Editor direkt auf dem Planung-Tab,
     * wo die Regler-Leitplanken + der Go liegen. Ein Klick bis zum Regler — kein Trend-Umweg.
     */
    public function schnellErstellen(string $scope, PlanningSessionService $svc): void
    {
        if (! in_array($scope, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        $titel = match ($scope) {
            'gericht' => 'Freies Gericht',
            'concept' => 'Freies Concept',
            default => 'Freies Basisrezept',
        };
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'cockpit_frei']);
        $this->fehler = null;
        $this->oeffne($session->id);
    }

    public function oeffne(int $id): void
    {
        $this->sessionId = $id;
        $this->fehler = null;
        $this->meldung = null;
        $this->ladeForm();
        $this->ladeLetztenLauf();
        $this->dispatch('modal.open', name: 'planung-editor');
    }

    public function waehle(int $id): void
    {
        $this->sessionId = $id;
        $this->ladeLetztenLauf();
    }

    /** Beim Öffnen/Wählen den letzten Kaskaden-Lauf laden — läuft er noch, wird das Polling fortgesetzt. */
    private function ladeLetztenLauf(): void
    {
        $team = $this->team();
        if ($team === null || $this->sessionId === null) {
            $this->laufId = null;
            $this->laeuft = false;

            return;
        }
        $lauf = app(PlanningCascadeService::class)->letzterLauf($team, $this->sessionId);
        $this->laufId = $lauf?->id;
        $this->laeuft = $lauf !== null && $lauf->status === 'running';
    }

    private function ladeForm(): void
    {
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        $this->form = [
            'title' => (string) $session->title,
            'brief' => (string) $session->brief,
            'analysis' => (string) $session->analysis,
            'creative_mode' => (string) $session->creative_mode,
        ];
    }

    public function speichern(PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $svc->update($team, $session->id, [
            'title' => $this->form['title'] ?? '',
            'brief' => $this->form['brief'] ?? null,
            'analysis' => $this->form['analysis'] ?? null,
        ]);
        if (in_array($this->form['creative_mode'] ?? null, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $svc->setCreativeMode($team, $session->id, $this->form['creative_mode']);
        }
        $this->meldung = 'Gespeichert.';
    }

    // ── Skizzen (Divergenz-Board) ──────────────────────────────────────

    public function ideeHinzu(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->ideeTitel) === '') {
            return;
        }
        try {
            $svc->add($team, ['planning_session_id' => $session->id, 'title' => $this->ideeTitel, 'created_via' => 'ui']);
            $this->ideeTitel = '';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function ideeVerwerfen(int $id, IdeenService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $svc->setStatus($team, $id, 'verworfen');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function paketBilden(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->paketName) === '') {
            return;
        }
        try {
            $svc->addGruppe($team, ['planning_session_id' => $session->id, 'name' => $this->paketName]);
            $this->paketName = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Leitstelle: Regler-Bedienung ───────────────────────────────────

    /** Pill-Toggle für die Richtungs-Regler (diaet_hart ist MULTI, sonst Single). */
    public function reglerPill(string $feld, string $wert): void
    {
        if ($feld === 'diaet_hart') {
            $this->regler['diaet_hart'] = in_array($wert, $this->regler['diaet_hart'], true)
                ? array_values(array_diff($this->regler['diaet_hart'], [$wert]))
                : [...$this->regler['diaet_hart'], $wert];

            return;
        }
        if (array_key_exists($feld, $this->regler)) {
            $this->regler[$feld] = $wert;
        }
    }

    /**
     * Regler → Richtungs-Param-Bündel — spiegelt EXAKT die Param-Logik der abgelösten
     * Rich-Modals: bio-Bool aus bio_praeferenz, Leer-Hints strippen (diaet_hart-Array +
     * Bools bleiben), Favoriten opt-in, VK-Achsen + Ziel-VK nur bei $vk. Wird am Go an
     * die Kaskade UND (für den Fan-out) an generation_params gereicht.
     *
     * @return array<string,mixed>
     */
    private function reglerParams(bool $vk): array
    {
        $p = $this->regler;
        $p['bio'] = $p['bio_praeferenz'] === 'bio';
        if (! $vk) {                                            // Basisrezept: keine VK-Achsen
            unset($p['occasion'], $p['serviceform'], $p['kompositions_stil']);
        }
        $p = array_filter($p, fn ($v) => $v !== '' && $v !== null && $v !== []);
        $p['use_favorites_list'] = $this->reglerFavoriten;
        $p['favorites_convenience_only'] = $this->reglerFavoriten && $this->reglerFavoritenConvenienceOnly;
        if ($vk && ($ziel = $this->zielVkEur()) !== null) {
            $p['ziel_vk_eur'] = $ziel;
        }

        return $p;
    }

    /** „8,50 €" → 8.5; außerhalb 0,50–500,00 € → null (Aufrufer meldet). Spiegel VkGeneratorModal. */
    private function zielVkEur(): ?float
    {
        $roh = str_replace([' ', '€'], '', trim($this->reglerZielVk));
        if ($roh === '') {
            return null;
        }
        $roh = str_replace(',', '.', $roh);
        if (! is_numeric($roh)) {
            return null;
        }
        $eur = round((float) $roh, 2);

        return $eur >= 0.5 && $eur <= 500.0 ? $eur : null;
    }

    /**
     * #1b Grounding-Preview: zeigt VOR dem Go, welches Wissen/Pairing/Template die Generierung für die
     * aktuellen Regler ziehen würde — OHNE zu generieren. Ruft denselben Kontext-Bau wie die Generierung
     * ({@see RecipeGenerationContextService::build}) und legt das `kontext`-Bündel für den Kontext-Inspektor
     * ab. Fail-soft: eine Preview darf den Editor nie brechen.
     */
    public function wissenVorschau(bool $vk = false): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $brief = trim((string) ($session->brief !== null && $session->brief !== '' ? $session->brief : $session->title));
        if ($brief === '') {
            $this->fehler = 'Für die Wissens-Vorschau erst einen Brief oder Titel setzen.';

            return;
        }
        try {
            $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
                ->build($team, $brief, $this->reglerParams($vk), $vk);
            $this->wissenVorschau = is_array($ctx['kontext'] ?? null) ? $ctx['kontext'] : null;
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Wissens-Vorschau fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── „Go" — Tiefen-Leiter über den geteilten Kaskaden-Motor ─────────

    /**
     * Go → in-place Generierung über {@see PlanningCascadeService}. `$scope` = Einstiegs-Stufe
     * (`rezept`|`gericht`|`concept`). Sammelt die Richtungs-Regler (rezept/gericht), persistiert
     * sie als `generation_params` (Fan-out-Vererbung) und reicht sie als Lauf-`params` an den Motor.
     * Startet im Hintergrund; die Fläche pollt {@see pruefeLauf}. Kein Redirect.
     */
    public function goKaskade(string $scope, PlanningCascadeService $cascade, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        // Ziel-VK-Eingabe (nur Gericht) wird GESAGT statt still verworfen — der Absender ist ein
        // Mensch, der 8,5 statt 850 meinte, und kann korrigieren (L8b-2, Spiegel VkGeneratorModal).
        if ($scope === 'gericht' && trim($this->reglerZielVk) !== '' && $this->zielVkEur() === null) {
            $this->fehler = 'Ziel-VK: bitte einen Netto-Preis je Portion zwischen 0,50 € und 500,00 € angeben (z. B. 8,50) — oder das Feld leer lassen.';

            return;
        }
        // Rahmen persistieren, damit der Motor mit dem aktuellen Brief/Modus arbeitet (spiegelt speichern()).
        $this->speichern($svc);
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        // Regler nur für die direkte Rezept-/Gericht-Kaskade; Concept ist reuse-basiert (keine Regler).
        $params = [];
        if (in_array($scope, ['rezept', 'gericht'], true)) {
            $params = $this->reglerParams($scope === 'gericht');
            // FAIL-SOFT: die Regler fließen ohnehin über die Lauf-`params` in den Depth-1-Job — die
            // Session-Persistenz ist NUR für die Fan-out-Vererbung. Kippt sie (z. B. generation_params-
            // Migration noch nicht durch), darf der Go NICHT sterben; die Leitplanken greifen direkt trotzdem.
            try {
                $svc->setGenerationParams($team, $session->id, $params);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Planung] setGenerationParams übersprungen (Fan-out-Vererbung aus) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
            }
        }
        try {
            $run = $cascade->starteKaskade($team, $scope, $session, (string) $session->creative_mode, [
                'created_via' => 'plan_go',
                'params' => $params,
                'voll_anreichern' => $this->vollAnreichern,   // recipe-first: default AUS
            ]);
            $this->laufId = $run->id;
            $this->laeuft = true;
            $this->hinweis = null;
            $this->wissenVorschau = null;   // neue Kaskade → Vorschau weg; die Steps zeigen dann das ECHTE Wissen (#1a)
            $this->meldung = 'Kaskade gestartet — Entwurf wird erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Poll-Ziel (wire:poll während $laeuft): Lauf-Status aus der DB lesen. */
    public function pruefeLauf(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            $this->laeuft = false;

            return;
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        if ($lauf === null || $lauf->status !== 'running') {
            $this->laeuft = false;
            $this->hinweis = null;
            if ($lauf !== null && $lauf->status === 'review') {
                $this->meldung = 'Entwurf erzeugt — im Ergebnis unten prüfen.';
            } elseif ($lauf !== null && $lauf->status === 'failed') {
                $this->fehler = 'Generierung fehlgeschlagen — Details im Ergebnis unten.';
            }

            return;
        }
        // Queue-Watchdog: hat KEIN Schritt je Fortschritt gemacht (alle noch queued/running) UND
        // läuft der Run schon ungewöhnlich lange → fast sicher kein Worker. Sobald irgendein Schritt
        // done/failed/… erreicht, ist der Worker bewiesen aktiv (legitim langer Fan-out) → kein Hinweis.
        $fortschritt = $lauf->steps->contains(fn ($s) => ! in_array($s->status, ['queued', 'running'], true));
        $alterSek = $lauf->created_at !== null ? $lauf->created_at->diffInSeconds(now()) : 0;
        $this->hinweis = (! $fortschritt && $alterSek > self::WATCHDOG_SEKUNDEN)
            ? 'Der Lauf läuft ungewöhnlich lange und kein Schritt ist fertig — vermutlich läuft kein Hintergrund-Worker (Queue). Sobald er den Job abarbeitet, erscheint der Entwurf automatisch.'
            : null;
    }

    // ── Freigabe / Verwerfen (Gate 2 — inline im Editor) ───────────────

    /** Einen erzeugten Draft freigeben (→ live) — Rezept approved / Concept active. */
    public function gibFrei(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->gibStepFrei($team, $stepId);
            $this->meldung = 'Freigegeben.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Einen Draft verwerfen (soft-delete). */
    public function verwirf(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->verwirfStep($team, $stepId);
            $this->meldung = 'Verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * A: Inline-Zutaten-Review eines Drafts auf-/zuklappen (voll editierbar vor Freigabe).
     * On-Demand — der IngredientEditor wird erst beim Öffnen gemountet.
     */
    public function toggleZutaten(int $stepId): void
    {
        if (in_array($stepId, $this->zutatenOffen, true)) {
            $this->zutatenOffen = array_values(array_diff($this->zutatenOffen, [$stepId]));
        } else {
            $this->zutatenOffen[] = $stepId;
        }
    }

    /** Alle offenen Entwürfe des Laufs freigeben. */
    public function alleFrei(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $cascade->gibRunFrei($team, $this->laufId);
        $this->meldung = 'Alle Entwürfe freigegeben.';
    }

    /** Alle offenen Entwürfe des Laufs verwerfen. */
    public function alleVerwerfen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $cascade->verwirfRun($team, $this->laufId);
        $this->meldung = 'Alle Entwürfe verworfen.';
    }

    // ── Composer-Tab (Foodpairing-Fläche: Anker zusammenstellen) ───────

    /** Einen Anker in die Auswahl aufnehmen (aus Suchtreffer ODER Kandidaten-Klick im Netz). */
    public function composerAdd(int $id): void
    {
        $ids = array_column($this->composerAnker, 'id');
        if (in_array($id, $ids, true) || count($this->composerAnker) >= 12) {
            return;
        }
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->first(['id', 'slug', 'display_de']);
        if ($a === null) {
            return;
        }
        $this->composerAnker[] = ['id' => (int) $a->id, 'slug' => $a->slug, 'label' => $a->display_de ?: $a->slug];
        $this->composerTerm = '';
    }

    /** Einen Anker aus der Auswahl entfernen. */
    public function composerRemove(int $id): void
    {
        $this->composerAnker = array_values(array_filter(
            $this->composerAnker,
            fn ($a) => (int) $a['id'] !== $id
        ));
        if ($this->composerFocus === $id) {
            $this->composerFocus = null;
        }
    }

    /** Fokus auf einen Anker setzen/aufheben (Klick im Netz; 0 oder erneut derselbe = aufheben). */
    public function composerFocus(int $id): void
    {
        if ($id === 0 || $this->composerFocus === $id) {
            $this->composerFocus = null;

            return;
        }
        if (in_array($id, array_map('intval', array_column($this->composerAnker, 'id')), true)) {
            $this->composerFocus = $id;
        }
    }

    // ── Datenbeschaffung ───────────────────────────────────────────────

    private function aktiveSession(): ?FoodAlchemistPlanningSession
    {
        if ($this->sessionId === null) {
            return null;
        }
        $team = $this->team();

        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId);
    }

    public function render()
    {
        $team = $this->team();

        // Sessions team-sichtbar + Trend-Kategorie/Klasse (loser Join über die Herkunft).
        $sessions = TeamScope::applyVisible(
            DB::table('foodalchemist_planning_sessions as s')
                ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 's.source_knowledge_document_id')
                ->whereNull('s.deleted_at'),
            's.team_id', $team
        )->orderByDesc('s.updated_at')
            ->get(['s.id', 's.title', 's.status', 's.source_knowledge_document_id', 's.updated_at', 'm.category', 'm.trend_class']);

        // Baum: Kategorie → Sessions (Frei-Bucket für ohne-Trend).
        $baum = $sessions->groupBy(fn ($s) => $s->category ?: '__frei')
            ->map(fn ($grp, $cat) => [
                'category' => $cat === '__frei' ? 'Frei / ohne Kategorie' : $cat,
                'sessions' => $grp->values(),
            ])->values();

        $active = $this->aktiveSession();
        $skizzen = null;
        if ($active !== null) {
            $skizzen = app(IdeenService::class)->liste($team, null, null, false, $active->id);
        }

        // Aktiver Kaskaden-Lauf (in-place „Go") inkl. Steps — für Fortschritt + Ergebnis-Liste.
        $lauf = ($team !== null && $this->laufId !== null)
            ? app(PlanningCascadeService::class)->lauf($team, $this->laufId)
            : null;

        // Composer-Tab: Ad-hoc-Netz + Kohäsion (fit/orphan je Anker) + browsebarer Picker.
        $composerNetz = ['nodes' => [], 'edges' => [], 'meta' => []];
        $composerCohesion = null;
        $composerBrowse = ['items' => [], 'total' => 0, 'kategorien' => []];
        if ($team !== null) {
            $pairing = app(PairingService::class);
            $composerIds = array_map('intval', array_column($this->composerAnker, 'id'));
            if ($composerIds !== []) {
                // Netz inkl. Brücken-Ebene — das Orphan-Flag (bridge-basiert) steckt schon
                // in den Anker-Knoten, die Brücken-Zusammenfassung in meta.bridge.
                $composerNetz = $pairing->pairingNetzForAnkers($team, $composerIds);
                // Direkt-Pairing-Kohäsion nur als Sekundär-Info im Readout (Brücken-Metrik = meta.bridge).
                $composerCohesion = $pairing->composerCohesion($composerIds);
            }
            // Fokus (falls gesetzt) → Picker-Badge/Sortierung relativ zum fokussierten Anker.
            $composerBrowse = $pairing->composerAnkerBrowse(
                $team, (string) $this->composerTerm, $this->composerCategory !== '' ? $this->composerCategory : null,
                $composerIds, 200, $this->composerFocus
            );
        }

        // Fokus-Label (und Fokus verwerfen, wenn der Anker nicht mehr in der Auswahl ist).
        $composerFokusLabel = null;
        if ($this->composerFocus !== null) {
            foreach ($this->composerAnker as $a) {
                if ((int) $a['id'] === $this->composerFocus) {
                    $composerFokusLabel = $a['label'];
                    break;
                }
            }
            if ($composerFokusLabel === null) {
                $this->composerFocus = null;
            }
        }

        return view('foodalchemist::livewire.planung.index', [
            'sessions' => $sessions,
            'baum' => $baum,
            'active' => $active,
            'skizzen' => $skizzen,
            'lauf' => $lauf,
            'composerNetz' => $composerNetz,
            'composerCohesion' => $composerCohesion,
            'composerBrowse' => $composerBrowse,
            'composerFocus' => $this->composerFocus,
            'composerFokusLabel' => $composerFokusLabel,
        ])->layout('platform::layouts.app');
    }
}
