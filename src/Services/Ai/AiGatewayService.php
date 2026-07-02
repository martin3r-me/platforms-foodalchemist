<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use RuntimeException;

/**
 * M0-14: KI-Gateway-Basis — Fassade vor dem Plattform-LLM (D3-Entscheid, hybrid).
 *
 * Der Transport läuft IMMER über Cores `LLMProviderContract` — kein eigener
 * HTTP-Client, kein Key-Handling im Modul. Provider-Wahl per Config:
 *     foodalchemist.ai.provider = 'core'  → Plattform-Binding (OpenAiService & Co.)
 *                               = 'fake'  → FakeAiProvider (Sandbox/Tests, ohne Key)
 *
 * M7-01: ai_call_log-Audit — jede Antwort schreibt VOR Rückgabe genau eine
 * Zeile, AUCH der Fehlerpfad (06_KI §5 Pflicht 2, try/finally erzwungen).
 * M7-02: Tiering A–D — Tier aus der Prompt-Registry (V-01), Override via
 * options['tier']; Tier→Modell-Mapping ist Deployment-Config
 * (foodalchemist.ai.tiers — null = Plattform-Default-Modell).
 *
 * Noch offen (planmäßig): Retry/Degeneration + Fence-Stripping (M7-03),
 * Voice-Hüllen via core.semantic_layer (M7-05) — Hook: $systemBlock.
 */
class AiGatewayService
{
    /**
     * GL-07 Propose: Task-Prompt + Kontext → validiertes Vorschlags-DTO.
     * Persistiert nur den AUDIT-Eintrag (06_KI §5), nie Fachdaten (GL-07 I3).
     *
     * @param array<string, mixed> $context Fachkontext — wird als JSON an die Task gehängt
     * @param array<string, mixed> $options knowledge (GL-13-Block) · knowledge_used (Audit-Slugs)
     *                                      · tier (Override) · target_table/target_id · Provider-Optionen
     */
    /**
     * #389 Food DNA: kreative/geschmackliche Prompt-Keys, die die Marken-/Küchen-DNA als
     * STEHENDEN Kontext erhalten (Team-Basis + optional Concept-Override via Option
     * 'food_dna_concept_id'). Klassifikatoren (kategorie/eigenschaften/geschmack/zustand/…)
     * bleiben bewusst AUSSEN vor — DNA würde dort die strukturelle Klassifikation verzerren.
     */
    public const FOOD_DNA_KEYS = [
        'recipe.generator', 'recipe.beschreibung', 'recipe.zubereitung', 'recipe.ueberarbeiten', 'recipe.pairing', 'recipe.review',
        'vk.generator', 'vk.wording', 'vk.marketing', 'vk.plating', 'vk.servier_vehikel', 'vk.behaelter', 'vk.regeneration', 'vk.kohaerenz', 'vk.teller_heber', 'vk.review',
        'concept.wording',
    ];

    public function propose(string $promptKey, array $context = [], array $options = []): AiProposal
    {
        // M7-08: Kill-Switch — Team-Schalter stoppt jeden Call VOR dem Provider
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && ! app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->kiAktiv($team)) {
            throw new \Platform\FoodAlchemist\Exceptions\KiDeaktiviertException();
        }

        // Literaler Array-Zugriff — Prompt-Keys enthalten Punkte (config()-Dot-Notation würde sie als Pfad lesen)
        $prompt = config('foodalchemist.prompts', [])[$promptKey] ?? null;
        if (!is_array($prompt) || empty($prompt['task'])) {
            throw new RuntimeException("Unbekannter Prompt-Key [{$promptKey}] — Registry: config/foodalchemist.php → prompts.");
        }

        // M7-02: Tier aus der Registry, Override per Option; Modell aus dem Tier-Mapping
        $tier = is_string($options['tier'] ?? null) ? $options['tier'] : ($prompt['tier'] ?? 'B');
        $tierModell = config('foodalchemist.ai.tiers', [])[$tier] ?? null;

