<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Welle 0 — Steuerdaten-Wächter für die Generator-Routings (und heute: für ihren Kanon).
 *
 * WARUM ALS COMMAND UND NICHT ALS HAND-SQL: die Änderung scheitert STILL.
 * `regelwerk:discovery → none` nimmt dem Generator den Routing-Weg zum Regelwerk; kommt der
 * Ersatz nicht an, generiert er ab sofort ohne jedes Regelwerk, ohne Fehler und ohne Log —
 * nur mit schlechteren Rezepten. Darum: eine Transaktion, danach ein maschineller
 * Post-Condition-Assert, und `--verify` wöchentlich als Signal.
 *
 * ★ **Der Ersatz hat sich zweimal geändert, der Wächter wandert mit.**
 *   1. W0-4 (Welle 0): der Ersatz waren `always`-Layer-BINDUNGEN.
 *   2. Welle 2 (Spec 50): der KANON kam dazu und gewann je Prompt-Key; Bindungen wurden
 *      Fallback.
 *   3. Spec 52 · F2 (2026-09-08): der Bindungs-Kanal ist WEG. Der Kanon ist der einzige
 *      Weg, und ein leerer Kanon an einem Generator-Key ist deshalb ein Fehler — vorher
 *      fiel dieser Fall stillschweigend in die Bindungen.
 *
 * Was dieser Befehl deshalb NICHT mehr tut: Bindungen anlegen, umbinden oder stilllegen.
 * Die Soll-Liste „welches Dossier muss verbindlich in diesen Prompt" stand dafür als
 * Konstante im Code und driftete (Befund `H1`); sie liegt heute im Kanon, kuratiert, und
 * gesichert in `database/kanon/`.
 *
 * Idempotent (reine UPDATEs auf Zielwerte), `--verify` ist read-only.
 *
 * Reihenfolge: `--dry-run` → `--apply` → `--verify`. Rollback = das vor dem Lauf gesicherte
 * CSV von `foodalchemist_knowledge_routings`.
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
     * ⚠ SEIT WELLE 2 (Spec 50, 2026-09-06) NUR NOCH FALLBACK: die Prompt-Keys `recipe.generator`
     * und `vk.generator` haben einen KANON (`foodalchemist_knowledge_canon`, `pflicht`-Dossiers,
     * `KnowledgeCanonService::documentsFor`). Ist ein Kanon vorhanden, sind die Layer-Bindings im
     * Gateway stumm (`AiGatewayService`: `$kanonBlock === null && …bindings…`) — und die hier
     * gelisteten Original-Dossiers (`mengen_defaults`, `geschmacksbalance`,
     * `regelwerk.regelwerk_verkaufsgerichte`) sind DEAKTIVIERT, weil sie in Ein-Thema-Splits
     * zerlegt wurden. `--verify` prüft darum je Ziel zuerst den Kanon und fällt nur ohne Kanon
     * auf die Bindings zurück (`kanonZeilen()`); `--apply` legt an Kanon-Zielen keine Bindings an
     * und bindet nie ein inaktives Dossier. Die Listen bleiben für Sandboxen ohne Kanon.
     *
     * @var list<string>
     */
    /**
     * ENTBUNDEN — Dossiers, die aus den Generator-Prompts RAUS sollen, weil der Code die
     * Regel deterministisch erzwingt (Architektur-Entscheid 2026-09-02: Zwänge in Resolver
     * und Validatoren, nicht in den Prompt). Gemessen begründet, s. `project_fa_regelwerk_
     * bindung_vs_durchsetzung`: §7 und §12 entbunden + code-erzwungen ergaben **0 Befunde**.
     *
     * ★ **Die Invariante ist geblieben, ihr Mechanismus hat sich geändert.** Bis Spec 52 · F2
     * prüfte dieser Wächter, dass die Slugs an keinen Layer GEBUNDEN sind — das war damals der
     * Weg in den Prompt. Bindungen wirken nicht mehr; der Weg in den Prompt ist jetzt der
     * KANON. Also wird gegen den Kanon geprüft. Dieselbe Aussage („dieses Dossier gehört nicht
     * in den Prompt"), an der Stelle, wo sie heute entschieden wird.
     *
     * Und weiterhin gilt: **Entbinden heisst NICHT deaktivieren.** Schicht 3 muss den § noch
     * zitieren können, also bleibt das Dossier im Korpus aktiv und suchbar.
     */
    private const ENTBUNDEN = [
        // `workflow.rezept_anlegen_mcp` (6.761 Z.) — IST KEIN REGELWERK. Der Frontmatter sagt
        // es selbst: `typ: Skill_Workflow`, `zielgruppe: agent`, plus eine `required_tools`-
        // Liste von MCP-Tools. Es ist eine Werkzeug-Reihenfolge für einen EXTERNEN MCP-Client
        // — der Generator ruft keine MCP-Tools auf, er liefert JSON. (Dominique 2026-09-03:
        // „workflow rezept anlegen sagt es ja schon, ist kein Regelwerk, ist für die MCP".)
        'workflow.rezept_anlegen_mcp',
        // §5 Default-GPs (4.796 Z., das grösste gebundene Dossier):
        // `MatchHeuristics::defaultGpAlias()` setzt die Zuordnung deterministisch — im Prompt
        // ist die Liste nur Kosten.
        'regelwerk-basisrezepte-5-default-gps-direct-overrides',
    ];

    /*
     * ── Gelöscht mit Spec 52 · F2/F3 (2026-09-08) ───────────────────────────────────────
     *
     * Hier standen `ALWAYS_SLUGS_BAU/BASIS/UNIVERSAL/VK`, `UMBINDEN` und `BINDING_SOURCE`:
     * die Soll-Liste, WELCHE Dossiers als `always`-Bindung an `recipe.generator` und
     * `vk.generator` hängen mussten, samt Umbindungen vom falschen Bereichs-Präfix weg.
     *
     * Das war Welle-0-Arbeit am Bound-Kanal. Diesen Kanal gibt es nicht mehr — und die
     * Aussage ist nicht verloren, sondern umgezogen: die vier Bau-§§, das
     * Erstellungs-Dossier, `mengen_defaults` und das VK-Regelwerk stehen heute als
     * `pflicht`-Zeilen im Kanon beider Generatoren (28 Zeilen, gesichert in
     * `database/kanon/kanon-team-6.json`). Der Sollzustand liegt damit dort, wo ihn ein
     * Mensch kuratiert, statt in einer Code-Liste, die driftet (Befund `H1`).
     *
     * `--verify` prüft weiterhin, dass die Kanon-Pflicht auflösbar ist und ins Budget passt.
     */

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

        $fehler = [];
        // ENTBUNDEN gegen den KANON prüfen, nicht mehr gegen Bindungen (s. Konstanten-Doc).
        foreach (self::ENTBUNDEN as $slug) {
            $imKanon = DB::table('foodalchemist_knowledge_canon as c')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'c.knowledge_document_id')
                ->whereNull('c.deleted_at')->where('c.active', 1)->where('d.slug', $slug)->count();
            if ($imKanon > 0) {
                $fehler[] = "«{$slug}» steht in {$imKanon} Kanon-Zeile(n) — der Code erzwingt die Regel, "
                    . 'das Dossier gehört nicht in den Prompt.';
            }
            $imKorpus = DB::table('foodalchemist_knowledge_documents')
                ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')->count();
            if ($imKorpus === 0) {
                $fehler[] = "«{$slug}» ist im Korpus inaktiv — Schicht 3 kann den § dann nicht mehr "
                    . 'zitieren. Entbinden heisst NICHT deaktivieren.';
            }
        }
        if ($fehler !== []) {
            $this->newLine();
            foreach ($fehler as $f) {
                $this->warn('⚠ '.$f);
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
            // Hier stand die Bindungs-Hälfte: `always`-Bindungen sicherstellen, entbundene
            // Dossiers stilllegen, Umbindungen vom Bereichs-Präfix weg. Alles Schreiben in eine
            // Tabelle, die der Gateway seit Spec 52 · F2 nicht mehr liest — also gelöscht statt
            // beibehalten. Der Sollzustand für „was muss verbindlich in diesen Prompt" liegt
            // im Kanon; `--verify` prüft ihn.
        });

        $this->info('Geschrieben (eine Transaktion).');

        return $this->verify();
    }

    /**
     * Kanon-Zeilen eines Prompt-Keys (Spec 50 Welle 2) — inklusive INAKTIVER Dossiers, damit
     * `--verify` sie melden kann (`KnowledgeCanonService::documentsFor` filtert die weg).
     * Sichtbarkeit wie der Gateway (global ∪ Ahnenkette des `--team`); ohne Team team-agnostisch.
     *
     * @return \Illuminate\Support\Collection<int, object{slug: string, mode: string, char_count: int, doc_active: int}>
     */
    private function kanonZeilen(string $targetKey, ?Team $team): \Illuminate\Support\Collection
    {
        $q = DB::table('foodalchemist_knowledge_canon as c')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'c.knowledge_document_id')
            ->whereNull('c.deleted_at')->whereNull('d.deleted_at')
            ->where('c.scope', 'prompt_key')->where('c.scope_key', $targetKey)
            ->where('c.role', 'root')->where('c.active', 1);
        if ($team !== null) {
            TeamScope::applyVisible($q, 'c.team_id', $team);
        }

        return $q->orderBy('c.ord')->get(['d.slug', 'c.mode', 'd.char_count', 'd.content_md', 'd.active as doc_active']);
    }

    /**
     * Bound-Gesamtdeckel eines Prompt-Keys. Muss dieselbe Quelle lesen wie
     * AiGatewayService::boundBudget() — ein hart verdrahteter Wert hier hätte
     * `vk.generator` (Budget 28.000) fälschlich als Überlauf gemeldet.
     */
    private function boundDeckel(string $promptKey): int
    {
        return (int) app(AiGatewayService::class)->boundBudgetFuer($promptKey)['total'];
    }

    /**
     * Die Prompt-Keys, deren Kanon dieser Wächter prüft.
     *
     * Hiess bis Spec 52 · F2 `bindingZiele()` und lieferte Key → Soll-Slug-Liste: die
     * Bindungen, die existieren MUSSTEN. Die Slug-Liste ist mit dem Kanal weggefallen; die
     * Soll-Liste liegt heute im Kanon selbst, kuratiert statt im Code (Befund `H1`). Übrig
     * bleibt die Frage, WELCHE Keys überhaupt einen tragen müssen — und das sind die beiden
     * Generatoren, für die Welle 0 den Kanal gebaut hat.
     *
     * @return list<string>
     */
    private function generatorKeys(): array
    {
        return ['recipe.generator', 'vk.generator'];
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
        $team = Team::find((int) $this->option('team'));
        $kanonZiele = [];

        $rw = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')->first();
        if ($rw !== null && $rw->mode !== 'none') {
            $fehler[] = "Routing regelwerk steht auf '{$rw->mode}' statt 'none' — Doppelweg offen.";
        }

        // Der Kanon ist seit Spec 52 · F2 die EINZIGE Quelle für „muss in diesen Prompt" —
        // vorher gab es darunter noch die Bindungs-Prüfung als Fallback für Umgebungen ohne
        // Kanon. Den Fallback gibt es nicht mehr, und damit ändert sich die Aussage: ein
        // LEERER Kanon an einem Generator-Key ist kein „dann eben Bindungen", sondern ein
        // Fehler. Genau der Fall, den `KanonSicherungService` reparieren kann.
        $dossierDeckel = app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->dossierMaxChars();
        $canonKeys = DB::table('foodalchemist_knowledge_canon as c')
            ->where('c.scope', 'prompt_key')->where('c.role', 'root')
            ->where('c.active', 1)->whereNull('c.deleted_at')
            ->when($team !== null, fn ($query) => TeamScope::applyVisible($query, 'c.team_id', $team))
            ->pluck('c.scope_key')->all();
        foreach (array_unique([...$this->generatorKeys(), ...$canonKeys]) as $targetKey) {
            $kanon = $this->kanonZeilen($targetKey, $team);
            if ($kanon->isEmpty()) {
                $fehler[] = "{$targetKey}: KEIN Kanon — dieser Prompt bekommt kein Regelwerk, und es gibt "
                    . 'keinen Fallback mehr. Sicherung einspielen: `foodalchemist:wissen-kanon-sicherung '
                    . 'import --team='.(int) $this->option('team').' --apply`.';

                continue;
            }
            $kanonZiele[] = $targetKey;
            $pflicht = $kanon->where('mode', 'pflicht');
            foreach ($pflicht as $z) {
                if (! $z->doc_active) {
                    $fehler[] = "{$targetKey}: Kanon-Pflicht «{$z->slug}» ist inaktiv — das Dossier fällt still aus dem Prompt.";
                }
                if ((int) $z->char_count > $dossierDeckel) {
                    $fehler[] = "{$targetKey}: Kanon-Pflicht «{$z->slug}» hat {$z->char_count} Zeichen > Dossier-Deckel {$dossierDeckel} — kein Ein-Thema-Dossier mehr.";
                }
            }
            $summe = app(AiGatewayService::class)->kanonPflichtZeichen($pflicht);
            $deckel = $this->boundDeckel($targetKey);
            $this->line(sprintf(
                '%-18s Kanon-Pflicht: %d Dossiers, %s Zeichen (Deckel %s)',
                $targetKey,
                $pflicht->count(),
                number_format($summe, 0, ',', '.'),
                number_format($deckel, 0, ',', '.'),
            ));
            if ($summe > $deckel) {
                $fehler[] = "{$targetKey}: Kanon-Pflicht summiert {$summe} Zeichen > Deckel {$deckel} — Budget-Config lügt über den Prompt.";
            }
        }

        // Hier standen drei Prüfblöcke über `knowledge_bindings`: „Soll-Binding fehlt",
        // „nicht-always-Bindung bricht den Cache-Prefix (W3-1)" und ein Deckel-Realitätscheck
        // über die Soll-Slug-Liste. Alle drei prüften einen Kanal, den es seit F2 nicht mehr
        // gibt — die Kanon-Prüfung oben hat ihre Aufgabe übernommen und deckt jetzt auch den
        // Fall „gar kein Kanon" ab, der vorher stillschweigend in die Bindungen fiel.
        //
        // Verbliebene Alt-Bindungen sind kein Fehler mehr, sondern Ballast; wer sie sehen
        // will, fragt `knowledge_bindings.GET` (dort mit `wirkungslos`-Zähler).

        // B4: Pflichtquellen messen, optionale Kandidatenmengen sind kein Budgetfehler.
        // Auch kanonische Keys prüfen, deren always-Politik aus einem Alias stammt.
        $kcs = app(KnowledgeContextService::class);
        $features = DB::table('foodalchemist_knowledge_routings')
            ->where('mode', 'always')->distinct()->pluck('feature')
            ->merge(array_keys((array) config('foodalchemist.prompts', [])))->unique();
        $zeilen = [];

        // Cross-Cutting-Wächter (2026-09-06): `cross_cutting:always` lädt eine FEST VERDRAHTETE
        // Slug-Liste (Konstante bzw. config `ai.cross_cutting_slugs`), und `crossCuttingDocs()`
        // überspringt fehlende/inaktive Slugs STILL. Genau so verloren `foodbook.kundentext` und
        // `concept.wording` beim Split-Cutover ihr Saison-/Synonym-Wissen, ohne dass irgendwo
        // etwas rot wurde. Jeder Slug, den ein always-Feature wirklich lädt, muss aktiv existieren.
        $ccFeatures = DB::table('foodalchemist_knowledge_routings')
            ->where('category', 'cross_cutting')->where('mode', 'always')->pluck('feature');
        foreach ($ccFeatures as $feature) {
            $soll = $kcs->crossCuttingSlugs((string) $feature);
            $aktiv = DB::table('foodalchemist_knowledge_documents')
                ->whereIn('slug', $soll)->where('category', 'cross_cutting')
                ->where('active', 1)->whereNull('deleted_at')->pluck('slug')->all();
            foreach (array_diff($soll, $aktiv) as $slug) {
                $fehler[] = "«{$feature}» lädt cross_cutting:always «{$slug}» — Dossier fehlt oder ist inaktiv; das Feature bekommt an dieser Stelle still nichts.";
            }
        }

        foreach ($features as $feature) {
            $messung = $kcs->pflichtBudgetFuer($team, (string) $feature);
            $pflicht = $messung['required_chars'];
            $budget = $messung['budget'];
            $ok = $messung['ok'];
            if ($pflicht === 0) {
                continue;
            }
            $zeilen[] = [$feature, number_format($pflicht, 0, ',', '.'), number_format($budget, 0, ',', '.'), $ok ? 'ok' : 'ZU KLEIN'];
            if (! $ok) {
                $fehler[] = "Budget von «{$feature}» ist {$budget} Zeichen, die always-gerouteten Pflicht-Inhalte brauchen {$pflicht} — der Wissensaufbau stoppt mit einem Konfigurationsfehler.";
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
