<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\Warteschlange;

/** Persistenter, begrenzter DAG für ineinander verschachtelte Basisrezepte. */
class RecipeDependencyWorkflowService
{
    public const MAX_DEPTH = 3;

    /**
     * Wie viele SUB-REZEPT-Schritte ein Lauf insgesamt planen darf — die Tiefe des Baums, nicht
     * die Breite der Ausgabe.
     *
     * KORREKTUR 2026-09-03: die Prüfung zählte ALLE nicht-`skipped`-Steps des Laufs, also auch
     * die `gericht`-Steps der Ausgabe. Ein Speisekarten-Lauf pflanzt bis zu 40 Positionen, ein
     * Speiseplan bis zu sechs Wochen (90 Zellen) — das Budget war damit aufgebraucht, BEVOR das
     * erste Basisrezept geplant wurde, und `planChildren` brach beim ersten Kandidaten ab. Folge:
     * die Zutaten blieben ungebunden, und zwar lautlos.
     *
     * Aufgefallen ist es beim Sichtbarmachen dieses Deckels — und es war schon vorher falsch, nur
     * weniger sichtbar (bei 30 Zellen bekamen etwa die ersten vier Zellen ihre Sub-Rezepte und der
     * Rest keine). Der Docblock dieser Klasse sagt, was gemeint war: »begrenzter DAG für
     * ineinander verschachtelte Basisrezepte«. Zwei völlig verschiedene Dinge dürfen sich kein
     * Budget teilen — die Breite deckeln die Ausgabe-Deckel (SPEISEPLAN_MAX_WOCHEN,
     * SPEISEKARTE_MAX_POSITIONEN, CONCEPT_MAX_SLOTS), jeder für sich und jeder gemeldet.
     */
    public const MAX_STEPS = 50;

    public function prepare(Team $team, int $stepId, string $description, array $parameter, bool $vkModus): array
    {
        $context = app(RecipeGenerationContextService::class)->build($team, $description, $parameter, $vkModus);
        // Der Komponenten-Plan (plan/komponenten) gehört dem Step, nicht dem Bau: vorher schrieb der Bau den
        // Snapshot komplett neu und löschte ihn (demo Lauf 86, Step 556) — kindVorgaben fand danach keine
        // Suchbegriffe mehr, „neu erzeugen“ keinen bestätigten Plan.
        $alt = FoodAlchemistCascadeRunStep::whereKey($stepId)->value('context_snapshot');
        $alt = is_string($alt) ? (json_decode($alt, true) ?: []) : (is_array($alt) ? $alt : []);
        $plan = array_intersect_key($alt, array_flip(['plan', 'komponenten', 'kind_brief']));
        FoodAlchemistCascadeRunStep::whereKey($stepId)->update(['context_snapshot' => [...$context['snapshot'], ...$plan]]);

        return $context;
    }

    /**
     * Spec 53 Paket B Aufgabe 5 — „Verwendet" = wirklich gesendet.
     *
     * `prepare()` (oben) schreibt `context_snapshot.kanon_files` VOR dem KI-Call aus der vollen
     * Kanon-Liste (`pflicht` + `wenn_platz`) — `RecipeGenerationContextService::build()` kennt die
     * Gateway-Auswahl noch nicht, die erst `AiGatewayService::selectKanon()` trifft (droppt
     * `wenn_platz`, wenn das Budget nicht reicht). Die Step-Zeile „Kanon (n) · Recherche (m)"
     * (`step-zeile.blade.php`, liest `context_snapshot.kanon_files`) zeigte damit eine Zahl, die
     * grösser sein kann als das, was tatsächlich im Prompt stand.
     *
     * Fix OHNE AiGatewayService/GenerateRecipeJob anzufassen: der Gateway schreibt die WIRKLICH
     * gesendete Kanon-Liste bereits verlässlich in `foodalchemist_ai_call_log.knowledge_channels`
     * (`AiGatewayService::schreibeCallLog()`), und `RecipeGeneratorService::generiereImLauf()`
     * verknüpft diese Call-Log-Zeile NACH dem Commit über `target_table`/`target_id` mit dem
     * fertigen Rezept. Zum Zeitpunkt von `afterGenerated()` (nach `bindCompletedChild` aufgerufen)
     * steht diese Verknüpfung bereits — ein einfacher Rückschreib-Haken statt einer zweiten
     * Zähl-Formel („eine Rechnung, ein Ergebnis").
     *
     * Fail-soft: fehlt der Call-Log-Eintrag oder das Feld (älterer Migrationsstand,
     * `schreibeCallLog()` schreibt `knowledge_channels` nur hinter `Schema::hasColumn`), bleibt
     * `context_snapshot` unverändert — der alte (potenziell zu grosse) Wert ist kein Blocker.
     *
     * Aufgabe 6 (verworfen getrennt ausweisen): befüllt hier zusätzlich den `kanon`-Zweig von
     * `context_snapshot.knowledge_dropped` — die gedroppten `wenn_platz`-Dossiers, als Differenz
     * zwischen der vollen Kanon-Liste (vor dieser Korrektur) und der wirklich gesendeten. Der
     * `retrieval`-Zweig kommt bereits korrekt aus `RecipeGenerationContextService::build()`
     * (`contextFor()::files_dropped`) und wird hier nur durchgereicht, nicht neu berechnet.
     */
    private function korrigiereKanonFiles(FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe): void
    {
        $snapshot = $step->context_snapshot;
        if (! is_array($snapshot) || ! array_key_exists('kanon_files', $snapshot)) {
            return;
        }
        $row = DB::table('foodalchemist_ai_call_log')
            ->where('target_table', 'foodalchemist_recipes')->where('target_id', $recipe->id)
            ->whereIn('feature', ['recipe.generator', 'vk.generator'])
            ->orderByDesc('id')->first(['knowledge_channels']);
        if ($row === null || $row->knowledge_channels === null) {
            return;
        }
        $channels = json_decode((string) $row->knowledge_channels, true);
        if (! is_array($channels) || ! array_key_exists('kanon', $channels)) {
            return;                                                     // kein Kanon gesendet ⇒ nichts zu korrigieren
        }
        $kanonVorher = $snapshot['kanon_files'];
        $kanonGesendet = is_array($channels['kanon']) ? array_values($channels['kanon']) : [];
        $kanonVerworfen = array_values(array_diff($kanonVorher, $kanonGesendet));

        $dropped = is_array($snapshot['knowledge_dropped'] ?? null) ? $snapshot['knowledge_dropped'] : [];
        $dropped['kanon'] = $kanonVerworfen;
        $updates = ['kanon_files' => $kanonGesendet, 'knowledge_dropped' => $dropped];
        if ($kanonGesendet === $kanonVorher && $kanonVerworfen === []) {
            return;                                                     // schon deckungsgleich, kein Schreib-Nutzen
        }
        $neuerSnapshot = array_merge($snapshot, $updates);
        FoodAlchemistCascadeRunStep::whereKey($step->id)->update(['context_snapshot' => $neuerSnapshot]);
        $step->context_snapshot = $neuerSnapshot;
    }

