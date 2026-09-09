<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\Wissensart;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M5-06 / GL-13: Wissenskontext-Beschaffung für KI-Calls — 1:1-Port von
 * vault_context.rs, Quelle sind die foodalchemist_knowledge_*-Tabellen (D4)
 * statt Disk-Reads. Liefert FAKTEN-Wissen als Teil des User-Prompts; die
 * Hüllen (GL-06) liefern Verhalten als systemInstruction — additiv, nie
 * redundant.
 *
 * Routing pro Feature kommt aus foodalchemist_knowledge_routings (Tabelle 4.1
 * als Daten): cross_cutting/always = die 7 Always-Load-Einheiten, domain/
 * discovery = zweistufige Domain-Discovery (Alias-Mapping, Filename-Fallback),
 * pairing/discovery = kompakter FLAVOR-PAIRING-Block (nur Partner-NAMEN, kein
 * Prosa-Volltext), pairing/grounding = Doku-Auszüge je Hauptzutat-Slug.
 * Fehlende Quelle = leerer Kontext, nie Fehler (Invariante 6).
 *
 * ── MANDANTEN-INVARIANTE (Produktentscheid Dominique, 2026-09-02) ─────────────
 * DER WISSENS-KORPUS IST GEMEINSAM. Das Retrieval liest ihn ABSICHTLICH ohne
 * team_id-Filter: er ist von BHG kuratiert und trägt den Generator für alle Teams.
 * Gescopet ist die KURATION (Schreiben), nicht das Lesen — `KnowledgeService`
 * stempelt beim Anlegen ein team_id und `TeamScope::owns()` lässt nur den Eigentümer
 * ändern. Kurz: alle lesen, nur der Eigentümer schreibt.
 *
 * WARNUNG AN JEDEN, DER HIER EINEN FILTER EINBAUEN WILL — mit Zahlen von demo
 * (2026-09-02, 598 aktive Docs):
 *   team_id NULL (global):     6 Docs =   1,0 %
 *   team_id 6   (Kurator):   592 Docs =  99,0 %  (3,2 Mio Zeichen)
 * Ein `applyVisible`-Filter ist für Team 6 ein No-op (598 → 598) und lässt für
 * JEDES andere Team sowie für jeden Console-Lauf ohne `--team` genau 6 von 598 Docs
 * übrig — der Korpus fällt auf 1 % zusammen und die Generierung verliert ihr
 * Fundament, OHNE dass ein Test rot wird (die Suite seedet team_id NULL). Genau
 * dieser Weg wurde am 2026-09-02 gebaut und wieder verworfen, nachdem die Messung
 * ihn widerlegt hat.
 *
 * Ein echter Mandanten-Schnitt ist deshalb KEINE Code-Änderung, sondern zuerst eine
 * DATEN-Entscheidung: entweder wandert der kuratierte Bestand auf `team_id = NULL`,
 * oder Kundenteams werden über `teams.parent_team_id` Nachfahren des BHG-Teams
 * (heute ist dieser Wert bei allen 8 Teams NULL, es gibt also gar keine Hierarchie).
 * Erst danach greift `TeamScope::applyVisible` sinnvoll. Gepinnt in
 * tests/Feature/WissenKorpusGemeinsamTest.php.
 */
class KnowledgeContextService
{
    private bool $artenRoutingAktiv = false;
    private array $geltungsParameter = [];
    private ?array $geltungsIds = null;
    private array $achsenPflichtFiles = [];

    /**
     * Invariante 1: diese 7 gehen bei `cross_cutting:always`-Features IMMER mit (Reihenfolge = Ist).
     *
     * ⚠ LEGACY-DEFAULT seit Spec 50 Welle 2 (2026-09-06): alle 7 Originale sind auf demo
     * DEAKTIVIERT (in Ein-Thema-Splits zerlegt), und kein live geroutetes Feature nutzt den
     * Default mehr — `concept.wording` + `foodbook.kundentext` überschreiben ihn per config
     * `ai.cross_cutting_slugs` mit Split-Slugs; die Generatoren laden cross_cutting per
     * discovery. Die Konstante bleibt, weil Tests + Wissens-Browser daran hängen. Ein Feature,
     * das künftig ohne Override auf `always` geroutet wird, bekäme hier still NICHTS —
     * `wissen-steuerdaten-w0 --verify` (Cross-Cutting-Wächter) macht das sichtbar.
     */
    public const ALWAYS_LOAD_CROSS_CUTTING = [
        'substitutionen', 'saisonkalender', 'synonyme', 'sauce_mutterstrukturen',
        'mengen_defaults', 'techniken', 'bruehen_fonds',
    ];

    /**
     * W0-4 — Pro-Doc-Deckel für die beiden Kategorien, die ihre Routing-Zeile NICHT lesen.
     * `cross_cutting:always` und `domain:discovery` werten `max_chars_per_doc`/`max_docs`
     * nur als Boolean-Gate aus (die Routing-Werte laufen ins Leere), sie sind also
     * ausschließlich hier steuerbar. 7 Cross-Cutting-Dossiers à 4000 Z. waren allein
     * 28.000 Z. — bei Features ohne Gesamtbudget (recipe.steps) ungedeckelt.
     */
    public const CROSS_CUTTING_TRUNCATE_CHARS = 1800;

    public const DOMAIN_TRUNCATE_CHARS = 2500;

    public const DOMAIN_TOP_K = 4;

    public const PAIRING_TOP_K = 3;

    public const MAX_PARTNERS = 28;

    /**
     * Pro-Doc-Deckel für achsen-aufgelöstes Wissen (Anlass-Playbook, Segment-Profil).
     * Bewusst knapp: das sind PRÄZISE Treffer, die den unpräzisen Discovery-Treffern
     * Budget wegnehmen — das ist der Sinn der Sache, aber es darf sie nicht verdrängen.
     */
    private const ACHSEN_TRUNCATE_CHARS = 2400;

    /**
     * W0-6 — Herkunft je ausgewähltem Doc-Slug: `via` (lexical|alias|semantic|hybrid), `score`,
     * `chars` (Doc-Größe) und `sent` (was nach dem Pro-Doc-Deckel wirklich rausging).
     * Wird je contextFor()-Lauf zurückgesetzt und mit dem Block zurückgegeben.
     *
     * @var array<string, array{score: float|null, via: string, chars?: int, sent?: int}>
     */
    private array $herkunft = [];

    /**
     * B1 — Slugs, die ein VORHERIGER contextFor-Aufruf desselben Prompts schon geliefert hat.
     *
     * Nötig, weil Aufrufer Wissensblöcke KOMPONIEREN: `IdeenService` baut für
     * `foodbook.kapitel_ideen` zwei Blöcke (concept.plan + concept.brief_geruest) und verkettet
     * sie. Gemessen am 2026-09-02: 29.028 + 10.028 = 39.056 Zeichen, darin `kalkulation_event_angebot`
     * DOPPELT — der Kanal `geschaeftsmodell` ist für beide Features geroutet. Das Budget lebt pro
     * Feature; die Komposition kannte es nicht.
     *
     * @var list<string>
     */
    private array $ausgeschlossen = [];

    /**
     * Rezept-Calls laufen in einer Kaskade (Gericht + Basisrezepte). Ohne einen featureweiten
     * Deckel addiert jede geroutete Discovery-Kategorie ihr eigenes Top-K und aus einem gezielten
     * Abruf werden 30–40 Volltext-Dossiers pro Call. Der Deckel gilt nur für den eigentlichen
     * Rezeptgenerator; Planungs-/Concept-Features behalten ihre eigenen Budgets.
     */
    public const RECIPE_MAX_CHARS_PER_DOC = 2400;

    public const RECIPE_MAX_KNOWLEDGE_CHARS = 48000; // Kompatibilitätskonstante; Laufzeit: KnowledgeBudget

    /**
     * W0-5 — Gesamtbudget für JEDES Feature.
     *
     * Bis Welle 0 hatte nur `ai_generate_recipe` einen featureweiten Deckel; alle anderen
     * (recipe.steps, concept.plan, foodbook.plan, foodbook.kapitel_ideen, format.grundgeruest,
     * *.ueberarbeiten, recipe.eigenschaften) summierten ihre Pro-Doc-Caps unbegrenzt auf —
     * gemessen bis 23.603 Tk/Call bei foodbook.kapitel_ideen. Feature-Overrides in
     * config('foodalchemist.ai.knowledge_budget'), damit sie diff- und PR-fähig bleiben
     * statt als unversionierte Handdaten in einer Tabelle zu driften (wie die Routings).
     */
    public const MAX_KNOWLEDGE_CHARS_DEFAULT = KnowledgeBudget::DEFAULT_CHARS;

    /** Spec 08 P6: Fallback-Budget für `concept:always`, wenn die Routing-Zeile nichts vorgibt. */
    public const CONCEPT_MAX_DOCS = 4;

    public const CONCEPT_TRUNCATE_CHARS = 4000;

    /** Etappe 1: Fallback-Budget für `regelwerk:always` — fasst die §2–§4-Region (~6,5k) mit Reserve. */
    public const REGELWERK_TRUNCATE_CHARS = 7000;