        $messages = [];
        // M7-05 / GL-06 §6 (Hybrid): Voice-Hülle aus core.semantic_layer als
        // ERSTE systemInstruction (kanonische Reihenfolge: 1. Voice-Hülle,
        // 2. Feld-Hülle aus der Registry, … 4. Task) — defensiv: ohne Core-
        // Resolver/Layer läuft der Call unverändert (Resolver liefert empty).
        $layersUsed = null;
        $huelle = $this->voiceHuelle();
        if ($huelle !== null) {
            $messages[] = ['role' => 'system', 'content' => $huelle['block']];
            $layersUsed = $huelle['version_chain'];
        }
        if (!empty($prompt['system'])) {
            $messages[] = ['role' => 'system', 'content' => $prompt['system']];
        }
        // GL-13: Fakten-Wissen gehört in den USER-Prompt (Hüllen = Verhalten, additiv, nie redundant)
        $wissen = isset($options['knowledge']) && is_string($options['knowledge']) && $options['knowledge'] !== ''
            ? $options['knowledge'] . "\n\n"
            : '';
        $audit = [
            'knowledge_used' => $options['knowledge_used'] ?? null,
            'target_table' => $options['target_table'] ?? null,
            'target_id' => $options['target_id'] ?? null,
        ];
        $cidFoodDna = $options['food_dna_concept_id'] ?? null;     // #389 → zentraler Canvas
        $fbFoodDna = $options['food_dna_foodbook_id'] ?? null;
        $agFoodDna = $options['food_dna_angebot_id'] ?? null;
        unset($options['knowledge'], $options['knowledge_used'], $options['tier'], $options['target_table'], $options['target_id'], $options['food_dna_concept_id'], $options['food_dna_foodbook_id'], $options['food_dna_angebot_id']);

        // #389/Canvas: stehenden Marken-/Brief-Kontext NUR in kreative Prompts mergen
        // (Klassifikatoren ausgenommen). Kaskade Team-DNA → Angebot → Foodbook → Concept (CanvasService).
        if ($team !== null && in_array($promptKey, self::FOOD_DNA_KEYS, true)) {
            $context = app(\Platform\FoodAlchemist\Services\CanvasService::class)->cascadeKontext(
                $team,
                $cidFoodDna !== null ? (int) $cidFoodDna : null,
                $fbFoodDna !== null ? (int) $fbFoodDna : null,
                $agFoodDna !== null ? (int) $agFoodDna : null,
            ) + $context;
        }

        $userContent = $wissen . $prompt['task'] . "\n\nKontext:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $messages[] = ['role' => 'user', 'content' => $userContent];

        if ($tierModell !== null && ! isset($options['model'])) {
            $options['model'] = $tierModell;
        }

        // M7-03 §3.3: Structural-Retry-Gate — valides JSON, aber fachlich
        // unbrauchbar (z. B. leeres Pflicht-Array) → Re-Roll
        $isUsable = $options['structural_retry'] ?? null;
        unset($options['structural_retry']);

        // ── 06_KI §5 Pflicht 1+2: VOR Rückgabe loggen, auch im Fehlerpfad ──
        // M7-03 §3.1–3.3: Backoff-Treppe (transiente Provider-Fehler) +
        // einmaliger Modell-Fallback + Degenerations-Re-Roll (Temp 0.3→0.5→0.7)
        $start = hrtime(true);
        $antwort = null;
        $fehler = null;
        $parsed = null;
        $tempTreppe = [(float) ($prompt['temperature'] ?? 0.1), 0.5, 0.7];   // §3.3
        foreach ($tempTreppe as $versuch => $temperature) {
            $fehler = null;
            try {
                $antwort = $this->chatMitBackoff($messages, $options + ['temperature' => $temperature]);
                $parsed = json_decode($this->stripJsonFence((string) ($antwort['content'] ?? '')), true);
                if (!is_array($parsed)) {
                    throw new RuntimeException("KI-Antwort für [{$promptKey}] ist kein valides JSON (nach Fence-Stripping, Versuch " . ($versuch + 1) . ').');
                }
                if (is_callable($isUsable) && ! $isUsable($parsed)) {
                    throw new RuntimeException("KI-Antwort für [{$promptKey}] ist strukturell unbrauchbar (Versuch " . ($versuch + 1) . ').');
                }
                break;                                               // erste valide + brauchbare gewinnt
            } catch (\Throwable $e) {
                $fehler = $e;
                $parsed = null;
            }
        }
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $audit['layers_used'] = $layersUsed;
        $callLogId = $this->schreibeCallLog($promptKey, $tier, $userContent, $antwort, $parsed, $fehler, $elapsedMs, $audit);

