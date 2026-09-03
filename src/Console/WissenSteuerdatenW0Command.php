<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * Welle 0 — Steuerdaten für Routings + Layer-Bindings (W0-3 Daten-Hälfte, W0-4 Daten-Hälfte).
 *
 * WARUM ALS COMMAND UND NICHT ALS HAND-SQL:
 * Die beiden Änderungen hängen voneinander ab und scheitern beide STILL.
 *   · `regelwerk:discovery → none` nimmt dem Generator den Routing-Weg zum Regelwerk.
 *   · Die Bau-§-Dossiers `discovery → always` geben ihm stattdessen den Bindings-Weg.
 * Landet nur die erste Hälfte, generiert der Generator ab sofort OHNE jedes Regelwerk —
 * und niemand merkt es: `regelwerkBlock()` gibt bei fehlendem Treffer `null` zurück,
 * `selectBoundKnowledge()` ein leeres Array. Kein Fehler, kein Log, nur schlechtere
 * Rezepte. Darum: eine Transaktion, danach ein maschineller Post-Condition-Assert.
 *
 * Idempotent (reine UPDATEs auf Zielwerte), `--verify` ist read-only.
 *
 * Reihenfolge: `--dry-run` → `--apply` → `--verify`. Rollback = die vor dem Lauf
 * gesicherten CSVs von `foodalchemist_knowledge_routings` / `_bindings`.
 */
class WissenSteuerdatenW0Command extends Command
{
    protected $signature = 'foodalchemist:wissen-steuerdaten-w0
        {--apply    : Änderungen schreiben (ohne dieses Flag nur Vorschau)}
        {--verify   : Nur prüfen: sitzen Routings, Bindings und der Regelwerk-Pfad?}
        {--team=6   : Team, dessen Generator-Pfad geprüft wird}';

    protected $description = 'Welle 0: Routing-Deckel trimmen + Bau-§-Dossiers als always binden (eine Transaktion + Assert)';

