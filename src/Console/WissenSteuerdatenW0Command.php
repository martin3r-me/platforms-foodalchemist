<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

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
    private const ROUTINGS = [
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
        'regelwerk-basisrezepte-5-default-gps-fur-generische-zutaten',
        'regelwerk-basisrezepte-6-mengen-einheiten-yield',
        'regelwerk-basisrezepte-7-allergen-zusatzstoff-vererbung',
    ];

    /**
     * Nur für Basisrezepte: das Erstellungs-Dossier. An `vk.generator` ist es bewusst
     * NICHT gebunden (und soll es nicht sein) — ein Verkaufsgericht wird nicht nach der
     * Basisrezept-Anlage gebaut, dafür hat VK sein eigenes Regelwerk.
     *
     * @var list<string>
     */
    private const ALWAYS_SLUGS_BASIS = ['workflow.basisrezept_erstellungs_dossier'];

    /** Nur für Gerichte: das VK-Regelwerk (Naming-Skelett + Modell-A-Klassifikation). */
    private const ALWAYS_SLUGS_VK = ['regelwerk.regelwerk_verkaufsgerichte'];

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
        foreach ($this->bindingZiele() as $targetKey => $slugs) {
            foreach ($slugs as $slug) {
                $row = DB::table('foodalchemist_knowledge_bindings as b')
                    ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                    ->whereNull('b.deleted_at')->where('b.binding_type', 'layer')
                    ->where('b.target_key', $targetKey)->where('d.slug', $slug)
                    ->first(['b.id', 'b.mode', 'd.char_count', 'd.active']);
                $bPlan[] = $row === null
                    ? [$targetKey, $slug, '—', 'FEHLT (kein Binding)', '']
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
        return [
            'recipe.generator' => array_merge(self::ALWAYS_SLUGS_BAU, self::ALWAYS_SLUGS_BASIS),
            'vk.generator' => array_merge(self::ALWAYS_SLUGS_BAU, self::ALWAYS_SLUGS_VK),
        ];
    }

    /**
     * Post-Condition-Assert: nicht „sieht gut aus", sondern „die Bau-§§ sind im Prompt".
     * Prüft den EINZIGEN Pfad, der nach W0-4 noch Regelwerk liefert (Layer-Bindings),
     * plus dass der Routing-Weg wirklich zu ist.
     */
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