    /**
     * Haupt-Einstieg (Pseudocode §3): baut den Wissens-Block für ein KI-Feature.
     *
     * @param  list<string>  $hauptzutatSlugs  nur für Grounding-Features (ai_suggest_pairings, ai_infer_ankers)
     * @return array{block: string, files_used: list<string>, files_dropped: list<string>, used_by_category: array<string, list<string>>, total_chars: int, built_chars: int, dropped_chars: int, herkunft: array<string, array<string, mixed>>}
     */
    public function contextFor(?Team $team, string $feature, string $description, ?string $stil = null, array $hauptzutatSlugs = [], array $params = []): array
    {
        $allRouting = $this->routingZeilen($feature);
        $artRouting = $allRouting->filter(fn ($r) => ! empty($r->art));
        $this->artenRoutingAktiv = $artRouting->isNotEmpty();
        $this->geltungsParameter = $params;
        $this->geltungsIds = null;
        $this->achsenPflichtFiles = [];
        $routing = $allRouting->filter(fn ($r) => empty($r->art))
            ->when(! empty($params['_required_only']), fn ($rows) => $rows->where('mode', 'always'))
            ->keyBy(fn ($r) => $r->category . ':' . $r->mode);

        $filesUsed = [];
        $usedByCategory = [];
        $parts = [];
        $this->herkunft = [];
        // B1: Slugs, die ein vorheriger Block schon geliefert hat (Versions-Suffix @vN weg).
        $this->ausgeschlossen = [];
        foreach ((array) ($params['_exclude_slugs'] ?? []) as $roh) {
            $slug = preg_replace('/@v\d+$/', '', trim((string) $roh));
            if ($slug !== '') {
                $this->ausgeschlossen[] = $slug;
            }
        }
        // Spec 50 Welle 2 (2026-09-06): `_kanon_prompt_key` = der Prompt-Key, dessen KANON der
        // Gateway vollständig reserviert (bei Budgetüberschreitung wird abgebrochen,
        // KnowledgeCanonService/AiGatewayService). Diese Dossiers darf das Retrieval nicht ein
        // zweites Mal laden — gemessen: `ai_generate_recipe × cross_cutting discovery 6×8000` zog
        // die mengen_defaults-/geschmacksbalance-Splits erneut. `wenn_platz` bleibt ABSICHTLICH
        // draußen: die können dem Kanon-Budget zum Opfer fallen und sollen dann noch findbar sein.
        // Der Aufrufer kennt nur seinen Prompt-Key; die Auflösung passiert hier, an EINEM Ort.
        $kanonKey = KnowledgeBudget::promptKey(trim((string) ($params['_kanon_prompt_key'] ?? $feature)));
        $kanonPflichtChars = 0;
        if ($kanonKey !== '' && $team !== null) {
            $kanonDocs = app(KnowledgeCanonService::class)->documentsFor('prompt_key', $kanonKey, $team);
            $kanonPflichtChars = app(AiGatewayService::class)->kanonPflichtZeichen($kanonDocs);
            $kanonSlugs = $kanonDocs->where('mode', 'pflicht')->pluck('slug')->map(static fn ($s) => (string) $s)->all();
            $this->ausgeschlossen = array_merge($this->ausgeschlossen, $kanonSlugs);
        }
        $this->ausgeschlossen = array_values(array_unique($this->ausgeschlossen));
        $recipeBudget = in_array($feature, self::REZEPT_BUDGET_KEYS, true);
        $scopeSlugs = $this->knowledgeScopeSlugs($params['_knowledge_scope'] ?? []);

        // Kontext-Inspektor (2026-08-07): jedem Block das Delta an $filesUsed SEINER Kategorie
        // zuordnen — additiv, ohne die Block-Methoden-Signaturen anzufassen. Speist die UI-
        // Transparenz „auf welches Wissen greift der Generator" (gruppiert je Kanal). Verändert
        // $filesUsed/$block nicht → golden-safe.
        $snap = function (string $cat, int $before) use (&$filesUsed, &$usedByCategory): void {
            $delta = array_slice($filesUsed, $before);
            if ($delta !== []) {
                $usedByCategory[$cat] = array_values(array_merge($usedByCategory[$cat] ?? [], $delta));
            }
        };

        // ── 0. CONCEPTING-WISSEN (Spec 08 P6, nur Planungs-Features) ──
        // Steht bewusst VOR dem Food-Wissen: es sagt, wie ein Konzept gebaut ist,
        // und rahmt damit die Zutaten-Ebene darunter. Leere Kategorie ⇒ kein Block.
        if (($r = $routing->get('concept:always')) !== null) {
            $before = count($filesUsed);
            $concept = $this->conceptBlock($team, 
                (int) ($r->max_docs ?: self::CONCEPT_MAX_DOCS),
                (int) ($r->max_chars_per_doc ?: self::CONCEPT_TRUNCATE_CHARS),
                $filesUsed
            );
            if ($concept !== null) {
                $parts[] = $concept;
            }
            $snap('concept', $before);
        }

        // ── 0b. TREND-WISSEN (Trendradar) — discovery, thematisch zur Beschreibung ──
        // Steht bei den rahmenden Blöcken: aktuelle Trends sagen, WAS gerade relevant
        // ist (Anlass/Inspiration), bevor die Zutaten-Ebene darunter greift.
        if (($r = $routing->get('trend:discovery')) !== null) {
            $before = count($filesUsed);
            $trend = $this->trendBlock($team, 
                (int) ($r->max_docs ?: 5),
                (int) ($r->max_chars_per_doc ?: 1500),
                $description,
                $filesUsed
            );
            if ($trend !== null) {
                $parts[] = $trend;
            }
            $snap('trend', $before);
        }

        // ── 0c. REGELWERK: ENTFERNT (Spec 52 · F4, 2026-09-08) ────────────────────────
        //
        // Hier stand der `regelwerk:always`-Zweig mit `regelwerkBlock()`. Er wählte das
        // Regelwerk per Slug-Muster (`REGELWERK_SLUG_LIKE`) und nahm davon
        // `orderBy('slug')->first()` — bei `%basisrezept%` also EINES von rund zwanzig
        // §-Dossiers, alphabetisch. Das ist keine Auswahl, das ist ein Los.
        //
        // Verbindliches Wissen kommt seit Spec 50 aus dem KANON, und seit F2 ausschliesslich
        // von dort. Als letztes lebendes Feature auf diesem Pfad stand `foodbook.grundgeruest`
        // (`%foodbook%` traf genau EIN Dossier, die Auswahl war dort also zufällig richtig);
        // es hat mit F4 eine Kanon-Zeile bekommen und sein Routing steht auf `none`.
        //
        // Das Regelwerk als KATEGORIE bleibt normal routbar — `recipe.eigenschaften`,
        // `recipe.review`, `*.ueberarbeiten` ziehen es per `discovery` über die generische
        // Auswahl weiter unten. Weggefallen ist nur der dedizierte `always`-Sonderweg.

        // ── 0d. ACHSEN-WISSEN: Anlass-Playbook + Segment-Profil, deterministisch aufgelöst ──
        // Hoch priorisiert (direkt nach dem Regelwerk): das sind exakte Treffer aus den
        // Leitplanken, keine Ratekandidaten. Sie sollen dem Fuzzy-Retrieval Budget wegnehmen.
        $before = count($filesUsed);
        if (($achsen = $this->achsenBlock($team, $params, $filesUsed)) !== null) {
            $parts[] = $achsen;
            $snap('achse', $before);
        }

        // ── 1. VAULT-WISSEN: Cross-Cutting (always) + Domains (discovery) ──
        $blocks = [];
        if ($routing->has('cross_cutting:always')) {
            $before = count($filesUsed);
            $crossDocs = $this->crossCuttingDocs($team, $feature);
            foreach ($crossDocs as $doc) {
                $maxChars = $recipeBudget ? self::RECIPE_MAX_CHARS_PER_DOC : self::CROSS_CUTTING_TRUNCATE_CHARS;
                $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => "## CROSS_CUTTING: {$doc->slug}\n\n" . (string) $doc->content_md];
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
            $snap('cross_cutting', $before);
        }
        if ($routing->has('domain:discovery')) {
            $before = count($filesUsed);
            $domainDocs = $this->discoverDomains($team, $this->discoveryQuery($description, $params), $scopeSlugs,
                (int) ($routing->get('domain:discovery')->max_docs ?: self::DOMAIN_TOP_K));
            foreach ($domainDocs as $doc) {
                $maxChars = $recipeBudget ? self::RECIPE_MAX_CHARS_PER_DOC : self::DOMAIN_TRUNCATE_CHARS;
                $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => "## DOMAIN: {$doc->slug}\n\n" . (string) $doc->content_md];
                $this->herkunft[$doc->slug]['sent'] = mb_strlen((string) $doc->content_md);
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
            $snap('domain', $before);
        }
        if ($blocks !== []) {
            $parts[] = new KnowledgeContextBlock("# VAULT-WISSEN (Catering-Wissensbasis)\n\n"
                . "Folgende Domain- und Cross-Cutting-Files aus der Wissensbasis sind für diesen Generator-Call relevant.\n"
                . "Nutze sie als Souschef-Wissen: klassische Verhältnisse, Substitutionen, Synonyme, Sub-Rezept-Patterns.\n\n",
                $blocks);
        }

        // ── 2. FLAVOR-PAIRING-Block (Generator-Features; SQL-Anker-Graph bleibt primär, GL-10) ──
        if ($routing->has('pairing:discovery')) {
            $before = count($filesUsed);
            $pairing = $this->pairingBlock(
                    $this->discoveryQuery($description, $params), $stil, $filesUsed,
                    self::PAIRING_TOP_K,
                );
            if ($pairing !== null) {
                $parts[] = $pairing;
            }
            $snap('pairing', $before);
        }

        // ── 3. Pairing-Doku-Grounding (Anker-/Pairing-Inferenz) ──
        if (($r = $routing->get('pairing:grounding')) !== null) {
            $before = count($filesUsed);
            $parts[] = $this->groundingBlock($team, $hauptzutatSlugs, (int) $r->max_docs, (int) $r->max_chars_per_doc, $filesUsed);
            $snap('pairing_grounding', $before);
        }

        // ── 3b. NIVEAU-WISSEN (Spec 37, TYP-ABHÄNGIG) ──
        // Bewusst NICHT im generischen discovery-Loop unten: Basisrezept-Doc (…basis…) und Teller-Doc
        // tragen denselben Level-Token (haute/gehoben/klassisch) → fuzzy-Ranking wäre nicht eindeutig.
        // Deterministisch: der Typ (params['rezept_typ']) wählt die Slug-Familie, der Level die Stufe.
        if (($r = $routing->get('niveau:discovery')) !== null) {
            $before = count($filesUsed);
            $niveau = $this->niveauBlock($team, 
                $recipeBudget
                    ? min(self::RECIPE_MAX_CHARS_PER_DOC, (int) ($r->max_chars_per_doc ?: 3000))
                    : (int) ($r->max_chars_per_doc ?: 3000),
                (string) ($params['niveau'] ?? $params['level'] ?? ''),
                (string) ($params['rezept_typ'] ?? 'basisrezept'),
                $filesUsed
            );
            if ($niveau !== null) {
                $parts[] = $niveau;
            }
            $snap('niveau', $before);
        }

        // ── 4. GENERISCHE discovery-Kategorien (S1 Skalierbarkeit) ──
        // Jede als `discovery` geroutete Kategorie OHNE Spezial-Handler (domain/pairing/
        // trend/concept haben eigene, oben) wird hier generisch per Beschreibung + Leitplanken-
        // Werten (Niveau/Sektor) entdeckt und gedeckelt geladen. Damit skaliert die Wissensbasis:
        // eine neue Kategorie braucht nur eine Routing-Zeile, KEINEN Service-Code. Bestehende
        // Kategorien werden übersprungen → Verhalten für sie byte-identisch (golden-safe).
        $spezial = ['domain', 'pairing', 'trend', 'concept', 'niveau'];   // niveau (3b) hat eigenen dedizierten Selektor. cross_cutting + regelwerk ab 2026-08-27 über generische Discovery (Dossier-Split): die dedizierten Blöcke oben feuern nur bei mode=always und werden bei routing=discovery automatisch übersprungen, crossCuttingDocs()/regelwerkBlock() sind dann ungenutzt.
        $leitplankenQuery = $this->discoveryQuery($description, $params);
        $discoveryRoutings = $routing->filter(
            fn ($r) => $r->mode === 'discovery' && ! in_array($r->category, $spezial, true)
        );
        if ($recipeBudget) {
            $prioritaet = array_flip([
                'regelwerk', 'kueche', 'kreativ_input', 'referenzgericht',
                'prasentation_service', 'ernaehrung', 'weltkueche', 'signatur_kuechen',
                'cross_cutting',
            ]);
            $discoveryRoutings = $discoveryRoutings->sortBy(
                fn ($r) => $prioritaet[(string) $r->category] ?? 100
            );
        }
        foreach ($discoveryRoutings as $r) {
            $before = count($filesUsed);
            $category = (string) $r->category;
            $topK = (int) ($r->max_docs ?: 3);
            $allowed = $scopeSlugs !== [] && ! in_array($category, ['regelwerk', 'niveau', 'cross_cutting'], true)
                ? $scopeSlugs
                : [];
            $generic = $this->discoverGenericBlock($team, 
                $category, $leitplankenQuery, $topK,
                $recipeBudget
                    ? min(self::RECIPE_MAX_CHARS_PER_DOC, (int) ($r->max_chars_per_doc ?: 3000))
                    : (int) ($r->max_chars_per_doc ?: 3000),
                $filesUsed, $allowed
            );
            if ($generic !== null) {
                $parts[] = $generic;
            }
            $snap((string) $r->category, $before);
        }

        // ── 4b. GENERISCHE always-Kategorien (2026-08-27, Dominique): Referenz-Dossiers, die
        // rezept-UNABHÄNGIG immer gelten (z.B. produktion_kapazitat = Produktions-Zeitkennwerte),
        // lassen sich NICHT per discovery holen — ihr Slug überlappt die Rezept-Beschreibung nicht
        // (Jaccard=0). Darum: als `always` geroutete Kategorien OHNE dedizierten always-Handler
        // (concept/regelwerk/cross_cutting feuern oben) hier UNBEDINGT laden (alle aktiven Docs der
        // Kategorie, slug-sortiert deterministisch, gedeckelt). Golden-safe: greift nur bei Routing-Zeile.
        $alwaysSpezial = ['concept', 'regelwerk', 'cross_cutting'];
        foreach ($routing as $r) {
            if ($r->mode !== 'always' || in_array($r->category, $alwaysSpezial, true)) {
                continue;
            }
            $before = count($filesUsed);
            $immer = $this->alwaysCategoryBlock($team, 
                (string) $r->category,
                (int) ($r->max_docs ?: 2),
                $recipeBudget
                    ? min(self::RECIPE_MAX_CHARS_PER_DOC, (int) ($r->max_chars_per_doc ?: 4000))
                    : (int) ($r->max_chars_per_doc ?: 4000),
                $filesUsed
            );
            if ($immer !== null) {
                $parts[] = $immer;
            }
            $snap((string) $r->category, $before);
        }

        $datenwerkFiles = [];
        $datenwerkErgebnis = null;
        foreach ($artRouting as $route) {
            $before = count($filesUsed);
            if ($route->mode === 'discovery' && empty($params['_required_only'])) {
                $part = $this->discoverGenericBlock($team, $route->art, $leitplankenQuery,
                    (int) ($route->max_docs ?: 3), (int) ($route->max_chars_per_doc ?: 3000), $filesUsed, $scopeSlugs, $route->art);
                if ($part !== null) $parts[] = $part;
            } elseif ($route->art === 'datenwerk' && $route->mode === 'resolve') {
                $base = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team));
                $datenwerkErgebnis = app(\Platform\FoodAlchemist\Services\Knowledge\DatenwerkResolver::class)->resolve($base, $params);
                $entries = [];
                foreach ($datenwerkErgebnis['ergebnisse'] as $result) {
                    foreach ($result['kandidaten'] as $candidate) {
                        $file = $candidate['dossier'];
                        $entries[] = ['file' => $file, 'text' => '## '.($result['status'] === 'widerspruch' ? 'WIDERSPRUCH — keinen Wert automatisch verwenden: ' : 'DATENWERT: ')
                            .$result['kennzahl']."\n".json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                        $filesUsed[] = $file;
                        $datenwerkFiles[] = $file;
                        $this->herkunft[explode('@v', $file)[0]] = ['via' => 'datenwerk:resolve', 'status' => $result['status']];
                    }
                }
                $grouped = [];
                foreach ($entries as $entry) {
                    if (isset($grouped[$entry['file']])) $grouped[$entry['file']]['text'] .= "\n\n".$entry['text'];
                    else $grouped[$entry['file']] = $entry;
                }
                $entries = array_values($grouped);
                foreach ($entries as $entry) {
                    $slug = explode('@v', $entry['file'])[0];
                    $this->herkunft[$slug]['score'] = null;
                    $this->herkunft[$slug]['chars'] = mb_strlen($entry['text']);
                    $this->herkunft[$slug]['sent'] = mb_strlen($entry['text']);
                }
                $filesUsed = array_values(array_unique($filesUsed));
                foreach ($datenwerkErgebnis['luecken'] as $gap) $entries[] = ['file' => null, 'required' => true, 'text' => 'DATENLÜCKE — keinen Wert erfinden: '.json_encode($gap, JSON_UNESCAPED_UNICODE)];
                $parts[] = new KnowledgeContextBlock("# STRUKTURIERTE DATENWERKE\n\n", $entries);
            }
            $snap('art:'.$route->art, $before);
        }