    /**
     * Ziel-Routings für `ai_generate_recipe`.
     *
     * NICHT enthalten und bewusst unangetastet:
     *   · `cross_cutting` und `domain` — beide werten `max_docs`/`max_chars_per_doc` NICHT
     *     aus (die Routing-Zeile ist dort nur ein Boolean-Gate). Sie sind ausschließlich
     *     über die Konstanten in KnowledgeContextService steuerbar; ein UPDATE hier wäre
     *     ein No-op, der Wirkung vortäuscht.
     *   · `pairing` — `pairingBlock()` speist sich aus dem ANKER-GRAPHEN (PairingService),
     *     nicht aus `category='pairing'`-Docs (die es in der DB gar nicht mehr gibt).
     *     `none` würde die verifizierten Pairing-Partner aus jedem Rezept-Prompt entfernen:
     *     fachlicher Verlust ohne Token-Gewinn.
     *
     * @var array<string, array{mode: string, max_docs: int|null, max_chars: int|null}>
     */
    /**
     * `public`, damit {@see KnowledgePolicySeedCommand} und der Drift-Test dieselbe Quelle lesen
     * können. Zwei Listen, die dasselbe behaupten, driften auseinander — und die Divergenz fiele
     * erst beim nächsten Neuaufbau auf, also genau dann, wenn niemand sie erwartet.
     */
    public const ROUTINGS = [
        // Kommt ab jetzt vollständig über die Layer-Bindings (W0-3) statt als
        // relevanz-gerankte Discovery. `regelwerkBlock()` holte per ->first() ohnehin
        // nur EIN Doc und wurde auf min(RECIPE_MAX_CHARS_PER_DOC) geklemmt.
        'regelwerk' => ['mode' => 'none', 'max_docs' => null, 'max_chars' => null],
        // Haupt-Fehltreffer-Quelle des Slug-Rankings (Referenzgerichte matchen auf
        // Gerichtnamen, nicht auf Bau-Wissen).
        'referenzgericht' => ['mode' => 'none', 'max_docs' => null, 'max_chars' => null],
        'kueche' => ['mode' => 'discovery', 'max_docs' => 2, 'max_chars' => 2500],
        'weltkueche' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 2000],
        'signatur_kuechen' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 2000],
        'kreativ_input' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 2000],
        'niveau' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 1800],
        'ernaehrung' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 1500],
        'prasentation_service' => ['mode' => 'discovery', 'max_docs' => 1, 'max_chars' => 1500],
    ];

    /**
     * Die Bau-Regeln, die JEDES generierte Rezept braucht und die per Discovery
     * strukturell nicht surfacen können, weil sie kein Gericht nennen (§2 Verarbeitungs-
     * Reduktion, §3 Pürees, §4 Sub-Rezept-Hierarchie, §5 Default-GPs, §6 Mengen/Yield,
     * §7 Allergen-Vererbung) — plus das Erstellungs-Dossier.
     *
     * `substitutionen` (9.851 Z.) und `mengen_defaults` (7.446 Z.) bleiben `discovery`:
     * sie sind ZUTATENABHÄNGIG. Als `always` würden sie über den Score-Bonus die Bau-§§
     * aus dem Gesamtdeckel verdrängen — genau der Fehler, den W0-3 behebt.
     *
     * @var list<string>
     */
    private const ALWAYS_SLUGS_BAU = [
        'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form',
        'regelwerk-basisrezepte-3-purees-marks-coulis',
        'regelwerk-basisrezepte-4-sub-rezept-hierarchie-stubs',
        'regelwerk-basisrezepte-6-mengen-einheiten-yield',
    ];

    /**
     * ENTBUNDEN — Dossiers, die aus den Generator-Prompts RAUS sollen, weil der Code die
     * Regel deterministisch erzwingt (Architektur-Entscheid 2026-09-02: Zwänge in Resolver
     * und Validatoren, nicht in den Prompt).
     *
     * §5 Default-GPs (4.796 Z., das grösste gebundene Dossier): `MatchHeuristics::defaultGpAlias()`
     * setzt die Standard-Variante mit Score 0,97 — auf demo an 12 von 13 Generika verifiziert
     * (Zucker→Raffinade weiss, Salz→unjodiert, Mehl→Type 405, Milch→3,5 %, Sahne→30 % …).
     * Das Dossier selbst dokumentiert das: „Der GP-Matcher erzwingt §5 jetzt deterministisch."
     * Was das Modell kontrolliert — generische Benennung, damit der Alias überhaupt feuert —
     * steht jetzt kompakt im Generator-Task statt als Tabelle im Kontext.
     *
     * Das Dossier bleibt AKTIV im Korpus: Schicht 3 lädt per Präfix und muss §5 zitieren können.
     * Nur die Prompt-Bindung fällt (active=0, reversibel — kein Delete).
     *
     * @var list<string>
     */
    private const ENTBUNDEN = [
        // `workflow.rezept_anlegen_mcp` (6.761 Z.) — IST KEIN REGELWERK. Der Frontmatter sagt
        // es selbst: `typ: Skill_Workflow`, `code: fa.basisrezept_anlegen`,
        // `zielgruppe: agent`, plus eine `required_tools`-Liste von MCP-Tools. Es ist eine
        // Werkzeug-Reihenfolge für einen EXTERNEN MCP-Client — der Generator ruft keine
        // MCP-Tools auf, er liefert JSON. (Dominique 2026-09-03: „workflow rezept anlegen
        // sagt es ja schon, ist kein Regelwerk, ist für die MCP".)
        //
        // Es lag als `always` am Präfix `recipe` und damit im »VERBINDLICHEN REGELWERK«
        // JEDES `recipe.*`-Prompts. Damit widersprach die Bindung auch der eigenen
        // Import-Doku: „MCP-Orchestrierungs-Workflows — searchbar, NICHT always-geroutet".
        //
        // Die Wirkung ist die ganze Kappung: always-Summe 25.282 Z. bei Deckel 19.000 →
        // 6.282 Z. verbindliches Regelwerk wurden pro Call abgeschnitten, und weil alle
        // Gewichte 0 sind, hatte niemand entschieden welche. Ohne dieses Dossier: 18.521 Z.
        // — alle §-Dossiers passen, nichts wird mehr gekappt. Eine falsche Bindung, ein
        // gelöstes Budget-Problem.
        'workflow.rezept_anlegen_mcp',

        // §5 Default-GPs (4.796 Z.) — MatchHeuristics::defaultGpAlias() erzwingt die Tabelle
        // deterministisch (Score 0,97, auf demo an 12 von 13 Generika verifiziert). Die
        // Benennungs-Direktive, die das Modell wirklich kontrolliert, steht kompakt im Task.
        'regelwerk-basisrezepte-5-default-gps-fur-generische-zutaten',

        // §7 Allergen-/Zusatzstoff-Vererbung (1.599 Z.) — das Modell liefert NIE Allergene:
        // RecipeRecomputeService::allergene() aggregiert sie aus GP-/Lieferanten-Stammdaten,
        // inklusive der rekursiven §7-Konfidenz („unsicheres Sub → unbekannt"). Was das
        // Modell braucht, ist allein `allergen_nogo` als Constraint — das steht als Parameter
        // im Task und wird nach der Erzeugung vom Diät-/Allergen-Gate deterministisch geprüft
        // (verletzende Zeilen werden ENTdrahtet). Die Vererbungsregel selbst ist reiner Code.
        'regelwerk-basisrezepte-7-allergen-zusatzstoff-vererbung',

        // `substitutionen` (9.851 Z.) — LIEFERT HEUTE NICHTS NUTZBARES. Nach den Pflicht-
        // Dossiers und `mengen_defaults` bleibt bei recipe.generator gar kein Budget (Rest
        // < 500 → ganz verworfen) und bei vk.generator ein 980-Zeichen-Kopf-Fragment einer
        // Substitutionstabelle. Ein Tabellen-Anschnitt ist kein Wissen, nur Kosten.
        // Substitution ist ZUTATENABHÄNGIG — sie gehört chunk-genau geholt (die zwei
        // relevanten Zeilen), nicht als Volltext-Dump gedeckelt. Bleibt aktiv im Korpus und
        // über cross_cutting-Discovery erreichbar, wenn die Query wirklich darauf zeigt.
        'substitutionen',
    ];

    /**
     * Nur für Basisrezepte: das Erstellungs-Dossier. An `vk.generator` ist es bewusst
     * NICHT gebunden (und soll es nicht sein) — ein Verkaufsgericht wird nicht nach der
     * Basisrezept-Anlage gebaut, dafür hat VK sein eigenes Regelwerk.
     *
     * @var list<string>
     */
    private const ALWAYS_SLUGS_BASIS = ['workflow.basisrezept_erstellungs_dossier'];

    /**
     * Universelles Wissen, das JEDER Generierung zusteht — und das deshalb `always` sein
     * MUSS, nicht `discovery`.
     *
     * `mengen_defaults` ist Portions-/Mengen-Grundwissen ohne Bezug zu einer konkreten
     * Zutat; ein Score-Gate darüber ist sinnlos (es matcht mal, mal nicht). Zweiter,
     * wichtigerer Grund: nur wenn ALLE gebundenen Dossiers `always` sind, ist der
     * Bound-Block über alle Calls eines Prompt-Keys BYTE-IDENTISCH — und erst dann kann
     * er als stabiler Cache-Prefix dienen (W3-1). Ein score-gegatetes Dossier im Block
     * würde den Prefix bei jedem zweiten Aufruf brechen.
     *
     * @var list<string>
     */
    private const ALWAYS_SLUGS_UNIVERSAL = ['mengen_defaults'];

    /** Nur für Gerichte: das VK-Regelwerk (Naming-Skelett + Modell-A-Klassifikation). */
    private const ALWAYS_SLUGS_VK = ['regelwerk.regelwerk_verkaufsgerichte'];

    /**
     * UMBINDEN — Dossiers, die am FALSCHEN Ziel hängen. Prinzip (Dominique 2026-09-03):
     * „die Dossiers da nutzen, wo sie auch schlussendlich benutzt werden."
     *
     * Beide hingen am Bereichs-Präfix `recipe` und wurden damit von ALLEN 22 `recipe.*`-Prompts
     * mitgeschluckt (siehe die Präfix-Erklärung bei ENTBUNDEN). Zusammen 17.759 Zeichen, die bei
     * einer Pflichtmenge von 18.521 und einem Deckel von 19.000 als Anschnitt ankamen oder als
     * `dropped` verpufften — gemessen 8.238 Zeichen gebaut und weggeworfen, pro Call. Zusätzlich
     * machten sie den Bound-Block call-abhängig und damit als Cache-Prefix unbrauchbar (W3-1).
     *
     *  · `produktion-arbeitszeit-und-personenminuten` (7.089 Z.) → `recipe.eigenschaften`.
     *    Das ist der einzige Prompt, der Arbeitszeit tatsächlich SETZT (`work_time_min`,
     *    Arbeitszeit, Minuten — die anderen Zeit-nahen Keys sind `recipe.production_depth`
     *    (Fertigungstiefe) und `recipe.equipment` (Geräte), beide ohne Minuten).
     *    Dominique: „Arbeitszeit bei den Stammdaten zum Einfüllen der Kochzeiten."
     *
     *  · `geschmacksbalance` (10.670 Z.) → `recipe.generator` UND `vk.generator`.
     *    Dominique: „Geschmacksbalance, ja, braucht es bei Gerichten und Basisrezepten."
     *    Als `always`, nicht `discovery`: ein score-gegatetes Dossier käme mal ganz, mal als
     *    Fragment, mal nicht — bei Wissen, das laut Entscheid IMMER gebraucht wird, ist das
     *    die schlechtere Hälfte. Und nur `always` hält den Block byte-identisch (W3-1); ein
     *    GRÖSSERER stabiler Prefix ist unter Prompt-Caching billiger, nicht teurer.
     *
     * Umbinden heisst NICHT deaktivieren: das Dossier bleibt im Korpus und für die
     * semantische Suche erreichbar — es verlässt nur den falschen Prompt.
     *
     * @var array<string, list<string>>
     */
    private const UMBINDEN = [
        'produktion-arbeitszeit-und-personenminuten' => ['recipe.eigenschaften'],
        'geschmacksbalance' => ['recipe.generator', 'vk.generator'],
    ];

    public function handle(): int
    {
        if ($this->option('verify')) {
            return $this->verify();
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'W0-Steuerdaten: SCHREIBEN' : 'W0-Steuerdaten: VORSCHAU (--apply zum Schreiben)');

        $plan = [];
        foreach (self::ROUTINGS as $category => $ziel) {
            $ist = DB::table('foodalchemist_knowledge_routings')
                ->where('feature', 'ai_generate_recipe')->where('category', $category)->first();
            if ($ist === null) {
                $plan[] = [$category, '—', 'FEHLT (kein Routing)', 'übersprungen'];

                continue;
            }
            $vorher = "{$ist->mode}/" . ($ist->max_docs ?? '-') . '/' . ($ist->max_chars_per_doc ?? '-');
            $nachher = "{$ziel['mode']}/" . ($ziel['max_docs'] ?? '-') . '/' . ($ziel['max_chars'] ?? '-');
            $plan[] = [$category, $vorher, $nachher, $vorher === $nachher ? 'unverändert' : 'UPDATE'];
        }
        $this->table(['category', 'ist (mode/docs/chars)', 'soll', 'aktion'], $plan);

        $bPlan = [];
        // Entbundene Dossiers dürfen den Prompt nicht mehr erreichen — sonst zahlt man die
        // Zeichen doppelt (Dossier + Task-Direktive).
        foreach (self::ENTBUNDEN as $slug) {
            $aktiv = DB::table('foodalchemist_knowledge_bindings as b')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                ->whereNull('b.deleted_at')->where('b.active', 1)->where('b.binding_type', 'layer')
                ->where('d.slug', $slug)->count();
            if ($aktiv > 0) {
                $fehler[] = "«{$slug}» ist noch an {$aktiv} Layer aktiv gebunden — der Code erzwingt die Regel, das Dossier gehört nicht mehr in den Prompt.";
            }
            $imKorpus = DB::table('foodalchemist_knowledge_documents')
                ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')->count();
            if ($imKorpus === 0) {
                $fehler[] = "«{$slug}» ist im Korpus inaktiv — Schicht 3 kann §5 dann nicht mehr zitieren. Entbinden heisst NICHT deaktivieren.";
            }
        }

        foreach ($this->bindingZiele() as $targetKey => $slugs) {
            foreach ($slugs as $slug) {
                $row = DB::table('foodalchemist_knowledge_bindings as b')
                    ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                    ->whereNull('b.deleted_at')->where('b.binding_type', 'layer')
                    ->where('b.target_key', $targetKey)->where('d.slug', $slug)
                    ->first(['b.id', 'b.mode', 'd.char_count', 'd.active']);
                // 0 statt '' in der Zeichen-Spalte: `collect($bPlan)->sum(4)` unten wirft
                // sonst »Unsupported operand types: int + string«. Vorher unerreichbar, weil
                // jede Soll-Bindung existierte — mit den UMBINDEN-Zielen ist »FEHLT (kein
                // Binding)« der NORMALFALL beim ersten Lauf.
                $bPlan[] = $row === null
                    ? [$targetKey, $slug, '—', 'ANLEGEN (kein Binding)', 0]
                    : [$targetKey, $slug, $row->mode, $row->mode === 'always' ? 'unverändert' : 'always', $row->char_count];
            }
        }
        $this->table(['target_key', 'slug', 'ist mode', 'soll', 'chars'], $bPlan);

        foreach (array_keys($this->bindingZiele()) as $tk) {
            $summe = collect($bPlan)->where(0, $tk)->sum(4);
            $deckel = $this->boundDeckel((string) $tk);
            $this->line(sprintf(
                'Bound-Summe %-18s %s Zeichen (Deckel: %s)',
                $tk,
                number_format((int) $summe, 0, ',', '.'),
                number_format($deckel, 0, ',', '.'),
            ));
            if ($summe > $deckel) {
                $this->warn("⚠ {$tk}: Bound-Summe über dem Deckel — die letzten Dossiers werden gekappt.");
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Nichts geschrieben. Mit --apply ausführen, danach --verify.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            foreach (self::ROUTINGS as $category => $ziel) {
                DB::table('foodalchemist_knowledge_routings')
                    ->where('feature', 'ai_generate_recipe')->where('category', $category)
                    ->update([
                        'mode' => $ziel['mode'],
                        'max_docs' => $ziel['max_docs'],
                        'max_chars_per_doc' => $ziel['max_chars'],
                        'updated_at' => now(),
                    ]);
            }
            foreach ($this->bindingZiele() as $targetKey => $slugs) {
                $ids = DB::table('foodalchemist_knowledge_documents')
                    ->whereIn('slug', $slugs)->whereNull('deleted_at')->pluck('id');
                DB::table('foodalchemist_knowledge_bindings')
                    ->where('binding_type', 'layer')->where('target_key', $targetKey)
                    ->whereIn('knowledge_document_id', $ids)->whereNull('deleted_at')
                    ->update(['mode' => 'always', 'active' => 1, 'updated_at' => now()]);

                // Code-erzwungene Dossiers aus dem Prompt nehmen (active=0, nicht löschen).
                //
                // AUCH DER BEREICHS-PRÄFIX. `selectBoundKnowledge()` liest
                // `whereIn('target_key', [$promptKey, $bereich])` — der Gateway nimmt also
                // sowohl `recipe.generator` ALS AUCH `recipe`. Dieser Befehl behandelte nur
                // den Prompt-Key; ein Dossier am Präfix blieb unsichtbar für ihn und landete
                // trotzdem in jedem Prompt. Genau so überlebte die MCP-Anleitung (6.761 Z.)
                // am Präfix `recipe` alle bisherigen Läufe. Selbst hineingelaufen — dieselbe
                // Präfix-Blindheit steckte auch in der W3-1-Vorbedingung unten.
                $ziele = array_values(array_unique([$targetKey, explode('.', $targetKey)[0]]));
                $entIds = DB::table('foodalchemist_knowledge_documents')
                    ->whereIn('slug', self::ENTBUNDEN)->whereNull('deleted_at')->pluck('id');
                if ($entIds->isNotEmpty()) {
                    DB::table('foodalchemist_knowledge_bindings')
                        ->where('binding_type', 'layer')->whereIn('target_key', $ziele)
                        ->whereIn('knowledge_document_id', $entIds)->whereNull('deleted_at')
                        ->update(['active' => 0, 'updated_at' => now()]);
                }
            }

            // UMBINDEN: vom falschen Ziel weg, an die Prompts, die das Dossier wirklich brauchen.
            // Anders als die Blöcke oben reicht hier kein UPDATE — am neuen Ziel existiert
            // vielleicht noch gar keine Zeile. Darum: alles ausserhalb der erlaubten Ziele still
            // legen (active=0, nicht löschen — reversibel), dann je erlaubtem Ziel eine
            // `always`-Bindung sicherstellen.
            foreach (self::UMBINDEN as $slug => $zielKeys) {
                $doc = DB::table('foodalchemist_knowledge_documents')
                    ->where('slug', $slug)->whereNull('deleted_at')->first(['id', 'team_id']);
                if ($doc === null) {
                    continue;
                }

                DB::table('foodalchemist_knowledge_bindings')
                    ->where('binding_type', 'layer')
                    ->where('knowledge_document_id', $doc->id)
                    ->whereNotIn('target_key', $zielKeys)
                    ->whereNull('deleted_at')
                    ->update(['active' => 0, 'updated_at' => now()]);

                foreach ($zielKeys as $ziel) {
                    $vorhanden = DB::table('foodalchemist_knowledge_bindings')
                        ->where('binding_type', 'layer')->where('target_key', $ziel)
                        ->where('knowledge_document_id', $doc->id)->whereNull('deleted_at')
                        ->first(['id']);

                    if ($vorhanden !== null) {
                        DB::table('foodalchemist_knowledge_bindings')->where('id', $vorhanden->id)
                            ->update(['mode' => 'always', 'active' => 1, 'updated_at' => now()]);

                        continue;
                    }

                    DB::table('foodalchemist_knowledge_bindings')->insert([
                        'uuid' => (string) Str::uuid(),
                        // team_id vom Dokument erben — eine Bindung darf nicht sichtbarer sein
                        // als das Dossier, an dem sie hängt.
                        'team_id' => $doc->team_id,
                        'knowledge_document_id' => $doc->id,
                        'binding_type' => 'layer',
                        'target_key' => $ziel,
                        'mode' => 'always',
                        'weight' => 50,
                        'active' => 1,
                        'source' => 'wissen-steuerdaten-w0',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->info('Geschrieben (eine Transaktion).');

        return $this->verify();
    }

    /**
     * Bound-Gesamtdeckel eines Prompt-Keys. Muss dieselbe Quelle lesen wie
     * AiGatewayService::boundBudget() — ein hart verdrahteter Wert hier hätte
     * `vk.generator` (Budget 28.000) fälschlich als Überlauf gemeldet.
     */
    private function boundDeckel(string $promptKey): int
    {
        $konfig = config('foodalchemist.ai.bound_knowledge_budget', []);
        if (is_array($konfig) && isset($konfig[$promptKey]['total'])) {
            return (int) $konfig[$promptKey]['total'];
        }

        // Fallback = konservativer Default in AiGatewayService (3 × 1.400).
        return 4200;
    }

    /** @return array<string, list<string>> */
    private function bindingZiele(): array
    {
        $ziele = [
            'recipe.generator' => array_merge(self::ALWAYS_SLUGS_BAU, self::ALWAYS_SLUGS_BASIS, self::ALWAYS_SLUGS_UNIVERSAL),
            'vk.generator' => array_merge(self::ALWAYS_SLUGS_BAU, self::ALWAYS_SLUGS_VK, self::ALWAYS_SLUGS_UNIVERSAL),
        ];

        // Die Umbindungen gehören in dieselbe Soll-Sicht — sonst prüft `verify()` sie nicht
        // und die Deckel-Rechnung unten zählt sie nicht mit (genau der blinde Fleck, durch den
        // die MCP-Anleitung am Präfix jahrelang überlebte).
        foreach (self::UMBINDEN as $slug => $zielKeys) {
            foreach ($zielKeys as $key) {
                $ziele[$key] = array_values(array_unique(array_merge($ziele[$key] ?? [], [$slug])));
            }
        }

        return $ziele;
    }

    /**
     * Post-Condition-Assert: nicht „sieht gut aus", sondern „die Bau-§§ sind im Prompt".
     * Prüft den EINZIGEN Pfad, der nach W0-4 noch Regelwerk liefert (Layer-Bindings),
     * plus dass der Routing-Weg wirklich zu ist.
     */
    /**
     * Meldet Steuerdaten-Drift als SIGNAL, nicht als Log-Zeile.
     *
     * Der Grund für den Kanal: die Steuerdaten sind per Hand editierbar (MCP, SQL), und genau das
     * ist schon passiert — die Live-Tabelle trug `regelwerk|discovery|4x8000`, wo die Migration
     * `always|1|7000` gesetzt hatte. Ein Regelwerk, das leise aus dem Prompt fällt, erzeugt
     * KEINEN Fehler: der Generator läuft weiter und liefert nur schlechtere Rezepte. Ein
     * Scheduler-Lauf, der das in `laravel.log` schreibt, ist deshalb wertlos — er hätte dieselbe
     * Eigenschaft wie der Deckel-Vermerk in `params`: technisch vorhanden, faktisch unsichtbar.
     *
     * Deshalb Signale-Cockpit, mit `dedup_key` (ein offenes Signal je Team, nicht eines je Lauf)
     * und mit `schliesseGemessen`, wenn die Drift weg ist — sonst bleibt eine behobene Warnung
     * stehen und stumpft den Riegel ab.
     *
     * @param  list<string>  $fehler
     */
    private function meldeDrift(array $fehler): void
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->warn('Kein Team #' . $this->option('team') . ' — Drift wird nicht als Signal gemeldet.');

            return;
        }

        $signale = app(SignalService::class);

        if ($fehler === []) {
            $signale->schliesseGemessen(
                $team,
                SignalTyp::SteuerdatenDrift,
                'wissen-steuerdaten',
                'wissen-steuerdaten-w0',
                'Steuerdaten stimmen wieder mit dem Soll — automatisch geschlossen',
            );

            return;
        }

        $signale->erzeuge(
            $team,
            SignalTyp::SteuerdatenDrift,
            SignalSeverity::Warnung,
            count($fehler) . ' Abweichung(en) in den Wissens-Steuerdaten',
            [
                'dedup_key' => 'wissen-steuerdaten',
                'source' => 'wissen-steuerdaten-w0',
                'description' => "Die live wirksamen Routings/Bindings weichen vom Soll ab. Der Generator "
                    . "läuft dabei weiter — er bekommt nur weniger oder anderes Regelwerk, ohne Fehlermeldung.\n\n· "
                    . implode("\n· ", $fehler)
                    . "\n\nBeheben: `php artisan foodalchemist:wissen-steuerdaten-w0 --apply`, danach `--verify`.",
                'payload' => ['abweichungen' => $fehler],
            ],
        );
    }

    private function verify(): int
    {
        $fehler = [];

        $rw = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')->first();
        if ($rw !== null && $rw->mode !== 'none') {
            $fehler[] = "Routing regelwerk steht auf '{$rw->mode}' statt 'none' — Doppelweg offen.";
        }

        foreach ($this->bindingZiele() as $targetKey => $slugs) {
            foreach ($slugs as $slug) {
                $row = DB::table('foodalchemist_knowledge_bindings as b')
                    ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                    ->whereNull('b.deleted_at')->where('b.binding_type', 'layer')
                    ->where('b.target_key', $targetKey)->where('d.slug', $slug)
                    ->first(['b.mode', 'b.active', 'd.active as doc_active', 'd.char_count']);
                if ($row === null) {
                    $fehler[] = "{$targetKey}: Binding für «{$slug}» fehlt — Regel erreicht den Prompt nicht.";

                    continue;
                }
                if ($row->mode !== 'always') {
                    $fehler[] = "{$targetKey}: «{$slug}» steht auf mode='{$row->mode}' — score-gated, surft bei prozeduralen Regeln nicht auf.";
                }
                if (! $row->active || ! $row->doc_active) {
                    $fehler[] = "{$targetKey}: «{$slug}» ist inaktiv (binding={$row->active}, doc={$row->doc_active}).";
                }
            }
        }

        // W3-1-Voraussetzung: KEIN `discovery`-Binding an den Generatoren. Ein score-gegatetes
        // Dossier im Bound-Block macht ihn call-abhängig und zerstört den Cache-Prefix.
        foreach (array_keys($this->bindingZiele()) as $tk) {
            $dyn = DB::table('foodalchemist_knowledge_bindings as b')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                ->whereNull('b.deleted_at')->where('b.active', 1)->where('b.binding_type', 'layer')
                // Präfix mitprüfen: der Gateway matcht [$promptKey, $bereich], eine
                // score-gegatete Bindung am Präfix bricht den Cache-Prefix genauso.
                ->whereIn('b.target_key', array_values(array_unique([$tk, explode('.', $tk)[0]])))
                ->where('b.mode', '!=', 'always')
                ->pluck('d.slug')->all();
            if ($dyn !== []) {
                $fehler[] = "{$tk}: nicht-always-Bindings vorhanden (" . implode(', ', $dyn)
                    . ') — der Bound-Block ist damit call-abhängig und taugt nicht als Cache-Prefix (W3-1).';
            }
        }

        // Deckel-Realitätscheck: passen die Pflicht-Dossiers überhaupt in das Budget?
        foreach ($this->bindingZiele() as $targetKey => $slugs) {
            $summe = (int) DB::table('foodalchemist_knowledge_documents')
                ->whereIn('slug', $slugs)->whereNull('deleted_at')->sum('char_count');
            $deckel = $this->boundDeckel($targetKey);
            $this->line(sprintf(
                '%-18s Pflicht-Dossiers: %s Zeichen (Deckel %s)',
                $targetKey,
                number_format($summe, 0, ',', '.'),
                number_format($deckel, 0, ',', '.'),
            ));
            if ($summe > $deckel) {
                $fehler[] = "{$targetKey}: Pflicht-Dossiers summieren {$summe} Zeichen > Deckel {$deckel} — das letzte Dossier wird still gekappt.";
            }
        }

        // W0-5-Invariante über ALLE Features: Budget >= Pflichtmenge (always-Routings).
        // Ist der Deckel kleiner, kappt er genau das Pflichtwissen — still.
        $kcs = app(KnowledgeContextService::class);
        $features = DB::table('foodalchemist_knowledge_routings')
            ->where('mode', 'always')->distinct()->pluck('feature');
        $zeilen = [];
        foreach ($features as $feature) {
            $pflicht = $kcs->pflichtZeichen((string) $feature);
            $budget = $kcs->budgetFuer((string) $feature);
            $ok = $budget >= $pflicht;
            $zeilen[] = [$feature, number_format($pflicht, 0, ',', '.'), number_format($budget, 0, ',', '.'), $ok ? 'ok' : 'ZU KLEIN'];
            if (! $ok) {
                $fehler[] = "Budget von «{$feature}» ist {$budget} Zeichen, die always-gerouteten Pflicht-Inhalte brauchen {$pflicht} — das letzte Pflicht-Dossier wird still abgeschnitten.";
            }
        }
        if ($zeilen !== []) {
            $this->newLine();
            $this->line('W0-5-Invariante — Budget muss die Pflicht-Inhalte tragen:');
            $this->table(['feature', 'pflicht (always)', 'budget', 'status'], $zeilen);
        }

        $this->line(sprintf(
            'Konstanten: RECIPE_MAX_KNOWLEDGE_CHARS=%d, RECIPE_MAX_CHARS_PER_DOC=%d, CROSS_CUTTING=%d, DOMAIN=%d',
            KnowledgeContextService::RECIPE_MAX_KNOWLEDGE_CHARS,
            KnowledgeContextService::RECIPE_MAX_CHARS_PER_DOC,
            KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS,
            KnowledgeContextService::DOMAIN_TRUNCATE_CHARS,
        ));

        $this->meldeDrift($fehler);

        if ($fehler !== []) {
            $this->newLine();
            $this->error('ASSERT FEHLGESCHLAGEN — der Generator läuft (teilweise) ohne Regelwerk:');
            foreach ($fehler as $f) {
                $this->line("  · {$f}");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Assert grün: Routing-Doppelweg zu, alle Bau-§-Dossiers als always gebunden und aktiv.');

        return self::SUCCESS;
    }
}