        if ($fehler !== null) {
            throw $fehler;
        }

        return new AiProposal(
            werte: $parsed['werte'] ?? [],
            confidence: min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.0))), // Clamp (GL-07 I5)
            begruendung: $parsed['begruendung'] ?? null,
            unknownSlugs: $parsed['unknown_slugs'] ?? [],
            model: $antwort['model'] ?? null,
            elapsedMs: $elapsedMs,
            callLogId: $callLogId,
        );
    }

    /**
     * M7-10 / 06_KI §2 Tier D: agentischer Tool-Loop — provider-agnostisch
     * über ein JSON-Protokoll (das Contract garantiert keine Tool-API):
     * Das Modell antwortet {action:'tool', name, arguments} oder
     * {action:'final', text}; Tools laufen über die Core-ToolRegistry
     * (M8-01, team-scoped via ToolContext). Schreib-Tools bleiben GL-07-
     * Proposal-Flow. Jede Runde loggt (ai_call_log via propose-Pfad-Logik
     * hier inline), maxRuns deckelt; Thinking/Temp-0.0 sind Tier-D-Config.
     *
     * @param  list<string>  $toolNames  erlaubte Tools (Registry-Namen)
     * @return array{text: ?string, runden: int, tool_laeufe: list<array>, elapsed_ms: int}
     */
    public function callWithTools(string $auftrag, array $toolNames, int $maxRuns = 6): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && ! app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->kiAktiv($team)) {
            throw new \Platform\FoodAlchemist\Exceptions\KiDeaktiviertException();
        }
        $registry = app(\Platform\Core\Tools\ToolRegistry::class);
        $katalog = collect($toolNames)
            ->map(fn ($n) => $registry->get($n))
            ->filter()
            ->map(fn ($t) => ['name' => $t->getName(), 'beschreibung' => $t->getDescription(), 'schema' => $t->getSchema()])
            ->values()->all();

        $messages = [[
            'role' => 'system',
            'content' => 'Du bist der Food-Alchemist-Sprachassistent (Catering-Souschef). Antworte AUSSCHLIESSLICH '
                . 'mit einem JSON-Objekt: {"action":"tool","name":"<tool>","arguments":{…}} um ein Tool zu rufen, '
                . 'oder {"action":"final","text":"<kurze deutsche Antwort>"} wenn du fertig bist. '
                . 'Schreibaktionen NUR über die Proposal-Tools (nie erfinden). Verfügbare Tools: '
                . json_encode($katalog, JSON_UNESCAPED_UNICODE),
        ], ['role' => 'user', 'content' => $auftrag]];

        $start = hrtime(true);
        $toolLaeufe = [];
        $finalText = null;
        $runde = 0;
        $kontext = $team !== null && Auth::user() !== null ? new \Platform\Core\Contracts\ToolContext(Auth::user(), $team) : null;
        while ($runde < $maxRuns) {
            $runde++;
            $antwort = $this->chatMitBackoff($messages, [
                'temperature' => 0.0,
                'model' => config('foodalchemist.ai.tiers', [])['D'] ?? null,
            ]);
            $parsed = json_decode($this->stripJsonFence((string) ($antwort['content'] ?? '')), true);
            if (! is_array($parsed)) {
                $messages[] = ['role' => 'user', 'content' => 'Antwort war kein valides JSON — bitte exakt dem Protokoll folgen.'];

                continue;
            }
            if (($parsed['action'] ?? null) === 'final' || $kontext === null) {
                $finalText = $parsed['text'] ?? null;
                break;
            }
            if (($parsed['action'] ?? null) === 'tool' && is_string($parsed['name'] ?? null) && in_array($parsed['name'], $toolNames, true)) {
                $tool = $registry->get($parsed['name']);
                $resultat = $tool !== null
                    ? $tool->execute((array) ($parsed['arguments'] ?? []), $kontext)
                    : \Platform\Core\Contracts\ToolResult::error('Tool unbekannt.', 'NOT_FOUND');
                $toolLaeufe[] = ['name' => $parsed['name'], 'arguments' => $parsed['arguments'] ?? [], 'success' => $resultat->success, 'data' => $resultat->data];
                $messages[] = ['role' => 'assistant', 'content' => (string) ($antwort['content'] ?? '')];
                $messages[] = ['role' => 'user', 'content' => 'TOOL-ERGEBNIS ' . $parsed['name'] . ': '
                    . json_encode(['success' => $resultat->success, 'data' => $resultat->data, 'error' => $resultat->error], JSON_UNESCAPED_UNICODE)];

                continue;
            }
            $messages[] = ['role' => 'user', 'content' => 'Unbekannte action oder Tool nicht erlaubt — Protokoll beachten.'];
        }
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        // Audit: EIN Eintrag je Loop (Tier D, Runden in der Summary)
        $this->schreibeCallLog('voice.command', 'D', $auftrag, ['model' => config('foodalchemist.ai.tiers', [])['D'] ?? 'default'],
            ['werte' => ['runden' => $runde, 'tools' => count($toolLaeufe), 'final' => $finalText !== null]], null, $elapsedMs,
            ['knowledge_used' => null, 'target_table' => null, 'target_id' => null, 'layers_used' => null]);

        return ['text' => $finalText, 'runden' => $runde, 'tool_laeufe' => $toolLaeufe, 'elapsed_ms' => $elapsedMs];
    }

    /** 06_KI §5 Pflicht 3: generischer Accept-Stempel (Reject analog). */
    public function stempleAccepted(?int $callLogId): void
    {
        if ($callLogId !== null) {
            DB::table('foodalchemist_ai_call_log')->where('id', $callLogId)->update(['accepted_at' => now()]);
        }
    }

    public function stempleRejected(?int $callLogId): void
    {
        if ($callLogId !== null) {
            DB::table('foodalchemist_ai_call_log')->where('id', $callLogId)->update(['rejected_at' => now()]);
        }
    }

    private function schreibeCallLog(string $feature, string $tier, string $userContent, ?array $antwort, ?array $parsed, ?\Throwable $fehler, int $elapsedMs, array $audit): ?int
    {
        try {
            $summary = $fehler === null
                ? mb_strimwidth(json_encode($parsed['werte'] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 200, '…')
                : null;
            DB::table('foodalchemist_ai_call_log')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => Auth::user()?->currentTeamRelation?->id,
                'user_id' => Auth::id(),
                'feature' => $feature,
                'tier' => $tier,
                'model' => $antwort['model'] ?? null,
                'layers_used' => isset($audit['layers_used']) && $audit['layers_used'] !== null && $audit['layers_used'] !== []
                    ? json_encode($audit['layers_used']) : null,      // GL-06 Inv. 7
                'knowledge_used' => isset($audit['knowledge_used']) && $audit['knowledge_used'] !== null && $audit['knowledge_used'] !== []
                    ? json_encode($audit['knowledge_used']) : null,
                'prompt_hash' => hash('sha256', $userContent),
                'response_summary' => $summary,
                'tokens_in' => $antwort['usage']['input_tokens'] ?? null,
                'tokens_out' => $antwort['usage']['output_tokens'] ?? null,
                'target_table' => $audit['target_table'],
                'target_id' => $audit['target_id'],
                'error' => $fehler?->getMessage(),
                'elapsed_ms' => $elapsedMs,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;                                             // Audit darf den Fach-Call nie reißen (graceful)
        }
    }

    /**
     * M7-03 §3.1/3.2: Backoff-Treppe (Default 1s/3s/10s, Tests: ai.backoff=[])
     * für transiente Provider-Fehler; danach EINMALIGER Wechsel aufs
     * Fallback-Modell (ai.fallback_model) mit frischer Treppe — nur wenn
     * nicht schon darauf gestartet. model trägt immer das tatsächliche Modell.
     */
    private function chatMitBackoff(array $messages, array $options): array
    {
        $treppe = config('foodalchemist.ai.backoff', [1, 3, 10]);
        $fallback = config('foodalchemist.ai.fallback_model');

        $letzter = null;
        foreach ([null, $fallback] as $stufe => $modellWechsel) {
            if ($stufe === 1 && ($modellWechsel === null || ($options['model'] ?? null) === $modellWechsel)) {
                break;                                               // kein Fallback konfiguriert / schon drauf
            }
            $opts = $modellWechsel !== null && $stufe === 1 ? ['model' => $modellWechsel] + $options : $options;
            foreach ([0, ...$treppe] as $warte) {
                if ($warte > 0) {
                    sleep((int) $warte);
                }
                try {
                    return $this->provider()->chat($messages, $opts);
                } catch (\Throwable $e) {
                    $letzter = $e;
                }
            }
        }

        throw $letzter ?? new RuntimeException('Provider nicht erreichbar.');
    }

    /**
     * M7-03 §3.4.2 (Ist: gemini.rs:748-786): Fences/Prosa um das JSON entfernen —
     * ab erstem {/[ mit Tiefen-Zähler scannen (String-Literale + Escapes
     * respektiert), am ERSTEN vollständigen Wert abschneiden. Unbalanciert
     * (echte Truncation) → Rest ab Start zurückgeben, Parse-Fehler bleibt ehrlich.
     */
    public function stripJsonFence(string $raw): string
    {
        $start = null;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            if ($raw[$i] === '{' || $raw[$i] === '[') {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return $raw;
        }

        $tiefe = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start; $i < $len; $i++) {
            $c = $raw[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $tiefe++;
            } elseif ($c === '}' || $c === ']') {
                $tiefe--;
                if ($tiefe === 0) {
                    return substr($raw, $start, $i - $start + 1);    // erster vollständiger Wert
                }
            }
        }

        return substr($raw, $start);                                 // unbalanciert → ehrlich
    }

    /**
     * M7-05: Voice-Hülle (Ton/Perspektive/Negativ-Raum) team-aufgelöst über
     * `core.semantic_layer` — Verhalten als systemInstruction; Fakten-Wissen
     * (GL-13) bleibt im User-Prompt. Additiv, nie redundant (GL-13 §1).
     *
     * @return ?array{block: string, version_chain: array}
     */
    private function voiceHuelle(): ?array
    {
        if (! config('foodalchemist.ai.huellen', true)
            || ! class_exists(\Platform\Core\SemanticLayer\Services\SemanticLayerResolver::class)) {
            return null;
        }
        try {
            $resolved = app(\Platform\Core\SemanticLayer\Services\SemanticLayerResolver::class)
                ->resolveFor(Auth::user()?->currentTeamRelation, 'foodalchemist');
        } catch (\Throwable) {
            return null;                                             // Hülle darf den Fach-Call nie reißen
        }
        if ($resolved->rendered_block === null || $resolved->isEmpty()) {
            return null;
        }

        return ['block' => $resolved->rendered_block, 'version_chain' => $resolved->version_chain];
    }

    public function provider(): LLMProviderContract
    {
        return match (config('foodalchemist.ai.provider', 'core')) {
            'fake' => app(FakeAiProvider::class),
            // Plattform-Binding — lazy aufgelöst, damit Sandbox/Tests ohne Core-LLM-Setup laufen
            default => app(LLMProviderContract::class),
        };
    }
}