    public function afterGenerated(Team $team, int $stepId, int $userId, FoodAlchemistRecipe $recipe, array $offene, array $parameter): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }

        $this->korrigiereKanonFiles($step, $recipe);
        $this->bindCompletedChild($team, $step, $recipe);

        // Sichtbarkeit (Beobachtung Dominique 2026-08-14): die vom Generator direkt verdrahteten
        // Sub-Rezepte gehören in die Basisrezepte-Stufe, nicht nur als 📖-Referenz in die Zutatenliste.
        $this->spiegleReuseKinder($team, $step, $recipe);

        // Gestuft (Gate pro Ebene): die Sub-Rezepte NICHT sofort erzeugen, sondern die Kandidaten am Step
        // aufbewahren — die Freigabe dieses Steps arbeitet sie ab ({@see resumeDeferredChildren}).
        if ($parameter['_defer_children'] ?? false) {
            $step->update(['deferred' => ['children' => [
                'offene' => array_values($offene),
                'params' => $parameter,
                'user_id' => $userId,
            ]]]);
            // …aber sie werden SOFORT sichtbar: je Kandidat ein `geplant`-Step in der Basisrezepte-
            // Stufe (Gericht = Basisrezepte, nicht flache Zutaten). Kein Job — die Freigabe der Stufe
            // darüber schaltet sie scharf ({@see resumeDeferredChildren}).
            $this->planChildren($team, $step, $recipe, $offene, $parameter);

            return;
        }

        if (! ($parameter['auto_dependencies'] ?? false) || (int) $step->depth >= self::MAX_DEPTH) {
            return;
        }
        $this->dispatchChildren($team, $step, $userId, $recipe, $offene, $parameter);
    }

    /**
     * Fortsetzung eines aufgeschobenen Steps bei der Freigabe: die vorgemerkten Sub-Rezepte jetzt erzeugen.
     * Ab hier eager — die freigegebene Ebene erzeugt ihre Kinder; tiefere Ebenen lösen sich automatisch auf.
     */
    public function resumeDeferredChildren(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe): void
    {
        $d = $step->deferred['children'] ?? null;
        if (! is_array($d)) {
            return;
        }
        $params = is_array($d['params'] ?? null) ? $d['params'] : [];
        $params['auto_dependencies'] = true;
        unset($params['_defer_children']);
        $offene = is_array($d['offene'] ?? null) ? $d['offene'] : [];
        $this->dispatchChildren($team, $step, (int) ($d['user_id'] ?? 0), $recipe, $offene, $params);
        $step->update(['deferred' => null]);
    }

    /**
     * Parameter beim Abstieg vom Eltern- zum Kind-Rezept — EINE Stelle für beide Abstiege (Stufen-Freigabe
     * und „jetzt erzeugen“ je Zeile; vorher zwei Listen, die auseinanderlaufen konnten).
     *
     * Grundsatz (Dominique 09.10.): ein Basisrezept steht für sich — sein Name trägt, was hineingehört
     * („Jus: Thymian“ bekommt Thymian, weil es im Namen steht). Vererbt werden nur harte Bedingungen, die
     * für das ganze Gericht gelten müssen (Diät, Allergen-Ausschluss) und Produktionsachsen (Convenience,
     * Bestand, Level, Sektor, Frische, Bio). Alles Teller- und Geschmacksbezogene bleibt beim Gericht:
     * - VK-Achsen, Titel, Pax, Tellergewicht (L5/L6), Ansatz/Suchbegriffe/Plan der Wurzel (Spec 80 B6).
     * - Aroma, Aroma-Küche, Saison: Live-Test demo 09.10. (Lauf 86) — „herbstlich-würzig mit Ingwer, Kürbis und
     *   Pilz-Umami“ lief in JEDES Unterrezept und dessen GP-Erdung: Jus mit 1,2 kg Kürbis und 1 kg Pilzen,
     *   Kürbiskern-Crunch mit Pilzmischung.
     * Positivliste statt Streichliste: eine neue Gericht-Achse wandert so nicht still ins Kind.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function kindParameter(array $p): array
    {
        return array_intersect_key($p, array_flip(self::KIND_ERBT));
    }

    /** Was ein Kind-Basisrezept vom Gericht erbt — harte Bedingungen, Produktionsachsen, Lauf-Steuerung. */
    private const KIND_ERBT = [
        'diaet_hart', 'allergen_nogo',
        'convenience', 'frische', 'frische_erlaubt', 'bio', 'bio_pref', 'bio_praeferenz', 'bestand', 'level', 'niveau', 'sektor',
        'ki_bilder', 'complete_coverage', 'use_favorites_list', 'favorites_convenience_only',
        'planning_session_id', 'cascade_step_id', 'auto_dependencies',
    ];

    /**
     * Schaltet die geplanten Sub-Rezepte EINES Steps scharf: {@see planChildren} legt/findet die
     * Kind-Steps, dieser Dispatch-Kern startet je noch nicht laufendem Kind einen
     * {@see GenerateRecipeJob} (`geplant` → `running`). Ein im Lauf geteiltes Sub-Rezept wird nur
     * EINMAL erzeugt und danach an alle Eltern-Zutaten gebunden.
     */
    private function dispatchChildren(Team $team, FoodAlchemistCascadeRunStep $step, int $userId, FoodAlchemistRecipe $recipe, array $offene, array $parameter): void
    {
        $kindVollAnreichern = (bool) ($parameter['_voll_anreichern'] ?? false);
        $childParameter = self::kindParameter($parameter);
        // Kein `_knowledge_scope` mehr (Dominique 09.10.): jedes Basisrezept sucht sein Wissen selbst — vorher
        // rankte das Kind nur innerhalb der Dossiers, die das GERICHT gefunden hatte; ein „Jus: Ginger Beer“
        // bekam Jus-Technik nur, wenn das Gericht sie zufällig mitgezogen hatte.

        foreach ($this->planChildren($team, $step, $recipe, $offene, $parameter) as [$child, $ingredientId, $text]) {
            if ($child->status === 'done' && $child->ref_id !== null) {
                $this->bindIngredient($team, $ingredientId, (int) $child->ref_id);

                continue;
            }
            if ($child->status !== 'geplant') {
                continue;   // schon unterwegs (running/queued) oder terminal — kein zweiter Job
            }
            $this->starteKind($team, $child, $userId, $text,
                [...$childParameter, ...$this->kindVorgaben($step, $ingredientId, $text)], $kindVollAnreichern);
        }
    }

    /**
     * Der EINE Start eines Kind-Basisrezepts — Stufen-Freigabe, „jetzt erzeugen“ und „neu erzeugen“ laufen hier
     * durch (Architekturtest: keine weitere Dispatch-Stelle für Kind-Steps).
     *
     * Ein Kind steht für sich und wird wie ein allein gestartetes Basisrezept geplant (Komponenten-Plan, Bestand je
     * Komponente, Suchbegriffe) — automatisch, ohne Mensch-Gate (Dominique 09.10.). Auf der untersten Ebene
     * (MAX_DEPTH) kein Plan: seine Komponenten könnten nie mehr gebaut werden.
     *
     * @param  array<string, mixed>  $params  schon durch kindParameter + kindVorgaben
     */
    public function starteKind(Team $team, FoodAlchemistCascadeRunStep $child, int $userId, string $text, array $params, bool $vollAnreichern, ?string $auftrag = null): void
    {
        // Den ursprünglichen Auftrag festhalten: markStepDone zieht das Label später auf den Artefaktnamen
        // („Jus: Thymian, kräftig“) — „neu erzeugen“ braucht den Auftrag, nicht den letzten Namen (Review Hans).
        // Frisch lesen (ein vorher geladenes Modell kennt kind_brief nicht) und nie den Kommentar eines Versuchs
        // als Auftrag festschreiben — sonst wüchse er bei jedem neuen Versuch an (Review Hans).
        $snap = $child->fresh()?->context_snapshot;
        $snap = is_array($snap) ? $snap : [];
        if (! isset($snap['kind_brief'])) {
            $child->update(['context_snapshot' => [...$snap, 'kind_brief' => $auftrag ?? $text]]);
        }
        // Unter „nur Bestand“ dürfte eine neue Komponente ohnehin kein Kind werden (planChildren) — kein Plan-Call.
        $planen = (bool) config('foodalchemist.kaskade.kind_plan', true) && (int) $child->depth < self::MAX_DEPTH
            && ($params['bestand'] ?? null) !== 'nur_bestand';
        if ($planen) {
            $child->update(['status' => 'running', 'error' => null, 'generator_run_id' => null]);
            app(PlanningCascadeService::class)->setzePhase((int) $child->id, 'Komponenten werden geplant …');
            \Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::dispatch($team->id, $userId, (int) $child->id, $text,
                [...$params, '_voll_anreichern' => $vollAnreichern, '_kind' => true])->onQueue(Warteschlange::rezepte());

            return;
        }
        $this->baueKind($team, $child, $userId, $text, $params, $vollAnreichern);
    }

    /**
     * Nach dem Komponenten-Plan des Kinds bauen — ohne Gate. Das Kind geht nie auf `geplant` (heißt bei Kind-Steps
     * „wartet auf Stufen-Freigabe“; die nächste Freigabe dispatchte es sonst ein zweites Mal).
     *
     * @param  array<string, mixed>  $params
     * @param  list<array<string, mixed>>  $komponenten
     */
    public function baueKindNachPlan(Team $team, FoodAlchemistCascadeRunStep $child, int $userId, string $text, array $params, array $komponenten): void
    {
        $vollAnreichern = (bool) ($params['_voll_anreichern'] ?? false);
        unset($params['_voll_anreichern'], $params['_kind']);
        // Ein Baustein, den es freigegeben im Bestand gibt (bestandFuer: nur approved, Typ, Diät — findet auch per
        // Matcher, was planChildren per Namens-Token-Set übersah): binden statt eine Dublette bauen (Review Berater).
        $einziger = count($komponenten) === 1 ? $komponenten[0] : null;
        if (is_array($einziger['bestand'] ?? null) && (int) ($einziger['bestand']['recipe_id'] ?? 0) > 0) {
            $this->uebernimmBestandFuerKind($team, $child, $einziger['bestand']);

            return;
        }
        if (count($komponenten) > 1) {
            $params['plan_komponenten'] = array_values($komponenten);
        }
        // Suchbegriffe ohne Extra-Call: der Plan liefert sie je Komponente schon.
        if (RecipeGenerationContextService::suchbegriffeAus($params) === []) {
            $begriffe = [];
            foreach ($komponenten as $k) {
                foreach ((array) ($k['suchbegriffe'] ?? []) as $t) {
                    $begriffe[mb_strtolower(trim((string) $t))] = trim((string) $t);
                }
            }
            unset($begriffe['']);
            if ($begriffe !== []) {
                $params['suchbegriffe'] = array_values($begriffe);
            }
        }
        // Ein neuer Versuch ersetzt den alten Plan immer — auch durch „keinen“, sonst speisten alte Komponenten die Enkel.
        $snap = is_array($child->fresh()?->context_snapshot) ? $child->fresh()->context_snapshot : [];
        unset($snap['plan'], $snap['komponenten']);
        if ($komponenten !== []) {
            $snap = [...$snap, 'plan' => true, 'komponenten' => array_values($komponenten)];
        }
        $child->update(['context_snapshot' => $snap]);
        $this->baueKind($team, $child, $userId, $text, $params, $vollAnreichern);
    }

    /** @param  array{recipe_id: int, name?: string}  $bestand */
    private function uebernimmBestandFuerKind(Team $team, FoodAlchemistCascadeRunStep $child, array $bestand): void
    {
        $rezept = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $bestand['recipe_id']);
        if ($rezept === null) {
            app(PlanningCascadeService::class)->markStepFailed((int) $child->id, 'Bestands-Basisrezept nicht mehr vorhanden.');

            return;
        }
        $this->bindCompletedChild($team, $child, $rezept);
        $child->update([
            'status' => 'skipped', 'ref_type' => 'recipe', 'ref_id' => (int) $rezept->id, 'phase' => null, 'error' => null,
            'deferred' => ['reuse' => ['reif' => true, 'eigen' => (int) $rezept->team_id === (int) $team->id,
                'luecken' => [], 'status' => (string) ($rezept->status?->value ?? '')]],
        ]);
        app(PlanningCascadeService::class)->recomputeRunStatus((int) $child->cascade_run_id);
    }

    /** @param  array<string, mixed>  $params */
    private function baueKind(Team $team, FoodAlchemistCascadeRunStep $child, int $userId, string $text, array $params, bool $vollAnreichern): void
    {
        $runId = (string) Str::uuid();
        $child->update(['status' => 'running', 'error' => null, 'generator_run_id' => $runId]);
        Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(60));
        // Bis MAX_STEPS = 50 Sub-Rezepte je Lauf. Eigene Schlange, damit sie parallel zu den Gerichten laufen.
        GenerateRecipeJob::dispatch($runId, $team->id, $userId, $text, [
            ...$params,
            'cascade_step_id' => $child->id,
            'auto_dependencies' => true,
        ], false, $vollAnreichern)->onQueue(Warteschlange::rezepte());
    }

    /**
     * Spec 80 B6: Vorgaben eines Kind-Basisrezepts aus seiner Zeile im Eltern-Rezept. Ansatz = Menge der Zeile
     * (eine 100-g-Matte wurde vorher auf den 2-kg-Ansatz der Wurzel ausgelegt); Suchbegriffe = die der
     * passenden Plan-Komponente. Nur Masse-/Volumen-Einheiten werden als Ansatz übernommen.
     *
     * @return array<string, mixed>
     */
    private function kindVorgaben(FoodAlchemistCascadeRunStep $eltern, int $ingredientId, string $text): array
    {
        $out = [];
        $zeile = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::query()->with('unit:id,slug')->find($ingredientId);
        $slug = (string) ($zeile?->unit?->slug ?? '');
        if ($zeile !== null && (float) $zeile->quantity > 0 && in_array($slug, ['g', 'kg', 'ml', 'l'], true)) {
            $out['ziel_menge'] = (float) $zeile->quantity;
            $out['ziel_einheit'] = $slug;
        }
        $norm = static fn ($s) => mb_strtolower(trim((string) $s));
        foreach ((array) (($eltern->context_snapshot ?? [])['komponenten'] ?? []) as $k) {
            if (is_array($k) && $norm($k['name'] ?? '') === $norm($text) && ! empty($k['suchbegriffe'])) {
                $out['suchbegriffe'] = array_values((array) $k['suchbegriffe']);
                break;
            }
        }

        return $out;
    }

    /**
     * Schaltet EINEN geplanten Sub-Rezept-Step scharf — der „jetzt erzeugen"-Knopf je Zeile, VOR der
     * Freigabe der Stufe darüber. Nutzt die am Eltern-Step aufgeschobenen Kind-Parameter
     * ({@see afterGenerated}: `deferred.children.params`/`user_id`), fällt sonst auf die Lauf-Params
     * zurück. Dispatcht genau EINEN {@see GenerateRecipeJob} (`geplant` → `running`). Die spätere
     * Stufen-Freigabe ({@see dispatchChildren}) sieht den Step dann nicht mehr als `geplant` und
     * startet ihn nicht doppelt. Kein Re-Planen: die Zeile + ihre Dependency stehen schon.
     *
     * @return bool true, wenn ein Job dispatcht wurde (Aufrufer recomputet den Run danach)
     */
    public function dispatchGeplantesKind(Team $team, FoodAlchemistCascadeRunStep $child): bool
    {
        if ($child->status !== 'geplant' || $child->kind !== 'rezept') {
            return false;
        }
        $text = trim((string) $child->label);
        if ($text === '') {
            return false;
        }
        $parent = $child->parent_step_id !== null ? FoodAlchemistCascadeRunStep::find($child->parent_step_id) : null;
        $d = is_array($parent?->deferred['children'] ?? null) ? $parent->deferred['children'] : [];
        $params = is_array($d['params'] ?? null) ? $d['params'] : (is_array($child->run?->params) ? $child->run->params : []);
        $userId = (int) ($d['user_id'] ?? \Illuminate\Support\Facades\Auth::id() ?? 0);
        $kindVollAnreichern = (bool) ($params['_voll_anreichern'] ?? false);
        $params = self::kindParameter($params);
        $dep = FoodAlchemistCascadeRecipeDependency::where('child_step_id', $child->id)->first(['ingredient_id']);
        if ($parent !== null && $dep !== null) {
            $params = [...$params, ...$this->kindVorgaben($parent, (int) $dep->ingredient_id, $text)];
        }
        $this->starteKind($team, $child, $userId, $text, $params, $kindVollAnreichern);

        return true;
    }

    /**
     * „Neu erzeugen“ an einem Kind-Step: dieselben Vorgaben wie beim ersten Start (kindParameter + kindVorgaben +
     * Plan) — vorher lief das über den Wurzel-Pfad mit den vollen Lauf-Params (Aroma des Gerichts kam zurück).
     */
    public function starteKindNeu(Team $team, FoodAlchemistCascadeRunStep $child, ?string $kommentar = null): void
    {
        // Der ursprüngliche Auftrag, nicht das Label: das trägt nach dem Bau den Artefaktnamen.
        $auftrag = (string) (($child->fresh()?->context_snapshot ?? [])['kind_brief'] ?? $child->label ?? '');
        $kommentar = trim((string) $kommentar);
        $text = $kommentar !== '' ? rtrim($auftrag) . "\n\nGezielte Anpassung (Nutzer-Feedback zu dieser Position): " . $kommentar : $auftrag;
        $parent = $child->parent_step_id !== null ? FoodAlchemistCascadeRunStep::find($child->parent_step_id) : null;
        $d = is_array($parent?->deferred['children'] ?? null) ? $parent->deferred['children'] : [];
        $params = is_array($d['params'] ?? null) ? $d['params'] : (is_array($child->run?->params) ? $child->run->params : []);
        $userId = (int) (($d['user_id'] ?? null) ?: (\Illuminate\Support\Facades\Auth::id() ?? 0));
        $vollAnreichern = (bool) ($params['_voll_anreichern'] ?? false);
        $params = self::kindParameter($params);
        $dep = FoodAlchemistCascadeRecipeDependency::where('child_step_id', $child->id)->first(['ingredient_id']);
        if ($parent !== null && $dep !== null) {
            $params = [...$params, ...$this->kindVorgaben($parent, (int) $dep->ingredient_id, $auftrag)];
        }
        $this->starteKind($team, $child, $userId, $text, $params, $vollAnreichern, $auftrag);
    }

    /**
     * Wie viele `basisrezept_anlegen`-Kandidaten eines Rezepts noch UNGEBUNDEN sind.
     *
     * Der Zustand steht an der Zutat (`referenced_recipe_id`), nicht in der Kandidatenliste —
     * deshalb ist diese Zahl idempotent, auch wenn dieselbe Liste zweimal durchlaufen wird.
     *
     * @param  list<array<string, mixed>>  $kandidaten
     */
    private function ungebundeneKandidaten(FoodAlchemistRecipe $recipe, array $kandidaten): int
    {
        $recipe->loadMissing('ingredients:id,recipe_id,position,referenced_recipe_id');
        $offen = 0;
        foreach ($kandidaten as $k) {
            if (! is_array($k) || ($k['primaer'] ?? null) !== 'basisrezept_anlegen') {
                continue;
            }
            $zutat = $recipe->ingredients->firstWhere('position', ((int) ($k['index'] ?? 0)) + 1);
            if ($zutat !== null && $zutat->referenced_recipe_id === null) {
                $offen++;
            }
        }

        return $offen;
    }

    /**
     * Vermerkt, dass die Rekursion an der Ebenen-Grenze aufgehört hat.
     *
     * Fachlich: Gericht → Sauce → Fond ist Ebene 3. Was darunter läge (der Kalbsfond der Sauce
     * des Fonds), baut die Kaskade nicht mehr. Das deckt sich mit dem Regelwerk Basisrezepte §4
     * („max. 3 Ebenen Rekursion") — der Deckel ist also die Regel, nicht ein Notbehelf, und er
     * darf auch nicht einfach gehoben werden.
     *
     * Die HANDLUNG ist bewusst NICHT »über ‚Basisrezept ergänzen' nachziehen«: dieser Knopf ruft
     * `ergaenzeManuellenSubStep` ohne Eltern-Step, der Fallback-Anker greift dann den WURZEL-Step
     * des Laufs — die Komponente landete also an der obersten Ebene statt an der untersten, wo
     * sie hingehört. Die Meldung schickt darum ins Rezept selbst.
     *
     * @param  list<array<string, mixed>>  $offene
     */
    private function vermerkeTiefenGrenze(FoodAlchemistCascadeRunStep $step, array $offene): void
    {
        $kandidaten = 0;
        foreach ($offene as $k) {
            if (is_array($k) && ($k['primaer'] ?? null) === 'basisrezept_anlegen') {
                $kandidaten++;
            }
        }
        if ($kandidaten < 1) {
            return;   // an der Grenze, aber nichts zu bauen — kein Befund
        }

        FoodAlchemistCascadeRun::find((int) $step->cascade_run_id)?->vermerkeDeckel(
            'sub_rezept_tiefe',
            self::MAX_DEPTH,
            self::MAX_DEPTH + 1,
            $kandidaten,
            sprintf(
                '%d %s ohne eigenes Rezept — die Kaskade baut nur %d Ebenen tief. In den Rezepten '
                . 'der untersten Stufe von Hand zuordnen.',
                $kandidaten,
                $kandidaten === 1 ? 'Komponente' : 'Komponenten',
                self::MAX_DEPTH,
            ),
        );
    }

    /**
     * Vermerkt am Lauf, dass das Sub-Rezept-Budget erschöpft ist — und WAS dadurch liegen bleibt.
     *
     * Anders als bei den Ausgabe-Deckeln bedeutet das hier nicht »weniger«, sondern ein
     * UNFERTIGES Rezept: es entsteht kein Kind-Step und keine Dependency, die Zutat bleibt also
     * ungebunden. Damit fehlen sie in EK und Allergenen, ohne dass am Gericht etwas fehlend
     * aussieht.
     *
     * Die Zahl kommt aus dem ZUSTAND, nicht aus der Schleifenposition. Das ist der Kern: die
     * Planung läuft je Eltern-Rezept ZWEIMAL über dieselbe Kandidatenliste (einmal aus
     * {@see afterGenerated}, einmal aus {@see resumeDeferredChildren} nach der Freigabe). Eine
     * aus `$i` abgeleitete Zahl würde sich damit verdoppeln und im zweiten Durchgang sogar die
     * GANZE Liste melden statt des Rests — eine Zahl über der Gesamtmenge der Komponenten
     * zerstört das Vertrauen in die Meldung sofort.
     *
     * Gezählt wird deshalb, was am Ende wirklich offen ist: Zutaten der Rezepte dieses Laufs, die
     * als `basisrezept_anlegen` vorgemerkt sind und weiterhin kein `referenced_recipe_id` tragen.
     * Diese Zahl ist über beide Durchgänge idempotent — und passt damit auf die
     * Replace-Semantik von {@see FoodAlchemistCascadeRun::vermerkeDeckel} statt sie zu brechen:
     * der letzte Schreiber gewinnt mit dem dann gültigen Stand.
     */
    private function vermerkeTiefenBudget(FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe, array $offene): void
    {
        $run = FoodAlchemistCascadeRun::find((int) $step->cascade_run_id);
        if ($run === null) {
            return;
        }

        // Zwei Quellen, weil es zwei Pfade gibt: im GESTUFTEN Lauf liegen die Kandidaten am Step
        // (`deferred.children.offene`), im direkten kommen sie als Argument und wurden nie
        // abgelegt. Nur die abgelegten zu zählen war mein Fehler — der direkte Pfad hätte gar
        // nichts gemeldet, und genau dieser Pfad fährt die Speiseplan-Zellen.
        $offen = $this->ungebundeneKandidaten($recipe, $offene);

        $steps = FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
            ->whereKeyNot($step->getKey())
            ->whereIn('kind', ['rezept', 'gericht'])
            ->get(['id', 'ref_id', 'deferred']);
        foreach ($steps as $s) {
            $kandidaten = is_array($s->deferred['children']['offene'] ?? null) ? $s->deferred['children']['offene'] : [];
            if ($kandidaten === [] || $s->ref_id === null) {
                continue;
            }
            $rezept = FoodAlchemistRecipe::with('ingredients:id,recipe_id,position,referenced_recipe_id')->find((int) $s->ref_id);
            if ($rezept !== null) {
                $offen += $this->ungebundeneKandidaten($rezept, $kandidaten);
            }
        }

        $run->vermerkeDeckel(
            'sub_rezept_budget',
            self::MAX_STEPS,
            self::MAX_STEPS + $offen,
            $offen,
            sprintf(
                '%d %s nicht als Basisrezept angelegt — der Lauf ist bei %d Schritten voll. Sie '
                . 'stehen offen in den Zutaten: dort verknüpfen, sonst fehlen sie in EK und Allergenen.',
                $offen,
                $offen === 1 ? 'Komponente' : 'Komponenten',
                self::MAX_STEPS,
            ),
        );
    }

    /**
     * Plant die Sub-Rezepte eines Steps: je offener `basisrezept_anlegen`-Zeile ein Kind-Step
     * (`kind=rezept`, Status `geplant` = benannt, noch nicht erzeugt) + die Dependency auf die
     * Eltern-Zutat. Legt KEINE Jobs an — das ist {@see dispatchChildren}. Idempotent über
     * `dedupe_key` (identische Sub-Rezepte teilen sich EINEN Step im Lauf); gedeckelt durch
     * {@see MAX_DEPTH}/{@see MAX_STEPS}, wobei `skipped`-Zeilen (reine Reuse-Sichtbarkeit) das
     * Erzeugungs-Budget NICHT verbrauchen.
     *
     * @return list<array{0: FoodAlchemistCascadeRunStep, 1: int, 2: string}> je Kandidat
     *                                                                       [Kind-Step, Zutat-ID, Kandidaten-Text (= Brief der Erzeugung)]
     */
    private function planChildren(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe, array $offene, array $parameter): array
    {
        if ((int) $step->depth >= self::MAX_DEPTH) {
            $this->vermerkeTiefenGrenze($step, $offene);

            return [];
        }
        $geplant = [];

        foreach ($offene as $open) {
            // Kohärenz-Gate (2026-08-07) + Diät-/Allergen-Gate (L3): ENTdrahtete Zeilen tragen einen
            // `kritiker`- bzw. `diaet_verstoss`-Grund. Sie dürfen NICHT auto-nachgeneriert werden — sonst
            // liesse die Kaskade den gerade entfernten Fremdkörper / Diät-Verstoß als frisches Sub-Rezept
            // wiederauferstehen (der Mensch wählt eine konforme Alternative).
            if (isset($open['kritiker']) || isset($open['diaet_verstoss'])) {
                continue;
            }
            if (($open['primaer'] ?? null) !== 'basisrezept_anlegen') {
                continue;
            }
            // NUR die Sub-Rezept-Schritte zählen (kind='rezept'). Vorher zählte die Prüfung alle
            // Steps des Laufs mit — siehe MAX_STEPS.
            if (FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                ->where('kind', 'rezept')
                ->where('status', '!=', 'skipped')->count() >= self::MAX_STEPS) {
                $this->vermerkeTiefenBudget($step, $recipe, $offene);

                break;
            }
            $ingredient = $recipe->ingredients()->where('position', ((int) ($open['index'] ?? 0)) + 1)->first();
            if ($ingredient === null || $ingredient->referenced_recipe_id !== null) {
                continue;
            }
            $text = trim((string) ($open['text'] ?? $ingredient->display_name ?? $ingredient->raw_text));
            if ($text === '') {
                continue;
            }
            // ── Reuse-Gate (L1, Reuse-Achse aus dem Kreativ-Modus) ────────────────────────────────
            $bestand = (string) ($parameter['bestand'] ?? 'hybrid');
            if ($bestand !== 'komplett_neu') {
                // Bestand zuerst: existiert die Komponente als Basisrezept (Token-Set-Namensgleichheit)?
                // Treffer → Eltern-Zutat binden + Reuse-Sichtzeile (skipped), KEIN neuer Erzeugungs-Lauf.
                // Das ist der eigentliche Fix gegen „datenbank → sehr viele neue Rezepte".
                $reuse = app(\Platform\FoodAlchemist\Services\RecipeService::class)
                    ->findByTokenSetMitReife($team, $text);
                $bestehend = $reuse['recipe'] ?? null;
                // Spec 80 B3: auch der Namens-Treffer muss zur Diät passen (Gemüsefond mit Speck ≠ vegetarisch).
                if ($bestehend !== null && \Platform\FoodAlchemist\Support\BestandsPassung::grund(
                    $text, (string) $bestehend->name,
                    $bestehend->spec_is_vegan !== null ? (bool) $bestehend->spec_is_vegan : null,
                    $bestehend->spec_is_vegetarian !== null ? (bool) $bestehend->spec_is_vegetarian : null,
                    array_values(array_filter((array) ($parameter['diaet_hart'] ?? []), 'is_string')),
                ) !== null) {
                    $bestehend = null;
                }
                if ($bestehend !== null && (int) $bestehend->id !== (int) $recipe->id) {
                    $this->bindIngredient($team, (int) $ingredient->id, (int) $bestehend->id);
                    FoodAlchemistCascadeRunStep::firstOrCreate([
                        'cascade_run_id' => $step->cascade_run_id,
                        'dedupe_key' => 'reuse:' . (int) $bestehend->id,
                    ], [
                        'team_id' => $team->id,
                        'parent_step_id' => $step->id,
                        'depth' => ((int) $step->depth) + 1,
                        'kind' => 'rezept',
                        'label' => Str::limit((string) $bestehend->name, 120),
                        'status' => 'skipped',
                        'ref_type' => 'recipe',
                        'ref_id' => (int) $bestehend->id,
                        'sort' => (int) $ingredient->position,
                        // Der Reifegrad reist MIT: „übernommen" heißt nur dann „fertig", wenn
                        // es das auch ist. Ohne diesen Marker meldete der Lauf „abgeschlossen"
                        // über einem draft mit 0 Schritten (Lauf 65, Step 460).
                        'deferred' => ['reuse' => $this->reuseMarker($reuse)],
                    ]);

                    continue;
                }
            }
            if ($bestand === 'nur_bestand') {
                // »Nur Bestand« (Kreativ-Modus = Datenbank) + kein Treffer: NICHT neu anlegen — aber
                // B3 (2026-08-20, „staged, aber liefern"): NICHT mehr still fallen lassen (das war der
                // Regressionskern — leere Basisrezepte-Stufe). Stattdessen eine SICHTBARE Hardstop-Zeile
                // in die Stufe setzen (status=skipped, kein ref_id, deferred.hardstop-Marker), damit der
                // Mensch sieht: „die DB hat dafür nichts — Bestandsrezept wählen oder Modus wechseln".
                // Dependency registrieren, damit die menschliche Auswahl später an die Eltern-Zutat bindet.
                $hardstopStep = FoodAlchemistCascadeRunStep::firstOrCreate([
                    'cascade_run_id' => $step->cascade_run_id,
                    'dedupe_key' => 'hardstop:' . mb_strtolower($text),
                ], [
                    'team_id' => $team->id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit($text, 120),
                    'status' => 'skipped',
                    'deferred' => ['hardstop' => [
                        'reason' => 'nur_bestand_kein_treffer',
                        'text' => $text,
                        'ingredient_id' => (int) $ingredient->id,
                    ]],
                    'sort' => (int) $ingredient->position,
                ]);
                FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                    'team_id' => $team->id,
                    'cascade_run_id' => $step->cascade_run_id,
                    'parent_step_id' => $step->id,
                    'ingredient_id' => $ingredient->id,
                ], ['child_step_id' => $hardstopStep->id]);

                continue;
            }
            $dedupe = hash('sha256', mb_strtolower($text) . '|' . json_encode([
                $parameter['convenience'] ?? null, $parameter['frische'] ?? null,
                // Bio + Niveau: kanonisch heißen die Keys `bio`/`level` — der alte `niveau`-Read war immer
                // null (Dead-Read), sodass zwei Läufe, die sich NUR im Niveau unterschieden, denselben
                // dedupe_key trugen. Fallback auf `niveau` erhält Altverhalten, falls der Key doch mal kommt.
                $parameter['bio'] ?? null, $parameter['level'] ?? $parameter['niveau'] ?? null,
            ]));

            $child = DB::transaction(function () use ($team, $step, $ingredient, $text, $dedupe) {
                $existing = FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                    ->where('dedupe_key', $dedupe)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                return FoodAlchemistCascadeRunStep::create([
                    'team_id' => $team->id,
                    'cascade_run_id' => $step->cascade_run_id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit($text, 120),
                    'dedupe_key' => $dedupe,
                    'status' => 'geplant',
                    'sort' => (int) $ingredient->position,
                ]);
            });

            FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                'team_id' => $team->id,
                'cascade_run_id' => $step->cascade_run_id,
                'parent_step_id' => $step->id,
                'ingredient_id' => $ingredient->id,
            ], ['child_step_id' => $child->id]);

            $geplant[] = [$child, (int) $ingredient->id, $text];
        }

        return $geplant;
    }

    /**
     * Reuse-Sichtbarkeit (Beobachtung Dominique 2026-08-14): die vom Generator DIREKT verdrahteten
     * Sub-Rezepte (die 📖-Referenzen in der Zutatenliste) erscheinen als eigene Zeile der
     * Basisrezepte-Stufe — Status `skipped` (Reuse-Treffer: nichts zu erzeugen, nur zu prüfen), mit
     * Sprung aufs echte Rezept. Rein informativ: kein Job, keine Dependency, und das referenzierte
     * Bestands-Rezept wird NIE angetastet. Fail-open — eine Sicht-Zeile darf keine Generierung kippen.
     */
    private function spiegleReuseKinder(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe): void
    {
        if (! in_array($step->kind, ['gericht', 'rezept'], true)) {
            return;
        }
        try {
            $zeilen = $recipe->ingredients()->whereNotNull('referenced_recipe_id')
                ->with('referencedRecipe:id,name')->orderBy('position')->get();
            $svc = app(\Platform\FoodAlchemist\Services\RecipeService::class);
            foreach ($zeilen as $z) {
                if ((int) $z->referenced_recipe_id === (int) $recipe->id) {
                    continue;   // Selbstbezug kann nie eine eigene Stufe sein
                }
                // Auch der Spiegel-Pfad trägt den Reifegrad — sonst zeigt die Zeile
                // „übernommen" für ein hohles Bestands-Rezept (s. planChildren).
                $ziel = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $z->referenced_recipe_id);
                $marker = $ziel === null ? null : $this->reuseMarker([
                    'recipe' => $ziel,
                    'eigen' => (int) ($ziel->team_id ?? 0) === (int) $team->id,
                ] + $svc->reifegrad($ziel));
                FoodAlchemistCascadeRunStep::firstOrCreate([
                    'cascade_run_id' => $step->cascade_run_id,
                    'dedupe_key' => 'reuse:' . (int) $z->referenced_recipe_id,
                ], [
                    'team_id' => $team->id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit((string) ($z->referencedRecipe?->name ?: ($z->display_name ?: $z->raw_text)), 120),
                    'status' => 'skipped',
                    'ref_type' => 'recipe',
                    'ref_id' => (int) $z->referenced_recipe_id,
                    'sort' => (int) $z->position,
                    'deferred' => $marker === null ? null : ['reuse' => $marker],
                ]);
            }
        } catch (\Throwable) {
            // Parallel angelegt (dedupe-Unique) oder Zeile weg — Sichtbarkeit ist kein Blocker.
        }
    }

    /**
     * Der Reuse-Marker, der am Step landet (`deferred.reuse`). Eine Struktur, zwei Pfade
     * (planChildren + spiegleReuseKinder), damit UI, Zähler und MCP dasselbe lesen.
     *
     * `eigen` entscheidet, ob eine Lücke automatisch geschlossen werden DARF: ein
     * team-eigener Entwurf ist ein unfertiges Stück dieses Hauses, ein Referenz-Rezept aus
     * einem übergeordneten Team ist fremdes, lebendes Gut — daran schreibt die Kaskade nicht.
     *
     * @param  array{recipe: FoodAlchemistRecipe, reif: bool, eigen?: bool, luecken: list<string>}  $reuse
     * @return array{reif: bool, eigen: bool, luecken: list<string>, status: string}
     */
    private function reuseMarker(array $reuse): array
    {
        return [
            'reif' => (bool) ($reuse['reif'] ?? false),
            'eigen' => (bool) ($reuse['eigen'] ?? false),
            'luecken' => array_values((array) ($reuse['luecken'] ?? [])),
            'status' => (string) ($reuse['recipe']->status?->value ?? ''),
        ];
    }

    private function bindCompletedChild(Team $team, FoodAlchemistCascadeRunStep $child, FoodAlchemistRecipe $recipe): void
    {
        FoodAlchemistCascadeRecipeDependency::where('child_step_id', $child->id)->get()
            ->each(fn ($dependency) => $this->bindIngredient($team, (int) $dependency->ingredient_id, (int) $recipe->id));
    }

    private function bindIngredient(Team $team, int $ingredientId, int $recipeId): void
    {
        $ingredient = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::find($ingredientId);
        if ($ingredient === null || $ingredient->gp_id !== null || $ingredient->referenced_recipe_id !== null) {
            return;
        }
        if (! app(RecipeRecomputeService::class)->pruefeVerknuepfung((int) $ingredient->recipe_id, $recipeId)['erlaubt']) {
            return;
        }
        $ingredient->update([
            'referenced_recipe_id' => $recipeId,
            'match_method' => 'recipe_ref',
            'match_confidence' => null,
        ]);
        app(RecipeRecomputeService::class)->recomputeAndPropagate((int) $ingredient->recipe_id);
    }
}