        $gebaut = mb_strlen(KnowledgeContextBlock::join($parts));
        $requiredFiles = [...$datenwerkFiles, ...$this->achsenPflichtFiles];
        foreach ($routing as $row) {
            if ($row->mode === 'always') {
                array_push($requiredFiles, ...($usedByCategory[(string) $row->category] ?? []));
            }
        }
        $budget = KnowledgeBudget::forKey($kanonKey);
        if ($kanonPflichtChars > $budget) throw new KnowledgeBudgetExceeded($kanonKey, $kanonPflichtChars, $budget);
        try {
            // Pflichtkanon reservieren, bevor die Retrieval-Auswahl optionale Quellen aufnimmt.
            $restBudget = $budget - $kanonPflichtChars;
            $selection = KnowledgeContextBlock::assemble($parts, $requiredFiles, $restBudget, $kanonKey);
            if (($override = (int) ($params['_max_chars'] ?? 0)) > 0 && $override < $restBudget) {
                $selection = KnowledgeContextBlock::assemble($parts, $requiredFiles, $override, $kanonKey, callerOverride: true);
            }
        } catch (KnowledgeBudgetExceeded $exception) {
            throw new KnowledgeBudgetExceeded($kanonKey, $kanonPflichtChars + $exception->requiredChars, $budget);
        }
        $block = $selection['block'];
        $sentFiles = $selection['files_used'];
        $droppedFiles = array_values(array_diff($filesUsed, $sentFiles));
        $filesUsed = $sentFiles;
        foreach ($usedByCategory as $category => $files) {
            $usedByCategory[$category] = array_values(array_intersect($files, $sentFiles));
            if ($usedByCategory[$category] === []) {
                unset($usedByCategory[$category]);
            }
        }
        $sentSlugs = array_fill_keys(array_map(static fn ($file) => preg_replace('/@v\d+$/', '', $file), $sentFiles), true);
        foreach ($this->herkunft as $slug => &$source) {
            if (! isset($sentSlugs[$slug])) {
                $source['sent'] = 0;
            }
        }
        unset($source);

        return [
            'datenwerk' => $datenwerkErgebnis,
            'block' => $block,
            'files_used' => $filesUsed,
            'files_dropped' => $droppedFiles,
            'used_by_category' => $usedByCategory,
            'total_chars' => mb_strlen($block),
            // W0-0/W0-6: was gebaut und dann verworfen wurde. Ohne diese Zahl ist ein
            // Budget-Schnitt nicht von „Wissen fehlt jetzt" zu unterscheiden.
            // files_used nennt ausschließlich Quellen im tatsächlich gesendeten Block.
            'built_chars' => $gebaut,
            'required_chars' => $selection['required_chars'] + $kanonPflichtChars,
            'kanon_required_chars' => $kanonPflichtChars,
            'knowledge_budget' => $budget,
            'dropped_chars' => max(0, $gebaut - mb_strlen($block)),
            'herkunft' => $this->herkunft,
        ];
    }

    /**
     * ACHSEN-AUFLÖSUNG — deterministisches Nachschlagen statt Ranking.
     *
     * Für achsen-gebundenes Wissen ist Retrieval das falsche Werkzeug: `occasion=dinner`
     * → `event_playbook_gala` ist ein Join. Das Slug-Token-Ranking traf hier daneben, und
     * `event_playbook`/`segment` waren für Gerichte gar nicht geroutet — 20 Docs
     * Catering-Fachwissen (Anlass-Playbooks, Segment-Profile) waren unerreichbar.
     *
     * Gate ist die Anwesenheit des Parameters, nicht eine Routing-Zeile: ein Basisrezept
     * hat kein `occasion` und löst hier nichts aus. Trifft die Map einen Wert nicht
     * (z. B. `sektor=restaurant`, für das kein Dossier existiert), bleibt der Kanal
     * bewusst leer statt auf ein fremdes Profil auszuweichen.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $filesUsed
     */
    /**
     * Sichtbarkeits-Filter für die ROHEN Doc-Queries — HINTER EINEM SCHALTER.
     *
     * Dominiques Modell (2026-09-03): „das Wissen ist global, damit die Generatoren laufen;
     * ein neuer Nutzer bekommt es leer und kann für sich Wissen hinterlegen, das nur für
     * sein Team und Kinder gilt." Das ist wörtlich `TeamScope::applyVisible` — NULL ODER
     * eigene Ancestry.
     *
     * WARUM DER SCHALTER: der Filter ist erst richtig, wenn die DATEN es sind. Auf demo
     * liegen 818 kuratierte Dossiers unter `team_id = 6` statt NULL — gemessen am
     * 2026-09-03 wären mit Filter für Team 6 alles unverändert (598 → 598), für JEDES
     * andere Team und für jeden Console-Lauf ohne Team aber nur 6 von 598 übrig. Der Korpus
     * fiele auf 1 %, ohne dass ein Test rot wird (die Suite seedet mit team_id NULL).
     * Genau dieser Weg wurde in der Nacht gebaut, gemessen und wieder verworfen.
     *
     * Schalter AUS (Default) = byte-identisch zu heute: der Closure ist die Identität.
     * Schalter AN = Dominiques Modell. Der Flip ist eine ENV-Zeile, der Rollback dieselbe
     * Zeile zurück — kein Deploy. Reihenfolge: erst Daten (Bestand auf NULL heben ODER
     * `teams.parent_team_id` setzen), dann Flip.
     *
     * Bei $team === null bleibt genau der globale Seed übrig — nicht alles, nicht nichts.
     */
    private function nurSichtbar(?Team $team, string $spalte = 'team_id'): \Closure
    {
        if (! (bool) config('foodalchemist.knowledge_team_scope', false)) {
            return static fn ($q) => $q;                         // Schalter aus: keine Änderung
        }

        return static fn ($q) => TeamScope::applyVisible($q, $spalte, $team);
    }

    /**
     * Spec 52/H1 — Dossiers, die in einen PROMPT dürfen.
     *
     * Bewusst nicht in `nurSichtbar()`: `knowledge.SEARCH` und der Wissens-Browser müssen
     * `ablauf`-Dossiers weiter **finden** — genau darüber holen sich Agenten ihre Anleitung
     * (`ablauf.GET`). Nur der Prompt-Bau schliesst sie aus, denn der Generator ruft keine
     * Werkzeuge, er produziert JSON: eine Werkzeug-Reihenfolge wäre dort reines Rauschen.
     *
     * `art IS NULL` bleibt erlaubt — sonst fiele der gesamte, noch nicht eingeordnete Bestand
     * aus jedem Prompt. Einordnen ist eine Kurations-Aufgabe, kein Schalter.
     */
    private function geltendeDokumentIds(?Team $team): array
    {
        return $this->geltungsIds ??= DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))->get(['id', 'geltung'])
            ->filter(fn ($d) => \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::passt(
                \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($d->geltung), $this->geltungsParameter))->pluck('id')->all();
    }

    private function nurFuerPrompt(?Team $team, string $spalte = 'team_id', string $artSpalte = 'art'): \Closure
    {
        $sichtbar = $this->nurSichtbar($team, $spalte);

        $eligible = $this->geltendeDokumentIds($team);
        $idColumn = str_contains($artSpalte, '.') ? substr($artSpalte, 0, strrpos($artSpalte, '.') + 1).'id' : 'id';
        $strict = $this->artenRoutingAktiv;
        return static function ($q) use ($sichtbar, $artSpalte, $strict, $eligible, $idColumn) {
            $sichtbar($q);
            $q->whereIn($idColumn, $eligible);

            if (! Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
                return $q;    // vor der H1-Migration: unveraendert
            }

            return $q->where(fn ($w) => $w->whereNull($artSpalte)
                ->when(! $strict, fn ($w) => $w->orWhereIn($artSpalte, [Wissensart::FACHWISSEN, Wissensart::REFERENZ])));
        };
    }

    /**
     * Spec 52/H2 — Achse → Kandidaten-Slugs, aus dem KANON, mit der Config als Fallback.
     *
     * `datenwerk`-Wissen (Grundsatz A) wird **aufgelöst, nicht gesucht**: ein Standard, der für
     * einen Anlass verbindlich gilt, darf nicht davon abhängen, ob die Suche das passende
     * Dossier unter die ersten drei Treffer bekommt. Dieses Muster gab es schon — es lag nur
     * als hartkodierter Config-Baum da und war damit nicht pflegbar (Grundsatz E).
     *
     * Jetzt: Kanon-Zeilen mit `scope='achse'`, `scope_key='<achse>:<wert>'` gewinnen; hat eine
     * Achse/Wert-Kombination keine Zeile, greift `config('ai.knowledge_axis_map')` unverändert.
     * Achsen-NAMEN kommen aus beiden Quellen, damit eine ganz neue Achse ohne Deploy verdrahtet
     * werden kann.
     *
     * @param array<string, mixed> $params
     * @return array<string, list<string>>
     */
    private function achsenKandidaten(?Team $team, array $params): array
    {
        $params['niveau'] ??= $params['level'] ?? null;
        $map = config('foodalchemist.ai.knowledge_axis_map', []);
        $map = is_array($map) ? $map : [];
        $gepflegt = $team !== null
            ? app(KnowledgeCanonService::class)->achsenBindungen($team)
            : [];

        $achsen = array_values(array_unique(array_merge(
            array_map('strval', array_keys($map)),
            array_map('strval', array_keys($gepflegt)),
        )));

        $gesucht = [];
        foreach ($achsen as $achse) {
            $wert = $params[$achse] ?? null;
            if (! is_string($wert) || trim($wert) === '') {
                continue;
            }
            $wert = trim($wert);

            // Kanon zuerst — eine gepflegte Zeile schlaegt den Config-Default.
            $kandidaten = $gepflegt[$achse][$wert] ?? null;
            if (! is_array($kandidaten) || $kandidaten === []) {
                $kandidaten = (is_array($map[$achse] ?? null) ? ($map[$achse][$wert] ?? null) : null);
            }
            if (is_array($kandidaten) && $kandidaten !== []) {
                $gesucht[$achse] = array_values(array_filter(array_map('strval', $kandidaten)));
            }
        }

        return $gesucht;
    }

    private function achsenBlock(?Team $team, array $params, array &$filesUsed): ?KnowledgeContextBlock
    {
        $gesucht = $this->achsenKandidaten($team, $params);
        if ($gesucht === []) {
            return null;
        }

        // EINE Query für alle Achsen, danach je Achse der erste aktive Treffer.
        $alle = array_values(array_unique(array_merge(...array_values($gesucht))));
        $docs = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->whereIn('id', $this->geltendeDokumentIds($team))
            ->where(fn ($q) => $q->whereNull('art')->orWhere('art', Wissensart::REGEL)
                ->when(! $this->artenRoutingAktiv, fn ($q) => $q->orWhereIn('art', [Wissensart::FACHWISSEN, Wissensart::REFERENZ])))
            ->whereIn('slug', $alle)->where('active', 1)->whereNull('deleted_at')
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen))
            ->get(['slug', 'title', 'content_md', 'version', 'art'])->keyBy('slug');
        if ($docs->isEmpty()) {
            return null;
        }

        $bloecke = [];
        foreach ($gesucht as $achse => $kandidaten) {
            foreach ($kandidaten as $slug) {
                if (! isset($docs[$slug])) {
                    continue;                                        // deaktiviert → nächster Kandidat
                }
                $doc = $docs[$slug];
                if ($doc->art === Wissensart::REGEL) $this->achsenPflichtFiles[] = "{$doc->slug}@v{$doc->version}";
                $bloecke[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => '## ' . mb_strtoupper((string) $achse) . ": {$doc->title}\n\n"
                    . (string) $doc->content_md];
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
                $this->herkunft[$slug] = [
                    'score' => null,
                    'via' => 'achse:' . $achse,
                    'chars' => mb_strlen((string) $doc->content_md),
                    'sent' => mb_strlen((string) $doc->content_md),
                ];
                break;                                               // ein Dossier je Achse
            }
        }

        if ($bloecke === []) {
            return null;
        }

        return new KnowledgeContextBlock("# ANLASS- & SEGMENT-WISSEN (aus den Leitplanken aufgelöst — verbindlicher Rahmen)\n\n"
            . "Diese Dossiers gehören zum gewählten Anlass bzw. Verpflegungskontext. Sie setzen den\n"
            . "Rahmen für Portionierung, Service-Logik und Erwartungshaltung — nicht die Zutatenwahl.\n\n",
            $bloecke);
    }

    /**
     * W0-5 — Summe der PFLICHT-Inhalte eines Features: was über `mode='always'` geroutet
     * ist und deshalb unabhängig von jeder Relevanz in den Prompt MUSS.
     *
     * Warum das gebraucht wird: das Gesamtbudget kappt am ENDE des zusammengesetzten
     * Blocks. Ist der Deckel kleiner als die Pflichtmenge, verschwindet das letzte
     * `always`-Dossier mitsamt Überschrift — still, ohne Fehler, ohne Log. Genau die
     * Fehlerklasse, die Welle 0 beseitigen soll. Also: Budget >= Pflichtmenge, maschinell
     * geprüft (`foodalchemist:wissen-steuerdaten-w0 --verify`), nicht per Augenmaß.
     *
     * Die Rechnung spiegelt die Ist-Deckel der jeweiligen Block-Builder:
     *   · cross_cutting — 7 feste Slugs, Routing-Werte werden ignoriert
     *   · regelwerk     — **0**, seit Spec 52 · F4: der dedizierte always-Zweig ist gelöscht,
     *     die Zeile lädt nichts. Sie hier weiter als Pflichtmenge zu zählen, hiesse Budget für
     *     Wissen zu reservieren, das nie kommt — und die W0-5-Invariante würde Phantasiewerte
     *     prüfen. Dass so eine Zeile überhaupt existiert, meldet `wissen-profil` als Befund
     *     `routing_always_tot`; hier ist sie schlicht 0.
     *   · concept       — max_docs (Default CONCEPT_MAX_DOCS) × Doc-Deckel
     *   · sonst         — alwaysCategoryBlock: max_docs (Default 2) × Doc-Deckel
     */
    public function pflichtZeichen(string $feature): int
    {
        // Ueber denselben Helfer wie contextFor, sonst meldete die W0-Invariante fuer
        // `recipe.generator` eine Pflichtmenge von 0, obwohl der Alt-Name Zeilen traegt.
        $zeilen = $this->routingZeilen($feature)->where('mode', 'always');

        $summe = 0;
        foreach ($zeilen as $r) {
            $docDeckel = (int) ($r->max_chars_per_doc ?: 0);
            $summe += match ((string) $r->category) {
                // Dieselbe feature-genaue Auflösung wie crossCuttingDocs() — sonst prüft die
                // Invariante eine Pflichtmenge, die es für dieses Feature nie gibt.
                'cross_cutting' => count($this->crossCuttingSlugs($feature)) * self::CROSS_CUTTING_TRUNCATE_CHARS,
                'regelwerk' => 0,                                   // F4: lädt nichts mehr, s. Docblock
                'concept' => ((int) ($r->max_docs ?: self::CONCEPT_MAX_DOCS)) * ($docDeckel ?: self::CONCEPT_TRUNCATE_CHARS),
                default => ((int) ($r->max_docs ?: 2)) * ($docDeckel ?: 4000),
            };
        }

        return $summe;
    }

    /**
     * Spec 52/B4: gemessene Retrieval-Pflicht, aus denselben Quellen und mit
     * denselben Überschriften wie zur Laufzeit. Keine Discovery, kein Modellaufruf.
     * Die historische pflichtZeichen()-Formel ist nur eine Konfigurationsobergrenze.
     *
     * @return array{required_chars: int, budget: int, ok: bool}
     */
    public function pflichtBudgetFuer(?Team $team, string $feature): array
    {
        $budget = $this->budgetFuer($feature);
        try {
            $context = $this->contextFor($team, $feature, '', null, [], [
                '_required_only' => true, '_kanon_prompt_key' => $feature,
            ]);
            $required = $context['required_chars'];
        } catch (KnowledgeBudgetExceeded $exception) {
            $required = $exception->requiredChars;
        }

        return ['required_chars' => $required, 'budget' => $budget, 'ok' => $required <= $budget];
    }

    /** W0-5: das aufgelöste Zeichenbudget eines Features (für Prüf-Werkzeuge). */
    public function budgetFuer(string $feature): int
    {
        return $this->knowledgeBudget($feature, in_array($feature, self::REZEPT_BUDGET_KEYS, true));
    }

    /**
     * Spec 52/A2+A4 — welcher ROUTING-Schlüssel gehört zu einem Prompt-Key?
     *
     * Heute sind das zwei Schlüsselräume im selben Aufruf: der Gateway löst den Kanon über den
     * Prompt-Key auf (`recipe.generator`), das Routing wird aber mit einem hartkodierten
     * Alt-Feature gerufen. Wer das nicht weiss, hält die Generatoren für ungesteuert — und ein
     * `knowledge_routings.PUT` auf `vk.generator` schreibt stumm ins Leere.
     *
     * Die Tabelle ist AUS DEN AUFRUFSTELLEN abgelesen, nicht geraten; jede Zeile nennt ihre.
     * Sie ist bewusst hier zentral, damit Bericht (`wissen-versorgung`) und Auskunft
     * (`regelwerk.GET`) dieselbe Antwort geben. **Mit Spec 52/D1 verschwindet sie** — dann ist
     * der Schlüsselraum einer und diese Methode gibt den Prompt-Key unverändert zurück.
     *
     * @var array<string, string>
     */
    public const ROUTING_ALIAS = [
        // RecipeGenerationContextService:88 — EIN Pfad; `$vkModus` entscheidet nur den Kanon-Key,
        // nicht den Routing-Key. Basisrezept und Gericht teilen sich damit eine Routing-Politik.
        'recipe.generator' => 'ai_generate_recipe',
        'vk.generator' => 'ai_generate_recipe',
        // RecipeModal:961 / BulkEnrichService::proposeDichteklasse — contextFor() läuft unter
        // 'recipe.eigenschaften', propose() unter 'recipe.dichteklasse'.
        'recipe.dichteklasse' => 'recipe.eigenschaften',
    ];

    /** Der Routing-Schlüssel, unter dem dieser Prompt-Key sein Wissen zieht. */
    public static function routingFeatureFuer(string $promptKey): string
    {
        return self::ROUTING_ALIAS[$promptKey] ?? $promptKey;
    }

    /**
     * Spec 52/Paket 2 — Aufrufe, die die REZEPT-Deckel benutzen.
     *
     * ★ Vorher stand hier ein String-Vergleich: `$feature === 'ai_generate_recipe'`. Der
     * gatete **acht** Verhaltensweisen, darunter jeden Pro-Dossier-Deckel
     * (`RECIPE_MAX_CHARS_PER_DOC` 2.400 statt der Kategorie-Defaults 1.800/2.500). Den Alt-Namen
     * einfach durch den Prompt-Key zu ersetzen hätte deshalb JEDEN Rezept-Prompt anders gekappt
     * — still, ohne dass ein Test rot wird. Genau die Fehlerklasse, die diese Spec abbaut.
     *
     * Als Satz geschrieben, ist die Umstellung verhaltensneutral: `recipe.generator` und
     * `vk.generator` bekommen dieselben Deckel wie der Alt-Name, und der Alt-Name funktioniert
     * weiter, solange Aufrufer oder Steuerdaten ihn noch tragen.
     *
     * @var list<string>
     */
    public const REZEPT_BUDGET_KEYS = ['ai_generate_recipe', 'recipe.generator', 'vk.generator'];

    /**
     * Routing-Zeilen eines Features — mit Rückfall auf den Alt-Namen.
     *
     * Damit wird `knowledge_routings.PUT` auf `vk.generator` erstmals wirksam (vorher schrieb
     * es stumm ins Leere, weil der Generator unter `ai_generate_recipe` nachsah), ohne dass die
     * bestehenden Zeilen migriert sein müssen: eigene Zeilen gewinnen, sonst gilt der Alt-Name.
     * Dasselbe „Neues gewinnt, Altes bleibt Fallback" wie bei Kanon ⇄ Bindung und Achse ⇄ Config.
     */
    /**
     * Die WIRKSAMEN Routing-Zeilen eines Features — für Berichte und Wächter.
     *
     * ★ Warum das öffentlich ist: `WissensProfilService` und `WissensVersorgungService` haben
     * die Zeilen zuerst selbst abgefragt (`where('feature', $routingKey)`) und damit die
     * ALIAS-Zeilen gezeigt, während der Prompt-Bau bereits die zusammengeführten benutzte.
     * Gefunden beim Verifizieren auf demo: eine gesetzte VK-Zeile wirkte, der Bericht zeigte
     * weiter den geerbten Wert. Ein Diagnose-Werkzeug, das über die Laufzeit lügt, ist
     * schlimmer als keines — und es ist genau die Fehlerklasse, die diese Spec abbaut.
     *
     * Deshalb: eine Formel, drei Abnehmer (`docs/ARCHITEKTUR.md` — „Eine Formel pro fachlicher
     * Wahrheit").
     */
    public function wirksameRoutings(string $feature): \Illuminate\Support\Collection
    {
        return $this->routingZeilen($feature);
    }

    private function routingZeilen(string $feature): \Illuminate\Support\Collection
    {
        $eigene = DB::table('foodalchemist_knowledge_routings')->where('feature', $feature)->get();
        $alt = self::ROUTING_ALIAS[$feature] ?? null;
        if ($alt === null) {
            return $eigene;
        }

        // ★ Der Rückfall wirkt PRO KATEGORIE, nicht pro Feature.
        //
        // Meine erste Fassung gab die eigenen Zeilen zurück, sobald es welche gab — also
        // alles-oder-nichts. Wer EINE VK-Zeile setzt, hätte damit still die anderen elf
        // verloren: `vk.generator` hätte plötzlich nur noch `weltkueche` gehabt, ohne dass
        // irgendwo etwas anzeigt, dass `domain`, `kueche` und `cross_cutting` weg sind.
        //
        // Das bricht auch das Muster, das überall sonst gilt: der Kanon überschreibt die
        // Bindungen **pro Prompt-Key**, eine Achsen-Zeile die Config **pro Achsenwert** — immer
        // pro Element, nie pro Gruppe. „Bewusst leer" wird ausdrücklich mit `mode = none`
        // gesagt, nicht durch das Fehlen einer Zeile.
        $selector = static fn ($r) => ! empty($r->art) ? 'art:'.$r->art : 'category:'.$r->category;
        $eigeneKategorien = $eigene->map($selector)->all();

        return DB::table('foodalchemist_knowledge_routings')->where('feature', $alt)->get()
            ->reject(fn ($r) => in_array($selector($r), $eigeneKategorien, true))
            ->concat($eigene)
            ->values();
    }

    /**
     * Spec 52/A2 — Routing-Namen, die ein Aufrufer selbst führt, OHNE dass es dazu eine
     * Prompt-Registry-Zeile gibt. Dritte Variante des Schlüssel-Bruchs, nach dem Alias
     * (`recipe.generator` → `ai_generate_recipe`) und dem Feld-Mismatch (`recipe.dichteklasse`).
     *
     * Ohne diese Liste hielte der Versorgungs-Bericht solche Namen für toten Ballast — sie sind
     * aber live. Umgekehrt gilt: **welche Routing-Features WIRKLICH keinen Aufrufer haben, lässt
     * sich statisch nicht abschliessend feststellen**, weil mehrere Stellen mit einer Variablen
     * rufen (`contextFor($team, $promptKey, …)` in `ConceptGeneratorService`,
     * `contextFor($this->team(), $prompt, …)` in `StepEditor`). Der Bericht sagt deshalb
     * „ohne Prompt-Key", nicht „ohne Aufrufer" — und nennt die vier, für die ein `grep` über
     * `src/` am 2026-09-07 wirklich leer war.
     *
     * @var array<string, string>  Feature → Fundstelle
     */
    public const CONTEXT_ONLY_FEATURES = [
        'foodbook.plan' => 'IdeenService — contextFor(…, \'foodbook.plan\'), kein Registry-Key',
    ];

    /**
     * Routing-Features, für die ein `grep` über `src/` am 2026-09-07 KEINEN `contextFor()`-Aufrufer
     * fand — also konfigurierte Politik, die nichts steuert. 11 der 73 Zeilen.
     *
     * Besonders bitter bei `ai_extract_recipe × cross_cutting = none`: das ist eine BEWUSSTE
     * Entscheidung („dieser Schritt braucht kein Wissen"), hinterlegt unter einem Namen, den
     * niemand ruft. Der eigentliche Key heisst `recipe.extract` und hat keine Zeile.
     *
     * Als Datum geführt, nicht als Wahrheit: fügt jemand einen Aufrufer hinzu, gehört der Name
     * hier heraus.
     *
     * @var list<string>
     */
    public const OHNE_AUFRUFER_GEMESSEN = [
        'ai_extract_recipe', 'ai_infer_ankers', 'ai_plan_dishes', 'ai_suggest_pairings',
    ];

    /**
     * Spec 52/B3 — die Optionen für `propose()` aus einem `contextFor()`-Ergebnis bauen.
     *
     * Warum ein Helfer und nicht 18 Aufrufstellen von Hand: `knowledge_dropped_chars` gaben
     * genau ZWEI von achtzehn Aufrufern weiter, und darum stand in sechzehn Features
     * `prompt_parts.dropped = 0`, obwohl Zeichen fehlten (Befund I3). Das ist keine
     * Nachlässigkeit einzelner Stellen, sondern eine Konvention ohne Durchsetzung — dieselbe
     * Klasse wie `_kanon_prompt_key` (2 von 14). Ein Helfer, der ALLE Messfelder mitnimmt,
     * macht die nächste neue Aufrufstelle automatisch richtig; ein Wächter-Test hält es.
     *
     * Extras (`target_table`, `tier`, …) hängt der Aufrufer per `+` an.
     *
     * @param  array<string, mixed>  $wissen  Ergebnis von {@see contextFor()}
     * @return array<string, mixed>
     */
    public static function proposeOptionen(array $wissen): array
    {
        $block = (string) ($wissen['block'] ?? '');

        // BEWUSST ohne `knowledge_channels`: dieses Feld ist der Dedup-/Anzeige-Eingang, an dem
        // schon einmal der Bound-Kanal gestorben ist (W0-3b — ein Anzeige-Spiegel schrieb auf
        // das Feld, das die Auswahl-Logik liest). Wer Kanäle liefert, liefert sie weiterhin
        // selbst und bewusst. Dieser Helfer schliesst nur die Messlücke.
        return [
            'knowledge' => $block !== '' ? $block : null,
            'knowledge_used' => $wissen['files_used'] ?? [],
            // Ohne diese Zeile ist die Kappung im Call-Log unsichtbar.
            'knowledge_dropped_chars' => (int) ($wissen['dropped_chars'] ?? 0),
        ];
    }

    /**
     * W0-5: Featureweites Zeichenbudget. Reihenfolge: Feature-Override aus der Config >
     * Rezept-Sonderdeckel (historisch, bleibt der strengste) > Default.
     *
     * Bewusst auf `$feature` gekeyt, nicht auf den Prompt-Key: `vk.generator` ist KEIN
     * Routing-Feature — Gerichte laufen über contextFor('ai_generate_recipe'). Ein
     * Prompt-Key-Scope braucht erst den `_prompt_key`-Durchstich (Welle 1).
     */
    private function knowledgeBudget(string $feature, bool $recipeBudget): int
    {
        return KnowledgeBudget::forKey($feature);
    }

    /**
     * Die Regler/der Quadrant müssen die Retrieval-Query tatsächlich prägen. Steuer- und Cache-
     * Felder bleiben draußen; nur kulinarisch bedeutende Werte werden flach angehängt.
     */
    private function discoveryQuery(string $description, array $params): string
    {
        $keys = [
            'niveau', 'level', 'sektor', 'convenience', 'frische', 'bio', 'bio_pref',
            'bestand', 'diaet_hart', 'allergen_nogo', 'aroma', 'aroma_kueche', 'occasion',
            'serviceform', 'kompositions_stil', 'saison', 'ziel_we_pct', 'rezept_typ',
        ];
        $werte = [];
        foreach ($keys as $key) {
            $value = $params[$key] ?? null;
            if (is_array($value)) {
                $value = implode(' ', array_filter(array_map(
                    fn ($v) => is_scalar($v) ? (string) $v : '',
                    $value
                )));
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $werte[] = str_replace(['_', '-'], ' ', (string) $value);
            }
        }

        return trim($description . ' ' . implode(' ', $werte));
    }

    /** @return list<string> */
    private function knowledgeScopeSlugs(mixed $scope): array
    {
        if (! is_array($scope)) {
            return [];
        }
        $slugs = [];
        foreach ($scope as $item) {
            $slug = preg_replace('/@v\d+$/', '', trim((string) $item));
            if ($slug !== '' && ! str_starts_with($slug, 'graph:')) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Tokenisiert für Alias- und Slug-Matching (vault_context.rs:343-362):
     * lowercase, Umlaut-Expansion (ä→ae ö→oe ü→ue ß→ss), nur Alphanumerik,
     * Token ≥3 Zeichen.
     *
     * @return list<string> dedupliziert
     */
    public function tokenize(string $s): array
    {
        return app(KnowledgeTokenizer::class)->tokenize($s);
    }

    /** @param list<string> $a @param list<string> $b */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersect = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : $intersect / $union;
    }

    /**
     * Semantischer Recall für Pairing über die ANKER-Embeddings (embedAnkers), nicht die
     * Pairing-Docs. Gates: Config + Provider, damit deaktivierte
     * Semantik/kein Provider sauber zu no-op werden.
     *
     * @return list<string> Anker-Slugs, bestes zuerst
     */
    private function semanticAnkerStems(string $description, int $limit): array
    {
        if ($limit <= 0 || ! config('foodalchemist.semantic_search.enabled', false)) {
            return [];
        }
        try {
            $svc = app(KnowledgeEmbeddingService::class);
            if (! $svc->searchEnabled()) {
                return [];
            }

            return $svc->searchAnkerSlugs($description, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Invariante 3: hartes Per-Dokument-Budget mit wörtlichem Kürzungs-Marker. */
    public function truncate(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n\n[…gekürzt für KI-Kontext…]";
    }

    /**
     * Invariante 4: extrahiert KOMPAKT die verifizierten Partner-NAMEN aus der
     * »## Pairings«-Region (Wikilink-Displays + **bold**, ≤40 Zeichen, dedupe),
     * NICHT die molekulare Prosa. Region endet bei »## Notizen«/»## Eigene«.
     * $sections = Stil-Filter (Tabelle 4.2): null = ganze Region inkl.
     * Verbund/Trinitas; sonst nur ###-Untersektionen, deren Header mit einem
     * Key beginnt — neue ##-Sektion schaltet den Filter wieder aus.
     *
     * @param  list<string>|null  $sections
     * @return list<string>
     */
    public function extractPairingNames(string $content, ?array $sections = null): array
    {
        $start = mb_strpos($content, '## Pairings');
        if ($start === false) {
            return [];
        }
        $rest = mb_substr($content, $start);
        $end = mb_strpos($rest, '## Notizen');
        if ($end === false) {
            $end = mb_strpos($rest, '## Eigene');
        }
        $region = $end === false ? $rest : mb_substr($rest, 0, $end);

        $scan = $region;
        if ($sections !== null) {
            $kept = '';
            $keep = false;
            foreach (explode("\n", $region) as $line) {
                if (str_starts_with($line, '### ')) {
                    $h = substr($line, 4);
                    $keep = count(array_filter($sections, fn ($k) => str_starts_with($h, $k))) > 0;
                } elseif (str_starts_with($line, '## ')) {
                    $keep = false;                                  // neue ##-Sektion (z.B. Verbund) → aus
                }
                if ($keep) {
                    $kept .= $line . "\n";
                }
            }
            $scan = $kept;
        }

        $names = [];
        $seen = [];
        $push = function (string $raw) use (&$names, &$seen): void {
            $name = trim($raw);
            $key = mb_strtolower($name);
            if ($name !== '' && strlen($name) <= 40 && ! isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $name;
            }
        };
        // Wikilinks [[slug|Display]] / [[Display]] → Display
        foreach (array_slice(explode('[[', $scan), 1) as $part) {
            $close = strpos($part, ']]');
            if ($close !== false) {
                $inner = substr($part, 0, $close);
                $segments = explode('|', $inner);
                $push(end($segments));
            }
        }
        // **Bold**-Pairings (Einträge ohne eigene Datei)
        $boldParts = explode('**', $scan);
        for ($i = 1; $i < count($boldParts); $i += 2) {
            if (strlen($boldParts[$i]) <= 40 && preg_match('/[\x00-\x1f]/', $boldParts[$i]) === 0) {
                $push($boldParts[$i]);
            }
        }

        return $names;
    }

    /**
     * Spec 08 P6: Concepting-Wissen für die Planungs-Features (`foodbook.plan`,
     * `concept.plan`). Bewusst `always` statt `discovery`: der Bestand ist klein
     * und beschreibt Handwerk, kein Produkt — eine Beschreibungs-Discovery würde
     * hier nach Zutaten filtern, wo Dramaturgie gefragt ist. Deckel kommt aus der
     * Routing-Zeile (max_docs/max_chars_per_doc), Reihenfolge ist Slug-stabil.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function conceptBlock(?Team $team, int $maxDocs, int $maxChars, array &$filesUsed): ?KnowledgeContextBlock
    {
        $docs = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', 'concept')->where('active', 1)->whereNull('deleted_at')
            ->orderBy('slug')->limit(max(1, $maxDocs))
            ->get(['slug', 'content_md', 'version']);
        if ($docs->isEmpty()) {
            return null;                                             // Invariante 6: fehlende Quelle = leerer Kontext
        }

        $blocks = [];
        foreach ($docs as $doc) {
            $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => "## CONCEPT: {$doc->slug}\n\n" . (string) $doc->content_md];
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }

        return new KnowledgeContextBlock("# CONCEPTING-WISSEN (Konzept-/Menü-Handwerk: Dramaturgie, Gang-Aufbau, Anlass- und Gäste-Fit, Balance)\n\n"
            . "Maßstab für den PLAN: es sagt, WIE ein gutes Konzept gebaut ist — nicht, welches Gericht darin steht.\n\n",
            $blocks);
    }

    /**
     * Etappe 1 (Roadmap »Mise en Place« 2026-08-14) + Spec 41 B1 (2026-08-21): das verbindliche
     * REGELWERK als Bau-/Gerüst-Regel in den Generator — bewusst `always` + dediziert statt der
     * generischen discovery: Regelwerk ist HANDWERK, kein Produkt-Dossier — eine
     * Beschreibungs-Discovery (Slug-Token gegen die Zutaten) würde es bei realen Briefs
     * NIE treffen (kein Overlap »Steinpilz« ↔ »basisrezepte«).
     *
     * PRO FEATURE das RICHTIGE Regelwerk (Spec 41 B1, RC-2/RC-4): `ai_generate_recipe` →
     * Basisrezepte (§2–§4 Bau + §12 Reihenfolge), `concept.brief_geruest` → Concept (Volltext,
     * kompakt). Deterministisch über den Slug gewählt; unbekanntes Feature ⇒ Basisrezepte
     * (Bestand). Extrahiert wird nur die tragende Region (nicht der ganze ~50k-Text — §2 beginnt
     * erst bei ~17k, ein blinder Head-Truncate verfehlt sie). Fehlt der Doc ⇒ null (Invariante 6);
    /**
     * Spec 50 · E-4 — welche Regelwerk-Dossiers zu einem Feature gehören.
     *
     * ★ **Von `->first()` auf eine LISTE umgestellt (Spec 52 · F4).**
     *
     * Bis Etappe 8 lag die Auswahl in `regelwerkBlock()` und lieferte per `orderBy('slug')
     * ->first()` genau EIN Dossier — als Prompt-Block. `regelwerk.GET` und das
     * Vorgangs-Register riefen dieselbe Methode, damit „die Auskunft nennt, was der Generator
     * lädt". Richtig gedacht, und mit F4 hinfällig: der Generator lädt hier gar nichts mehr,
     * verbindliches Wissen kommt aus dem Kanon.
     *
     * Damit verliert das `->first()` seine Rechtfertigung — und war ohnehin die schwächste
     * Stelle: `%basisrezept%` trifft rund zwanzig §-Dossiers, und dem Agenten eines davon zu
     * nennen ist schlechter als ihm alle zu nennen. Diese Methode ist ab jetzt reines
     * **Nachschlagen für die Auskunft** („welches Regelwerk gehört zu diesem Bereich"), kein
     * Injektionspfad. Sie gibt deshalb alle Treffer zurück.
     *
     * @param  list<string>  $spalten
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function regelwerkDossiersFuer(?Team $team, string $feature, array $spalten = ['slug', 'title', 'char_count', 'version']): \Illuminate\Support\Collection
    {
        // ★ Spec 52/Paket 2 — KEIN Blind-Default mehr.
        //
        // Vorher fiel jedes unbekannte Feature auf `%basisrezept%` zurueck. Das widersprach dem
        // Prinzip, das direkt ueber der Karte steht („ein falsches waere schlimmer als keines"),
        // und traf real drei Vorgaenge: `gp_aus_la_anlegen` bekam das Basisrezepte-Regelwerk
        // statt des GP-Regelwerks, Angebot/Speiseplan/Preis-Monitoring bekamen eines, obwohl es
        // fuer sie gar keins gibt. Ein Vorgang ohne Regelwerk soll KEINES nennen — `regelwerk.GET`
        // sagt dann `quelle: keine`, und das ist die Wahrheit.
        $slugLike = self::REGELWERK_SLUG_LIKE[$feature] ?? null;
        if ($slugLike === null) {
            return collect();
        }

        return DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->where('category', 'regelwerk')->where('active', 1)->whereNull('deleted_at')
            ->where('slug', 'like', $slugLike)
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen))
            ->orderBy('slug')->get($spalten);
    }

    /**
     * Feature/Prompt-Key → Slug-Muster des zugehörigen Regelwerks. `format.grundgeruest` ist
     * bewusst eigen eingetragen, damit es NICHT auf den Basisrezept-Fallback rutscht — es gibt
     * (noch) kein Format-Regelwerk, und ein falsches wäre schlimmer als keines.
     *
     * ★ **Spec 52/Paket 2 — genau dieses Prinzip war für Gerichte verletzt.** `vk.generator`
     * hatte keinen Eintrag, und der Blind-Default (`?? ['ai_generate_recipe']`) liefert
     * `%basisrezept%`. Ohne Kanon bekam der Gericht-Pfad damit das **Basisrezepte**-Regelwerk,
     * obwohl `regelwerk.regelwerk_verkaufsgerichte--*` existiert — dieselbe Fehlerklasse wie
     * der schon dokumentierte Fall „vk bekam Basisrezepte statt Verkaufsgerichte", nur an
     * anderer Stelle. Auf demo fiel es nicht auf, weil der Kanon greift; auf einer frischen DB
     * (Migrationsstand: `regelwerk always`) hätte es zugeschlagen.
     *
     * ★ **Diese Karte trug zwei Dinge unter einem Namen — eines ist mit Spec 52 · F4 weg.**
     *
     *  1. *Injektion*: welches Dossier der Generator per `regelwerk:always` in den Prompt
     *     hebt. **Gelöscht.** Verbindliches Wissen kommt aus dem Kanon.
     *  2. *Nachschlagen*: welcher Regelwerks-BEREICH fachlich zu einem Feature gehört —
     *     für `regelwerk.GET` und das Vorgangs-Register. **Bleibt**, und das ist legitim:
     *     einem Agenten zu sagen „für Gerichte gilt das VK-Regelwerk" ist eine Auskunft,
     *     kein Prompt-Bau.
     *
     * Der Plan wollte die Karte ganz löschen. Das hielt der Messung nicht stand — sie hat
     * einen zweiten, gesunden Abnehmer. Weg ist stattdessen das `->first()`
     * ({@see regelwerkDossiersFuer()}): ein Muster wie `%basisrezept%` trifft rund zwanzig
     * §-Dossiers, und eines davon alphabetisch zu greifen war der eigentliche Defekt.
     */
    public const REGELWERK_SLUG_LIKE = [
        'concept.brief_geruest' => '%concept%',
        'foodbook.grundgeruest' => '%foodbook%',
        'format.grundgeruest' => '%format%',
        'ai_generate_recipe' => '%basisrezept%',
        // Paket 2: der eine Schlüsselraum — Prompt-Keys explizit, Basis und VK getrennt.
        'recipe.generator' => '%basisrezept%',
        'recipe.ueberarbeiten' => '%basisrezept%',
        'recipe.review' => '%basisrezept%',
        'recipe.eigenschaften' => '%basisrezept%',
        'vk.generator' => '%verkaufsgerichte%',
        'vk.ueberarbeiten' => '%verkaufsgerichte%',
        'vk.review' => '%verkaufsgerichte%',
        // Der GP-Vorgang fiel auf `%basisrezept%` zurueck, obwohl `regelwerk-gp-*` existiert.
        'gp.suggest' => '%regelwerk-gp%',
        'gp.conformance_revise' => '%regelwerk-gp%',
    ];

    /**
     * TREND-WISSEN (Trendradar, `foodbook.plan` / `concept.brief_geruest`): discovery
     * über die geclusterten Trend-Docs. Auswahl = Relevanz (aus trend_meta) + Token-Overlap
     * der Beschreibung gegen Titel/Slug/Klasse/Kategorie, damit nur thematisch passende
     * Trends ins Prompt-Budget kommen. Deckel aus der Routing-Zeile. Ohne Bestand: null
     * (Invariante 6 — fehlende Quelle = leerer Kontext, nie Fehler).
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function trendBlock(?Team $team, int $maxDocs, int $maxChars, string $description, array &$filesUsed): ?KnowledgeContextBlock
    {
        $maxDocs = max(1, $maxDocs);
        $tokens = $this->tokenize($description);
        $weight = ['high' => 3, 'medium' => 2, 'low' => 1];

        $rows = DB::table('foodalchemist_knowledge_documents as d')->tap($this->nurFuerPrompt($team, 'd.team_id', 'd.art'))
            ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 'd.id')
            ->where('d.category', 'trend')->where('d.active', 1)->whereNull('d.deleted_at')
            ->get(['d.id', 'd.slug', 'd.title', 'd.version', 'm.relevance', 'm.trend_class', 'm.category']);
        if ($rows->isEmpty()) {
            return null;
        }

        $scored = [];
        foreach ($rows as $r) {
            $matchTokens = $this->tokenize("{$r->title} {$r->slug} {$r->trend_class} {$r->category}");
            $overlap = $tokens === [] ? 0 : count(array_intersect($tokens, $matchTokens));
            $scored[] = [$r, ($weight[$r->relevance] ?? 1) + $overlap * 2];
        }
        usort($scored, fn ($x, $y) => $y[1] <=> $x[1]);
        $top = array_slice($scored, 0, $maxDocs);

        $ids = array_map(fn ($p) => $p[0]->id, $top);
        $docs = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))->whereIn('id', $ids)
            ->get(['id', 'slug', 'content_md', 'version'])->keyBy('id');

        $blocks = [];
        foreach ($top as [$r]) {
            $doc = $docs->get($r->id);
            if ($doc === null) {
                continue;
            }
            $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => "## TREND: {$doc->slug}\n\n" . (string) $doc->content_md];
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }
        if ($blocks === []) {
            return null;
        }

        return new KnowledgeContextBlock("# TREND-WISSEN (aktuelle Food-Trends aus dem Trendradar)\n\n"
            . "Diese Signale sagen, WAS gerade relevant ist — nutze sie als Anlass/Inspiration. "
            . "Erfinde nichts hinzu, was die Trends nicht hergeben.\n\n",
            $blocks);
    }

    /** Die 7 Always-Load-Dokumente in Ist-Reihenfolge (fehlende werden still übersprungen). */
    private function crossCuttingDocs(?Team $team, string $feature): array
    {
        $slugs = $this->crossCuttingSlugs($feature);
        $docs = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', 'cross_cutting')->where('active', 1)->whereNull('deleted_at')
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen))
            ->whereIn('slug', $slugs)
            ->get(['slug', 'content_md', 'version'])->keyBy('slug');

        return array_values(array_filter(array_map(fn ($slug) => $docs->get($slug), $slugs)));
    }

    /**
     * B3 — welche Cross-Cutting-Dossiers ein Feature WIRKLICH braucht.
     *
     * `ALWAYS_LOAD_CROSS_CUTTING` sind sieben PRODUKTIONS-Dossiers (Substitutionen,
     * Saisonkalender, Synonyme, Saucen-Mutterstrukturen, Mengen-Defaults, Techniken,
     * Brühen/Fonds) = 7 × 1.800 = 12.600 Zeichen. Für einen Generator ist das richtig.
     *
     * Für einen KUNDENTEXT ist es das nicht: `foodbook.kundentext` schreibt 2–4 Sätze
     * Fließtext (max_tokens 1.500) und bekam dafür Mengen-Defaults und Brühen-Rezepturen
     * mitgeliefert. Nützlich sind dort höchstens `saisonkalender` (Saison-Aussagen belegen)
     * und `synonyme` (Dinge richtig benennen) — der Rest ist Ballast, den das Modell
     * mitbezahlt und der die Aufmerksamkeit vom Auftrag wegzieht.
     *
     * Default bleibt die Konstante (Tests + Wissens-Browser hängen daran); Überschreibung
     * je Feature über config('foodalchemist.ai.cross_cutting_slugs') — seit Welle 2 mit
     * Split-Slugs (`saisonkalender--hauptsaison-nach-monaten-de`,
     * `synonyme--cross-sprachliche-synonyme-gleicher-lebensmittel`), die Originale sind inaktiv.
     * Public, weil der W0-Wächter genau diese Auflösung gegen den Korpus prüft.
     *
     * @return list<string>
     */
    public function crossCuttingSlugs(string $feature): array
    {
        $map = config('foodalchemist.ai.cross_cutting_slugs', []);
        if (is_array($map) && isset($map[$feature]) && is_array($map[$feature]) && $map[$feature] !== []) {
            return array_values(array_map('strval', $map[$feature]));
        }

        return self::ALWAYS_LOAD_CROSS_CUTTING;
    }

    /**
     * 2026-08-27 (Dominique): UNBEDINGTER Kategorie-Load für Referenz-Dossiers (z.B.
     * produktion_kapazitat = Produktions-Zeitkennwerte), die rezept-unabhängig immer gelten.
     * Lädt ALLE aktiven Docs der Kategorie (slug-sortiert = deterministisch), gedeckelt auf
     * $maxDocs + je Doc $maxChars. Ergänzt {@see discoverGenericBlock} (das per Slug-Jaccard
     * rankt und eine General-Referenz mit Score 0 verfehlen würde) — hier zählt die Kategorie-
     * Zugehörigkeit, nicht der Rezept-Bezug.
     */
    private function alwaysCategoryBlock(?Team $team, string $category, int $maxDocs, int $maxChars, array &$filesUsed): ?KnowledgeContextBlock
    {
        if ($maxDocs <= 0) {
            return null;
        }
        $docs = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', $category)->where('active', 1)->whereNull('deleted_at')
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen))
            ->orderBy('slug')->limit($maxDocs)->get(['slug', 'content_md', 'version']);
        if ($docs->isEmpty()) {
            return null;
        }
        $blocks = [];
        foreach ($docs as $doc) {
            $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => '## ' . mb_strtoupper($category) . ": {$doc->slug}\n\n" . (string) $doc->content_md];
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }

        return new KnowledgeContextBlock("# REFERENZ-WISSEN ({$category})\n\n", $blocks);
    }

    /**
     * S1 (Skalierbarkeit): generische discovery für JEDE als `discovery` geroutete Kategorie
     * OHNE eigenen Spezial-Handler. Rankt die aktiven Docs der Kategorie gegen die (Leitplanken-
     * augmentierte) Query über den gemeinsamen KnowledgeSearchService und lädt erst
     * nach Rangfusion die ausgewählten Volltexte. So trägt jedes neu gepflegte Doc automatisch,
     * ohne Service-Änderung; der Prompt bleibt durch top_k/chars beschränkt (O(1), nicht O(n)).
     */
    private function discoverGenericBlock(?Team $team, string $category, string $query, int $topK, int $maxChars, array &$filesUsed, array $allowedSlugs = [], ?string $art = null): ?KnowledgeContextBlock
    {
        $base = DB::table('foodalchemist_knowledge_documents')->tap($art === null ? $this->nurFuerPrompt($team) : $this->nurSichtbar($team))
            ->when($art === null, fn ($q) => $q->where('category', $category), fn ($q) => $q->where('art', $art))->where('active', 1)->whereNull('deleted_at')
            ->when($allowedSlugs !== [], fn ($q) => $q->whereIn('slug', $allowedSlugs))
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen));
        $eligible = (clone $base)->get(['id', 'geltung'])->filter(fn ($doc) => \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::passt(
            \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($doc->geltung), $this->geltungsParameter))->pluck('id')->all();
        $base->whereIn('id', $eligible);
        $hits = app(KnowledgeSearchService::class)->search($base, $query, $topK,
            (bool) config('foodalchemist.semantic_search.enabled', false), $team);
        if ($hits === []) {
            return null;
        }
        // Volltext erst nach gemeinsamer Rangfusion und Endauswahl laden.
        $contents = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->where('active', 1)->whereNull('deleted_at')
            ->whereIn('id', array_column($hits, 'id'))->pluck('content_md', 'id');
        $label = mb_strtoupper($category);
        $blocks = [];
        foreach ($hits as $hit) {
            $content = (string) ($contents[$hit['id']] ?? '');
            $file = "{$hit['slug']}@v{$hit['version']}";
            $blocks[] = ['file' => $file, 'text' => "## {$label}: {$hit['slug']}\n\n".$content];
            $filesUsed[] = $file;
            $this->herkunft[$hit['slug']] = [
                'score' => $hit['score'], 'via' => $hit['via'],
                'lexical_rank' => $hit['lexical_rank'], 'semantic_rank' => $hit['semantic_rank'],
                'lexical_score' => $hit['lexical_score'], 'candidate_limit' => $hit['candidate_limit'],
                'chars' => mb_strlen($content), 'sent' => mb_strlen($content),
            ];
        }

        return new KnowledgeContextBlock($art === Wissensart::REFERENZ ? "# REFERENZEN (optionale Inspiration; keine verbindlichen Regeln)\n\n" : '# '.$label."-WISSEN\n\n", $blocks);
    }

    /**
     * NIVEAU-WISSEN typ-abhängig (Spec 37): der Rezept-Typ wählt die Slug-Familie
     * (Basisrezept → `…basis…`-Docs = Komponenten-Niveau; Gericht → die Teller-Docs), der
     * Level die Stufe. Deterministisch statt fuzzy — beide Familien tragen denselben Level-Token
     * (haute/gehoben/klassisch), nur der Typ trennt sie sauber. Leeres/unbekanntes Niveau ⇒ kein
     * Block (Default egal). Slug-Match tolerant gegen -/_ (LIKE auf Token). Fehlt der typ-spezifische
     * Doc (z. B. Basis-Docs noch nicht importiert) ⇒ null statt falschem Teller-Doc.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function niveauBlock(?Team $team, int $maxChars, string $level, string $rezeptTyp, array &$filesUsed): ?KnowledgeContextBlock
    {
        $levelToken = match ($level) {
            'haute_cuisine' => 'haute',
            'gehoben' => 'gehoben',
            'klassisch' => 'klassisch',
            default => null,
        };
        if ($levelToken === null) {
            return null;
        }
        $istBasis = $rezeptTyp === 'basisrezept';
        $doc = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', 'niveau')->where('active', 1)->whereNull('deleted_at')
            ->where('slug', 'like', '%' . $levelToken . '%')
            ->when(
                $istBasis,
                fn ($q) => $q->where('slug', 'like', '%basis%'),
                fn ($q) => $q->where('slug', 'not like', '%basis%'),
            )
            ->orderBy('slug')->first(['slug', 'content_md', 'version']);
        if ($doc === null) {
            return null;
        }
        $filesUsed[] = "{$doc->slug}@v{$doc->version}";

        return new KnowledgeContextBlock("# NIVEAU-WISSEN\n\n", [[
            'file' => "{$doc->slug}@v{$doc->version}",
            'text' => "## NIVEAU: {$doc->slug}\n\n" . (string) $doc->content_md,
        ]]);
    }

    /**
     * Domain-Discovery benutzt denselben Rechner wie alle generischen Kategorien.
     * Das Routing begrenzt erst die Endauswahl; kein alphabetischer Vorab-Deckel.
     */
    private function discoverDomains(?Team $team, string $description, array $allowedSlugs = [], int $topK = self::DOMAIN_TOP_K): array
    {
        $base = DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', 'domain')->where('active', 1)->whereNull('deleted_at')
            ->when($allowedSlugs !== [], fn ($q) => $q->whereIn('slug', $allowedSlugs))
            ->when($this->ausgeschlossen !== [], fn ($q) => $q->whereNotIn('slug', $this->ausgeschlossen));
        $hits = app(KnowledgeSearchService::class)->search($base, $description, $topK,
            (bool) config('foodalchemist.semantic_search.enabled', false), $team);
        $docs = $this->domainDocsBySlug($team, array_column($hits, 'slug'));
        $selected = [];
        foreach ($hits as $hit) {
            if (($doc = $docs->get($hit['slug'])) === null) {
                continue;
            }
            $selected[] = $doc;
            $this->herkunft[$hit['slug']] = [
                'score' => $hit['score'], 'via' => $hit['via'],
                'lexical_rank' => $hit['lexical_rank'], 'semantic_rank' => $hit['semantic_rank'],
                'lexical_score' => $hit['lexical_score'], 'candidate_limit' => $hit['candidate_limit'],
                'chars' => mb_strlen((string) $doc->content_md),
            ];
        }

        return $selected;
    }

    /**
     * content_md NUR für die ausgewählten Top-K-Slugs laden (entspricht dem `read_truncated`
     * je Top-K der Tauri-App). Leere Auswahl → leere Collection (kein Query).
     */
    private function domainDocsBySlug(?Team $team, array $slugs): \Illuminate\Support\Collection
    {
        if ($slugs === []) {
            return collect();
        }

        // Geltung und Art wurden vor dem Ranking geprüft. Hier nur die Gewinner laden,
        // nicht erneut die gesamte Menge zulässiger IDs an die Volltext-Abfrage hängen.
        return DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->where('category', 'domain')->where('active', 1)->whereNull('deleted_at')
            ->whereIn('slug', $slugs)
            ->get(['slug', 'content_md', 'version'])->keyBy('slug');
    }

    /**
     * Kompakter FLAVOR-PAIRING-Block (vault_context.rs:464-539): Beschreibung
     * gegen Pairing-Doc-Stems matchen (max 3, sortiert), je Anker eine Zeile
     * »- <stem>: A · B · C« (max 28 Partner). Invariante 5: auch »gewagt«
     * zieht NUR belegte Paarungen — der Header sagt das der KI explizit.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function pairingBlock(string $description, ?string $stil, array &$filesUsed, int $maxAnchors = self::PAIRING_TOP_K): ?KnowledgeContextBlock
    {
        // Graph-first (2026-07-13): Partner kommen aus dem Anker-Graphen (PairingService),
        // NICHT mehr aus dem Markdown-Volltext. Der Graph ist das Gehirn (kuratiert + Buch +
        // computed, ~179k Kanten); die md-Prosa liefert nur noch Grounding (groundingBlock).
        // Stil → Kanten-Typen (neue Taxonomie aroma/kontrast/erprobt).
        $typen = match ($stil) {
            'klassisch' => ['erprobt'],
            'kreativ' => ['erprobt', 'aroma'],
            'gewagt' => ['aroma', 'kontrast'],
            default => ['erprobt', 'aroma', 'kontrast'],
        };
        $stilHint = match ($stil) {
            'klassisch' => ' (Stil KLASSISCH — etablierte, erprobte Kombinationen)',
            'kreativ' => ' (Stil KREATIV — erprobte Basis + Aroma-belegte Twists)',
            'gewagt' => ' (Stil GEWAGT — Aroma + Kontrast, bewusst mutig, aber NUR belegte aus dieser Liste)',
            default => '',
        };

        $tokens = $this->tokenize($description);
        if ($tokens === []) {
            return null;
        }

        $matched = [];
        foreach ($this->pairingStems() as $stem) {
            $stemNorm = str_replace(['-', '_'], '', $stem);          // mehrteilige Slugs auch ohne Trenner matchen
            foreach ($tokens as $t) {
                if ($t === $stem || $t === $stemNorm
                    || (mb_strlen($t) >= 4 && (str_contains($stem, $t) || str_contains($stemNorm, $t)))
                    || (mb_strlen($stem) >= 4 && str_contains($t, $stem))) {
                    $matched[] = $stem;
                    break;
                }
            }
        }

        // Semantischer Recall (Hybrid, opt-in): B2 — MERGEN statt nur auffüllen. Semantisch
        // passende Anker-Stems (über die Anker-Embeddings, NICHT Pairing-Docs) treten IMMER bei
        // und können via Top-K lexikalische Stems verdrängen. Deaktiviert (Default) = no-op.
        foreach ($this->semanticAnkerStems($description, $maxAnchors) as $stem) {
            if (! in_array($stem, $matched, true)) {
                $matched[] = $stem;
            }
        }

        if ($matched === []) {
            return null;
        }
        sort($matched);

        $svc = app(\Platform\FoodAlchemist\Services\PairingService::class);
        $typSet = array_flip($typen);
        $zeilen = [];
        foreach (array_slice($matched, 0, $maxAnchors) as $stem) {
            // Stem (Doc-Slug, evtl. mit »-«) → Anker-Slug (»_«) für die Graph-Auflösung.
            $res = $svc->neighborsForName(str_replace('-', '_', $stem), null, self::MAX_PARTNERS * 4);
            if ($res['anker'] === null) {
                continue;
            }
            $namen = [];                                             // display_de → Stärke-Symbol (Typ-Prio- + Level-sortiert)
            foreach ($res['partner'] as $p) {
                if (! isset($typSet[$p->type])) {
                    continue;
                }
                // C-b (2026-08-22): Harmonie-Stärke aus axis/level rahmen — ●●● = beste (L3),
                // ●● = gute (L2), ● = schwächer/ohne Level. Kontrast liegt live NICHT im Graph
                // (Inspire = reine Harmonie-Matrix) → keine ⚡-Annotation; Kontrast kommt aus dem
                // Pairing-Prinzip + Kochwissen (siehe Header + Regelwerk/Wissens-Doc).
                $namen[$p->display_de ?: $p->slug] = $this->harmonieStaerke($p);
                if (count($namen) >= self::MAX_PARTNERS) {
                    break;
                }
            }
            if ($namen !== []) {
                $partnerText = implode(' · ', array_map(
                    fn ($name, $sym) => $name . $sym,
                    array_keys($namen), array_values($namen),
                ));
                $zeilen[] = ['file' => "graph:{$res['anker']['slug']}", 'text' => "- {$stem}: {$partnerText}"];
                $filesUsed[] = "graph:{$res['anker']['slug']}";
            }
        }
        if ($zeilen === []) {
            return null;
        }

        return new KnowledgeContextBlock("# FLAVOR-PAIRING (gemessene Harmonie aus dem Anker-Graphen{$stilHint}"
            . " — ●●● = beste, ●● = gute Harmonie (geteilte Aromastoffe); bevorzuge diese fuer"
            . " Komponenten + Garnitur, erfinde KEINE unbelegten Paarungen. Kontrast (bewusstes"
            . " Gegeneinander von Saeure/Fett/Textur) leite aus dem Pairing-Prinzip + Kochwissen"
            . " ab, NICHT aus dieser Harmonie-Liste):\n",
            $zeilen, "\n");
    }

    /**
     * C-b: Harmonie-Stärke eines Graph-Partners als Symbol. Inspire kennt nur die Achse
     * `harmony` mit Level 3 (best, ●●●) / 2 (good, ●●). Level 1 oder fehlend → ● (schwach).
     * Nicht-Harmonie-Achsen bekommen kein Symbol (Kontrast liegt live nicht im Graph).
     */
    private function harmonieStaerke(object $p): string
    {
        if (($p->axis ?? null) !== 'harmony') {
            return '';
        }
        $level = $p->level ?? null;

        return match (true) {
            $level >= 3 => ' ●●●',
            $level >= 2 => ' ●●',
            default => ' ●',
        };
    }

    /**
     * Pairing-Doku-Grounding für Anker-/Pairing-Inferenz: je Hauptzutat-Slug
     * die Doku(s) per Identitäts-/Präfix-Match (slug == hz, slug startet mit
     * »hz_«, hz startet mit »slug_«), dedupliziert, bis max_docs erreicht.
     *
     * @param  list<string>  $hauptzutatSlugs
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function groundingBlock(?Team $team, array $hauptzutatSlugs, int $maxDocs, int $maxChars, array &$filesUsed): KnowledgeContextBlock
    {
        $blocks = [];
        $geladen = [];
        foreach ($hauptzutatSlugs as $hz) {
            if (count($geladen) >= $maxDocs) {
                break;
            }
            $hz = mb_strtolower(trim($hz));
            if ($hz === '') {
                continue;
            }
            foreach ($this->pairingStems() as $stem) {
                if (count($geladen) >= $maxDocs) {
                    break;
                }
                if ($stem === $hz || str_starts_with($stem, $hz . '_') || str_starts_with($hz, $stem . '_')) {
                    if (isset($geladen[$stem])) {
                        continue;
                    }
                    $doc = $this->pairingDoc($team, $stem);
                    if ($doc !== null) {
                        $geladen[$stem] = true;
                        $blocks[] = ['file' => "{$doc->slug}@v{$doc->version}", 'text' => "### Pairing-Doku: {$stem}\n" . (string) $doc->content_md];
                        $filesUsed[] = "{$doc->slug}@v{$doc->version}";
                    }
                }
            }
        }
        if ($blocks === []) {
            return new KnowledgeContextBlock('', [['file' => null, 'text' => '(keine spezifische Doku gefunden — nutze allgemeines Wissen)']]);
        }

        return new KnowledgeContextBlock('', $blocks, "\n\n");
    }

    /**
     * @return list<string> Anker-Slugs aus dem Pairing-Graphen (Inspire-Anker-Vokabular), sortiert.
     *
     * Quelle ist seit 2026-08-07 das Anker-VOKABULAR (foodalchemist_vocab_pairing_anchors),
     * nicht mehr die Pairing-Docs (category='pairing'). Die Token→Anker-Brücke lebt damit am
     * Graphen selbst — die Pairing-Docs sind für die KI-Rezept-Erdung nicht mehr nötig und
     * dürfen aufgeräumt werden (die Partner kommen ohnehin aus PairingService, nicht aus den
     * Docs). 'neutral' bleibt außen vor (kein Aroma), konsistent mit embedAnkers().
     */
    private function pairingStems(): array
    {
        static $stems = null;
        if ($stems === null || app()->runningUnitTests()) {
            $stems = DB::table('foodalchemist_vocab_pairing_anchors')
                ->whereNull('deleted_at')->where('slug', '!=', 'neutral')
                ->orderBy('slug')->pluck('slug')
                ->all();
        }

        return $stems;
    }

    private function pairingDoc(?Team $team, string $stem): ?object
    {
        return DB::table('foodalchemist_knowledge_documents')->tap($this->nurFuerPrompt($team))
            ->where('category', 'pairing')->where('active', 1)->whereNull('deleted_at')
            ->whereIn('slug', ["pairing.{$stem}", $stem])
            ->first(['slug', 'content_md', 'version']);
    }

    // ── MCP-Discovery (Phase K): Wissens-Suche für externe LLM-Clients ──────

    /**
     * Volltext-leichte Suche über den Wissens-Bestand: Token-Treffer in
     * slug/titel + Alias-Treffer (gewichtet).
     *
     * Kein Team-Filter — bewusst, siehe MANDANTEN-INVARIANTE im Klassen-Docblock.
     * Präzisierung gegenüber der früheren Fassung („der Bestand ist global"): die
     * Zeilen sind NICHT `team_id NULL`, sondern tragen zu 99 % den Kurator (Team 6).
     * Global ist der Bestand durch PRODUKTENTSCHEID, nicht durch die Spalte — wer
     * das verwechselt, baut einen Filter ein und kappt 99 % des Korpus.
     *
     * @return list<array{slug: string, titel: string, kategorie: string, version: int, char_count: int, score: float}>
     */
    public function searchDocuments(?Team $team, string $q, ?string $kategorie = null, int $limit = 10, bool $includeInactive = false): array
    {
        $base = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($query) => $query->where('active', 1))
            ->when($kategorie !== null, fn ($query) => $query->where('category', $kategorie));
        $hits = app(KnowledgeSearchService::class)->search($base, $q, max(1, min(50, $limit)),
            (bool) config('foodalchemist.semantic_search.enabled', false), $team);

        return array_map(static fn ($hit) => array_replace($hit, [
            'active' => (bool) $hit['active'], 'version' => (int) $hit['version'], 'char_count' => (int) $hit['char_count'],
        ]), $hits);
    }

    /**
     * #496: Vollständige, seiten-basierte Enumeration des Wissens-Bestands
     * (ohne Suchbegriff, ohne 50er-Cap) — für MCP-Clients, die den ganzen
     * Katalog abrufen wollen. Optional pro Kategorie gefiltert; Frontmatter
     * (thema/sub_thema/relevanz/recherche_datum/tags) wird bei Bedarf aus dem
     * content_md geparst.
     *
     * @return array{total: int, offset: int, limit: int, next_offset: ?int, categories: array<string,int>, documents: list<array>}
     */
    public function listDocuments(?Team $team, ?string $kategorie, int $offset, int $limit, bool $mitFrontmatter = true, bool $includeInactive = false): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $base = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($q) => $q->where('active', 1))
            ->when($kategorie !== null, fn ($q) => $q->where('category', $kategorie));

        $total = (clone $base)->count();
        $categories = DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($q) => $q->where('active', 1))
            ->select('category', DB::raw('COUNT(*) AS c'))->groupBy('category')
            ->pluck('c', 'category')->map(fn ($c) => (int) $c)->all();

        $spalten = ['slug', 'title', 'category', 'active', 'version', 'char_count', 'updated_at', 'geltung'];
        // Spec 52/H1: die Art gehoert in die Inventar-Sicht — beim Korpus-Umbau ist „welche
        // Dossiers sind noch nicht eingeordnet" genau die Frage, die man an LIST stellt.
        // Schema-Wache, damit die Liste vor der Migration nicht stirbt.
        if (Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            $spalten[] = 'art';
        }
        if ($mitFrontmatter) {
            $spalten[] = 'content_md';
        }
        $docs = (clone $base)->orderBy('category')->orderBy('slug')
            ->offset($offset)->limit($limit)->get($spalten);

        $documents = $docs->map(function ($doc) use ($mitFrontmatter) {
            $row = [
                'slug' => $doc->slug,
                'title' => $doc->title,
                'category' => $doc->category,
                'art' => $doc->art ?? null,
                'geltung' => json_decode($doc->geltung ?? '[]', true),
                'active' => (bool) $doc->active,
                'version' => (int) $doc->version,
                'char_count' => (int) $doc->char_count,
                'updated_at' => $doc->updated_at,
            ];
            if ($mitFrontmatter) {
                $fm = $this->parseFrontmatter((string) $doc->content_md);
                $row['frontmatter'] = [
                    'thema' => $fm['thema'] ?? null,
                    'sub_thema' => $fm['sub_thema'] ?? null,
                    'relevanz' => $fm['relevanz'] ?? null,
                    'recherche_datum' => $fm['recherche_datum'] ?? null,
                    'tags' => $this->normalizeTags($fm['tags'] ?? []),
                ];
            }

            return $row;
        })->all();

        $next = ($offset + count($documents) < $total) ? $offset + count($documents) : null;

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $next,
            'categories' => $categories,
            'documents' => $documents,
        ];
    }

    /**
     * Öffentliche Naht für den Trendradar (Clustering + UI): derselbe Parser
     * wie intern, aber mit auf Listen normalisierten `tags`/`quellen`. So
     * bleibt EINE Parser-Wahrheit — der Trendradar kopiert keine eigene
     * (schwächere) Frontmatter-Logik.
     *
     * @return array<string, string|list<string>>
     */
    public function frontmatterOf(string $md): array
    {
        $fm = $this->parseFrontmatter($md);
        foreach (['tags', 'quellen'] as $listenfeld) {
            if (array_key_exists($listenfeld, $fm)) {
                $fm[$listenfeld] = $this->normalizeTags($fm[$listenfeld]);
            }
        }

        return $fm;
    }

    /**
     * Minimaler, dependency-freier YAML-Frontmatter-Parser (Skalar-Keys +
     * Block-Sequenzen `- item`). Reicht für die geSyncten Research-/Domain-
     * Header; kein voller YAML-Support (bewusst schlank, keine Symfony-Yaml-
     * Abhängigkeit).
     *
     * @return array<string, string|list<string>>
     */
    private function parseFrontmatter(string $md): array
    {
        if (! preg_match('/\A\x{FEFF}?\s*---\R(.*?)\R---\s*(\R|$)/su', $md, $m)) {
            return [];
        }
        $fm = [];
        $listKey = null;
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if ($listKey !== null && preg_match('/^\s*-\s+(.*\S)\s*$/', $line, $lm)) {
                $fm[$listKey][] = $this->frontmatterScalar($lm[1]);

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $km)) {
                $wert = trim($km[2]);
                if ($wert === '') {
                    $listKey = $km[1];
                    $fm[$listKey] = [];
                } else {
                    $fm[$km[1]] = $this->frontmatterScalar($wert);
                    $listKey = null;
                }
            }
        }

        return $fm;
    }

    /**
     * tags-Frontmatter auf eine Liste bringen — deckt Block-Sequenz (schon
     * Array), Flow-Sequenz `[a, b, c]` und Einzel-Skalar ab.
     *
     * @param  string|list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags($tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar((string) $t), $tags), fn ($t) => $t !== ''));
        }
        $s = trim((string) $tags);
        if ($s === '') {
            return [];
        }
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $s = substr($s, 1, -1);
        }

        return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar($t), explode(',', $s)), fn ($t) => $t !== ''));
    }

    private function frontmatterScalar(string $s): string
    {
        $s = trim($s);
        if (strlen($s) >= 2 && ($s[0] === '"' || $s[0] === "'") && $s[-1] === $s[0]) {
            $s = substr($s, 1, -1);
        }

        return trim($s);
    }

    /** Einzelnes Wissens-Dokument per Slug (aktiv, nicht gelöscht). */
    public function getDocument(?Team $team, string $slug): ?object
    {
        return DB::table('foodalchemist_knowledge_documents')->tap($this->nurSichtbar($team))
            ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')
            ->first(['slug', 'title', 'category', 'art', 'geltung', 'datenwerte', 'version', 'char_count', 'content_md']);
    }
}
