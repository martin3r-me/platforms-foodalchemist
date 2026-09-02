<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Services\LLMProviderRegistry;
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
     * W0-3 — Deckel des Layer-Bound-Kanals.
     *
     * Vorher 3 Docs à 1400 Zeichen. An `recipe.generator` hängen aber 9 bewusst gebundene
     * Dossiers (Bau-§§ 2–7 + Erstellungs-Dossier + substitutionen + mengen_defaults):
     * 6 davon erreichten den Prompt nie, §5 Default-GPs kam als 29-%-Fragment an. Der
     * Kanal war damit genau für den Fall unbrauchbar, für den er gebaut wurde — prozedurale
     * Regeln, die per Discovery nicht surfacen, weil sie kein Gericht nennen.
     *
     * ⚠ Die Deckel gelten NICHT global, sondern je Prompt-Key über
     * config('foodalchemist.ai.bound_knowledge_budget'). Grund: selectBoundKnowledge()
     * matcht Bindings auf den Prompt-Key ODER dessen BEREICHS-Präfix — an `target_key='recipe'`
     * hängen live 3 Dossiers mit 24.520 Zeichen, die sonst jeden `recipe.*`-Prompt
     * (steps, pairing, review, sensorik, …) von 4.200 auf 20.000 Zeichen aufblasen würden.
     * Ein global gehobener Deckel wäre also keine Blutstillung, sondern eine Kosten-
     * ausweitung auf ~15 Prompts, die die Bau-§§ gar nicht brauchen.
     *
     * Die Konstanten hier sind daher der konservative DEFAULT (= Verhalten vor Welle 0);
     * die großzügigen Budgets stehen explizit bei `recipe.generator` / `vk.generator`,
     * wo die Bau-§-Dossiers hingehören.
     */
    private const BOUND_KNOWLEDGE_MAX_DOCS = 3;

    private const BOUND_KNOWLEDGE_CHARS_PER_DOC = 1400;

    private const BOUND_KNOWLEDGE_MAX_TOTAL_CHARS = 4200;

    /**
     * Fenster für das Relevanz-Token-Matching der `discovery`-Bindings. 1200 Zeichen trafen
     * bei mehrseitigen Dossiers nur die Einleitung; die eigentliche Regel liegt dahinter.
     */
    private const BOUND_KNOWLEDGE_MATCH_WINDOW = 4000;

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
     * 'food_dna_concept_id'). Klassifikatoren (kategorie/eigenschaften/geschmack/condition/…)
     * bleiben bewusst AUSSEN vor — DNA würde dort die strukturelle Klassifikation verzerren.
     */
    public const FOOD_DNA_KEYS = [
        'recipe.generator', 'recipe.description', 'recipe.preparation', 'recipe.steps', 'recipe.ueberarbeiten', 'recipe.pairing', 'recipe.review',
        'vk.generator', 'vk.wording', 'vk.marketing', 'vk.plating', 'vk.servier_vehikel', 'vk.behaelter', 'vk.regeneration', 'vk.kohaerenz', 'vk.teller_heber', 'vk.review',
        'vk.ueberarbeiten',         // Spec 03 L1a: Revise formt Texte + Komponenten des Gerichts — ohne DNA-Kette revidiert sie gegen die Marke
        'concept.wording',
        'concept.plan',             // Et.2b Kreativ-Kopf: die Concept-Leitidee IST Marke — ohne DNA-Kette formt sie am Team-/Kunden-Rahmen vorbei
        'foodbook.kapitel_ideen',   // Spec 19 E6.4: KI-Divergenz erbt die Food-DNA-Kette (Kontext-Vertrag)
        'foodbook.kundentext',      // Spec 03 L2: Kundentext IST die Marken-Stimme — ohne DNA-Kette wäre er beliebig
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
        // Ausgabe-Contract: AiProposal erwartet {werte:{…}, confidence, reasoning}. Reale Modelle
        // (verifiziert gpt-5.5) liefern die Fachfelder sonst FLACH auf oberster Ebene → werte leer,
        // confidence 0. Diese Instruktion erzwingt den Umschlag provider-übergreifend (Fake-Provider/
        // Tests liefern ihn ohnehin). Additiv als letzte system-Instruktion vor der Task.
        $messages[] = ['role' => 'system', 'content' =>
            'Antworte AUSSCHLIESSLICH mit EINEM JSON-Objekt in exakt dieser Form: '
            . '{"werte": { …genau die im Auftrag unter "werte = {…}" geforderten Felder… }, '
            . '"confidence": <Zahl 0..1>, "reasoning": "<kurze Begründung>"}. '
            . 'Alle Fachfelder MÜSSEN in "werte" verschachtelt sein, nie auf oberster Ebene. '
            . 'Kein Markdown, keine Prosa außerhalb des JSON.'];
        // GL-13: Fakten-Wissen gehört in den USER-Prompt (Hüllen = Verhalten, additiv, nie redundant)
        $wissen = isset($options['knowledge']) && is_string($options['knowledge']) && $options['knowledge'] !== ''
            ? $options['knowledge'] . "\n\n"
            : '';
        // W0-0 Messsonde: Zeichen je Prompt-Topf. `tokens_in` kommt post-hoc vom Provider und
        // sagt nicht, WOHER die Bytes kamen — ohne diese Zerlegung ist jede Budget-Änderung
        // blind (vgl. Welle 0: ~44 % des Generator-Prompts waren ein rechnerischer Restposten).
        $promptParts = [
            'kanon' => 0,                                            // Welle 2 (CanonBlockBuilder)
            // Exakt der Retrieval-Block, OHNE den angehängten Trenner: der wird beim
            // Zusammenbau ohnehin rtrim't, und eine Sonde, die zwei Zeichen zu viel meldet,
            // macht jede Nachrechnung von prompt_chars unmöglich.
            'retrieval' => mb_strlen((string) ($options['knowledge'] ?? '')),
            'bound' => 0,
            'task' => mb_strlen((string) $prompt['task']),
            'kontext' => 0,
            'huelle' => 0,
            'dropped' => (int) ($options['knowledge_dropped_chars'] ?? 0),
        ];

        // #469: an diesen Layer gebundenes Wissen additiv laden — Prompt-Key (fein) ODER Bereich (Präfix, grob).
        // Macht „einbinden" für JEDEN Prompt wirksam (zentraler Punkt, alle Prompts laufen durch propose()).
        $boundSlugs = [];
        $boundBlock = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('foodalchemist_knowledge_bindings')) {
            $bereich = str_contains($promptKey, '.') ? explode('.', $promptKey, 2)[0] : $promptKey;
            $bound = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_bindings as b')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                ->whereNull('b.deleted_at')->where('b.active', 1)
                ->where('b.binding_type', 'layer')->whereIn('b.target_key', array_unique([$promptKey, $bereich]))
                ->where('d.active', 1)->whereNull('d.deleted_at')
                ->orderByDesc('b.weight')
                ->get(['d.slug', 'd.title', 'd.category', 'd.version', 'd.content_md', 'b.mode', 'b.weight']);
            [$bBlocks, $boundSlugs, $boundVerworfen] = $this->selectBoundKnowledge(
                $bound,
                $context,
                is_array($options['knowledge_used'] ?? null) ? $options['knowledge_used'] : [],
                $this->boundBudget($promptKey),
            );
            $promptParts['dropped'] += $boundVerworfen;
            if ($bBlocks !== []) {
                // W3-1: NICHT mehr an $wissen anhängen. Der Bound-Block besteht ausschliesslich
                // aus `always`-Dossiers und ist damit über alle Calls eines Prompt-Keys
                // BYTE-IDENTISCH — er ist der Kern des Cache-Prefix und gehört deshalb VOR
                // alles Variable, als eigene system-Message. Im User-Content stand er hinter
                // dem variablen Retrieval-Block und war damit für den Prefix-Cache wertlos.
                $boundBlock = "# VERBINDLICHES REGELWERK (gilt für jede Antwort dieses Auftrags)\n\n"
                    . implode("\n\n---\n\n", $bBlocks);
                $promptParts['bound'] = mb_strlen($boundBlock);
            }
        }

        $knowledgeUsed = $options['knowledge_used'] ?? null;
        if ($boundSlugs !== []) {
            $knowledgeUsed = array_values(array_unique(array_merge(is_array($knowledgeUsed) ? $knowledgeUsed : [], $boundSlugs)));
        }
        $audit = [
            'knowledge_used' => $knowledgeUsed,
            'target_table' => $options['target_table'] ?? null,
            'target_id' => $options['target_id'] ?? null,
        ];
        $cidFoodDna = $options['food_dna_concept_id'] ?? null;     // #389 → zentraler Canvas
        $fbFoodDna = $options['food_dna_foodbook_id'] ?? null;
        $agFoodDna = $options['food_dna_angebot_id'] ?? null;
        $kdFoodDna = $options['food_dna_crm_company_id'] ?? null;  // Ebene 2: Endkunde (Kunde-DNA)
        unset($options['knowledge'], $options['knowledge_used'], $options['knowledge_dropped_chars'], $options['tier'], $options['target_table'], $options['target_id'], $options['food_dna_concept_id'], $options['food_dna_foodbook_id'], $options['food_dna_angebot_id'], $options['food_dna_crm_company_id']);

        // #389/Canvas: stehenden Marken-/Brief-Kontext NUR in kreative Prompts mergen
        // (Klassifikatoren ausgenommen). Kaskade Team-DNA → Kunde-DNA → Angebot → Foodbook → Concept (CanvasService).
        if ($team !== null && in_array($promptKey, self::FOOD_DNA_KEYS, true)) {
            $context = app(\Platform\FoodAlchemist\Services\CanvasService::class)->cascadeKontext(
                $team,
                $cidFoodDna !== null ? (int) $cidFoodDna : null,
                $fbFoodDna !== null ? (int) $fbFoodDna : null,
                $agFoodDna !== null ? (int) $agFoodDna : null,
                $kdFoodDna !== null ? (int) $kdFoodDna : null,
            ) + $context;
        }

        // W0-2: KEIN JSON_PRETTY_PRINT. Der Kontext ist Maschinen-Input, kein Lesetext —
        // Einrückung und Zeilenumbrüche blähen ihn um Faktor ~2,05 (gemessen an der realen
        // Generator-Struktur: 2,04 / 2,09) an reinem Whitespace, den das Modell mitbezahlt.
        $kontextJson = json_encode($context, JSON_UNESCAPED_UNICODE);
        $promptParts['kontext'] = mb_strlen((string) $kontextJson);

        /*
         * W3-1 — MESSAGE-LAYOUT FÜR DEN PREFIX-CACHE. Verbindlich, Reihenfolge ist die Aussage:
         *
         *   1.–3. system  Voice-Hülle · Feld-Hülle · JSON-Umschlag      (statisch)
         *   4.    system  VERBINDLICHES REGELWERK (always-Bindings)     (statisch, byte-identisch)
         *   5.    user    task                                          (statisch je Prompt-Key)
         *   6.    user    Retrieval-Wissen                              (variabel)
         *   7.    user    Kontext-JSON                                  (variabel)
         *
         * Alles bis einschliesslich 5 ist über alle Calls eines Prompt-Keys identisch —
         * ~16.000 Zeichen ≈ 5.400 Token, klar über der 1024-Token-Mindestlänge, ab der der
         * implizite Prefix-Cache greift (10 % des Input-Preises).
         *
         * Vorher stand das VARIABLE Retrieval-Wissen als Erstes im User-Content: damit begann
         * jeder Prompt anders und der Cache konnte nie greifen — gemessene Quote 0,35 %.
         * Zusammen mit W0-1 (der Core stellte zusätzlich `'Zeit: ' . now()` sekundengenau
         * voran) waren das die zwei Gründe, warum Caching strukturell unmöglich war.
         *
         * `prompt_cache_key` ist NICHT setzbar (applySupportedSamplingParams ist eine
         * geschlossene Whitelist) — der Nutzen hängt vollständig an dieser Reihenfolge.
         */
        if ($boundBlock !== null) {
            $messages[] = ['role' => 'system', 'content' => $boundBlock];
        }

        $userContent = $prompt['task']
            . ($wissen !== '' ? "\n\n" . rtrim($wissen) : '')
            . "\n\nKontext:\n" . $kontextJson;
        $messages[] = ['role' => 'user', 'content' => $userContent];

        // W0-0: Hülle = die system-Messages OHNE den Regelwerk-Block. Der wird seit W3-1
        // ebenfalls als system-Message gesendet, ist aber als `bound` separat ausgewiesen —
        // sonst zählte die Sonde ihn doppelt und `prompt_chars` ginge nicht mehr auf.
        $promptParts['huelle'] = array_sum(array_map(
            fn (array $m): int => $m['role'] === 'system' ? mb_strlen((string) $m['content']) : 0,
            $messages,
        )) - $promptParts['bound'];
        $promptChars = array_sum(array_map(fn (array $m): int => mb_strlen((string) $m['content']), $messages));

        // W0-1: Der Core stellt sonst eine System-Message mit `'Zeit: ' . now()` und einer
        // Tool-Übersicht VOR den Prompt (OpenAiService::buildMessagesWithContext, gated über
        // `with_context`). Ein sekundengenau wechselnder Prefix macht Prompt-Caching
        // strukturell unmöglich — bei `cached_in` = 10 % des Normalpreises ist das der
        // teuerste Nebeneffekt im System (gemessene Cache-Quote: 0,35 %). FA-Prompts sind
        // reine JSON-Generierung und nutzen keine Tools; der agentische Tier-D-Loop baut
        // seinen Katalog in callWithTools() selbst und ist hiervon nicht betroffen.
        // `+=` statt Überschreiben: ein Aufrufer, der es explizit setzt, behält die Hoheit.
        $options += ['with_context' => false, 'tools' => false];

        if ($tierModell !== null && ! isset($options['model'])) {
            $options['model'] = $tierModell;
        }

        // Output-Budget: Reasoning-Modelle (gpt-5.x u.a.) verbrauchen einen Teil des
        // Output-Kontingents für interne Reasoning-Tokens. Der Core-Default (OpenAiService:
        // max_output_tokens ?? 1000) schneidet große Struktur-Antworten (Rezept-/Konzept-JSON)
        // mitten im JSON ab → Parse-Fehler → 3× Re-Roll → Web-Timeout/502. Darum hier pro
        // Prompt (Registry: max_tokens) bzw. großzügig defaulten, statt Core den Wert raten zu lassen.
        if (! isset($options['max_tokens'])) {
            $options['max_tokens'] = (int) ($prompt['max_tokens'] ?? config('foodalchemist.ai.max_tokens_default', 4096));
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
        $usageGesamt = ['input_tokens' => 0, 'output_tokens' => 0, 'input_tokens_details' => ['cached_tokens' => 0]];
        $tatsaechlichesModell = null;
        $tempTreppe = [(float) ($prompt['temperature'] ?? 0.1), 0.5, 0.7];   // §3.3
        foreach ($tempTreppe as $versuch => $temperature) {
            $fehler = null;
            try {
                $antwort = $this->chatMitBackoff($messages, $options + ['temperature' => $temperature]);
                $this->addUsage($usageGesamt, (array) ($antwort['usage'] ?? []));
                $tatsaechlichesModell = $antwort['model'] ?? $tatsaechlichesModell;
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
        if ($antwort !== null) {
            // Auch verworfene, aber vom Provider erfolgreich beantwortete Re-Rolls werden berechnet.
            $antwort['usage'] = $usageGesamt;
            $antwort['model'] = $tatsaechlichesModell ?? ($antwort['model'] ?? null);
        }

        $audit['layers_used'] = $layersUsed;
        $audit['prompt_chars'] = $promptChars;
        $audit['prompt_parts'] = $promptParts;
        $audit['prompt_volltext'] = implode("\n", array_map(fn (array $m): string => (string) $m['content'], $messages));
        $callLogId = $this->schreibeCallLog($promptKey, $tier, $userContent, $antwort, $parsed, $fehler, $elapsedMs, $audit);

        if ($fehler !== null) {
            throw $fehler;
        }

        // Safety-Net: ignoriert ein Modell den Umschlag doch und liefert die Fachfelder flach,
        // die Meta-Keys rausziehen und den Rest als werte nehmen — statt eine leere Proposal.
        $werte = $parsed['werte'] ?? null;
        if (! is_array($werte)) {
            $werte = array_diff_key($parsed, array_flip(['confidence', 'reasoning', 'unknown_slugs']));
        }

        return new AiProposal(
            werte: $werte,
            confidence: min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.0))), // Clamp (GL-07 I5)
            reasoning: $parsed['reasoning'] ?? null,
            unknownSlugs: $parsed['unknown_slugs'] ?? [],
            model: $antwort['model'] ?? null,
            elapsedMs: $elapsedMs,
            callLogId: $callLogId,
        );
    }

    /**
     * Eine Layer-Bindung ist kein pauschaler Volltext-Load: `always` ist zwingend,
     * `discovery`/`grounding` müssen zur konkreten Anfrage passen, `reference` bleibt
     * rein manuell. Früher wurden alle Modi ungefiltert wie `always` geladen.
     *
     * @return array{0:list<string>,1:list<string>}
     */
    /**
     * Bound-Budget für einen Prompt-Key: {docs, chars_per_doc, total}.
     * Override in config('foodalchemist.ai.bound_knowledge_budget'), sonst die Defaults.
     *
     * @return array{docs: int, chars_per_doc: int, total: int}
     */
    private function boundBudget(string $promptKey): array
    {
        $default = [
            'docs' => self::BOUND_KNOWLEDGE_MAX_DOCS,
            'chars_per_doc' => self::BOUND_KNOWLEDGE_CHARS_PER_DOC,
            'total' => self::BOUND_KNOWLEDGE_MAX_TOTAL_CHARS,
        ];
        $konfig = config('foodalchemist.ai.bound_knowledge_budget', []);
        if (! is_array($konfig) || ! isset($konfig[$promptKey]) || ! is_array($konfig[$promptKey])) {
            return $default;
        }

        return [
            'docs' => (int) ($konfig[$promptKey]['docs'] ?? $default['docs']),
            'chars_per_doc' => (int) ($konfig[$promptKey]['chars_per_doc'] ?? $default['chars_per_doc']),
            'total' => (int) ($konfig[$promptKey]['total'] ?? $default['total']),
        ];
    }

    private function selectBoundKnowledge($rows, array $context, array $alreadyUsed, array $budget): array
    {
        $bereits = [];
        foreach ($alreadyUsed as $used) {
            $slug = preg_replace('/@v\d+$/', '', (string) $used);
            if ($slug !== '') {
                $bereits[$slug] = true;
            }
        }

        $query = json_encode($context, JSON_UNESCAPED_UNICODE) ?: '';
        $queryTokens = $this->knowledgeTokens($query);
        $kandidaten = [];
        foreach ($rows as $row) {
            $slug = (string) $row->slug;
            if (isset($bereits[$slug])) {
                continue;
            }
            $mode = (string) ($row->mode ?: 'discovery');
            if ($mode === 'reference' || $mode === 'none') {
                continue;
            }
            $always = $mode === 'always';
            $docTokens = $this->knowledgeTokens(implode(' ', [
                $slug, (string) $row->title, (string) $row->category,
                mb_substr((string) $row->content_md, 0, self::BOUND_KNOWLEDGE_MATCH_WINDOW),
            ]));
            $hits = count(array_intersect($queryTokens, $docTokens));
            if (! $always && $hits === 0) {
                continue;
            }
            $kandidaten[] = [
                'row' => $row,
                'score' => ($always ? 1000 : 0) + $hits * 10 + (int) $row->weight,
            ];
        }
        usort($kandidaten, fn ($a, $b) => $b['score'] <=> $a['score']);

        $blocks = [];
        $slugs = [];
        $verbraucht = 0;
        $verworfen = 0;
        foreach (array_slice($kandidaten, 0, $budget['docs']) as $kandidat) {
            $doc = $kandidat['row'];
            $content = (string) $doc->content_md;
            $laenge = mb_strlen($content);

            // Kandidaten sind nach Score sortiert, `always` bekommt +1000 — Pflicht-Dossiers
            // ziehen ihr Budget also VOR den score-gegateten. Unter 500 Zeichen Restbudget
            // ist ein weiterer Anschnitt nur noch Rauschen: abbrechen und den Rest als
            // `dropped` ausweisen, statt ihn unsichtbar zu verlieren.
            $rest = $budget['total'] - $verbraucht;
            if ($rest < 500) {
                $verworfen += $laenge;
                continue;
            }
            $deckel = min($budget['chars_per_doc'], $rest);

            if ($laenge > $deckel) {
                $blocks[] = "## GEBUNDEN: {$doc->slug}\n\n" . mb_substr($content, 0, $deckel) . "\n\n[…gekürzt für KI-Kontext…]";
                $verbraucht += $deckel;
                $verworfen += $laenge - $deckel;
            } else {
                $blocks[] = "## GEBUNDEN: {$doc->slug}\n\n" . $content;
                $verbraucht += $laenge;
            }
            $slugs[] = "{$doc->slug}@v{$doc->version}";
        }

        return [$blocks, $slugs, $verworfen];
    }

    /** @return list<string> */
    private function knowledgeTokens(string $text): array
    {
        $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($text));
        $text = (string) preg_replace('/[^[:alnum:]]+/u', ' ', $text);
        $stop = array_flip([
            'der', 'die', 'das', 'den', 'dem', 'des', 'und', 'oder', 'mit', 'ohne', 'fuer',
            'von', 'aus', 'ein', 'eine', 'einer', 'eines', 'einen', 'ist', 'sind', 'wird',
            'werden', 'rezept', 'gericht', 'basisrezept', 'komponente', 'zutaten', 'werte',
        ]);
        $tokens = [];
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) >= 4 && ! isset($stop[$token])) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
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
            ->map(fn ($t) => ['name' => $t->getName(), 'description' => $t->getDescription(), 'schema' => $t->getSchema()])
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
        $usageGesamt = ['input_tokens' => 0, 'output_tokens' => 0, 'input_tokens_details' => ['cached_tokens' => 0]];
        $tatsaechlichesModell = null;
        $kontext = $team !== null && Auth::user() !== null ? new \Platform\Core\Contracts\ToolContext(Auth::user(), $team) : null;
        try {
        while ($runde < $maxRuns) {
            $runde++;
            $antwort = $this->chatMitBackoff($messages, [
                'temperature' => 0.0,
                'model' => config('foodalchemist.ai.tiers', [])['D'] ?? null,
            ]);
            $this->addUsage($usageGesamt, (array) ($antwort['usage'] ?? []));
            $tatsaechlichesModell = $antwort['model'] ?? $tatsaechlichesModell;
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
        } catch (\Throwable $e) {
            // Audit-Vollständigkeit: auch der Fehlerpfad des Tool-Loops schreibt EINE ai_call_log-Zeile (wie propose()).
            $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->schreibeCallLog('voice.command', 'D', $auftrag, ['model' => $tatsaechlichesModell, 'usage' => $usageGesamt], null, $e, $elapsedMs,
                ['knowledge_used' => null, 'target_table' => null, 'target_id' => null, 'layers_used' => null]);

            throw $e;
        }
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        // Audit: EIN Eintrag je Loop (Tier D, Runden in der Summary)
        $this->schreibeCallLog('voice.command', 'D', $auftrag, ['model' => $tatsaechlichesModell, 'usage' => $usageGesamt],
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
            $values = [
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
                // W3-1: Der Hash deckt ab jetzt den GESAMTEN Prompt ab (alle Messages), nicht
                // nur den User-Content. Grund: das verbindliche Regelwerk ist in eine
                // system-Message gewandert — ein Hash über $userContent allein würde zwei
                // Calls mit unterschiedlichem Regelwerk als identisch ausweisen. Bewusste,
                // dokumentierte Änderung der Audit-Identität (06_KI §5): Hashes vor und nach
                // dem 2026-09-02 sind nicht vergleichbar.
                'prompt_hash' => hash('sha256', $audit['prompt_volltext'] ?? $userContent),
                'response_summary' => $summary,
                'tokens_in' => $antwort['usage']['input_tokens'] ?? null,
                'tokens_out' => $antwort['usage']['output_tokens'] ?? null,
                'target_table' => $audit['target_table'],
                'target_id' => $audit['target_id'],
                'error' => $fehler?->getMessage(),
                'elapsed_ms' => $elapsedMs,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'tokens_cached')) {
                $values['tokens_cached'] = $antwort['usage']['input_tokens_details']['cached_tokens'] ?? null;
            }
            // W0-0 Messsonde — wie tokens_cached hinter hasColumn, damit ein noch nicht
            // migrierter Stand den KI-Pfad nicht reißt.
            if (isset($audit['prompt_chars']) && \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'prompt_chars')) {
                $values['prompt_chars'] = (int) $audit['prompt_chars'];
                $values['prompt_parts'] = is_array($audit['prompt_parts'] ?? null)
                    ? json_encode($audit['prompt_parts'], JSON_UNESCAPED_UNICODE) : null;
            }
            DB::table('foodalchemist_ai_call_log')->insert($values);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;                                             // Audit darf den Fach-Call nie reißen (graceful)
        }
    }

    /** Addiert die abrechnungsrelevanten OpenAI-Usage-Felder über Re-Rolls/Tool-Runden. */
    private function addUsage(array &$summe, array $usage): void
    {
        $summe['input_tokens'] += (int) ($usage['input_tokens'] ?? 0);
        $summe['output_tokens'] += (int) ($usage['output_tokens'] ?? 0);
        $summe['input_tokens_details']['cached_tokens'] += (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
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

        // #499: Provider EINMAL vor der Treppe auflösen — ohne gebundenen Provider
        // (demo) bubbelt die typisierte KiNichtVerfuegbarException sofort durch,
        // statt in die Backoff-Sleeps (~28 s) zu laufen und als un-catchbare
        // BindingResolutionException wieder rauszufallen.
        $provider = $this->provider();

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
                    return $provider->chat($messages, $opts);
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
        if (config('foodalchemist.ai.provider', 'core') === 'fake') {
            return app(FakeAiProvider::class);
        }

        // #499: Plattform-Binding graceful. Zwei Auflösungswege, in dieser Reihenfolge:
        //
        // (1) Explizites `LLMProviderContract`-Binding hat Vorrang — eine Host-App darf
        //     bewusst einen konkreten Provider setzen.
        // (2) Fallback auf Cores `LLMProviderRegistry` (Core-Commit 924e4088): der
        //     Registry-Refactor bindet den bloßen Contract NICHT mehr, registriert
        //     Provider aber in der Registry. Ohne diesen Pfad wäre FA-KI auf demo tot,
        //     OBWOHL ein Provider verfügbar ist (Registry ist immer als Singleton gebunden;
        //     getDefaultProvider() = erster key-konfigurierter Provider).
        //     Root-Cause zu #499: das fehlende Contract-Binding, nicht nur ein fehlender Key.
        //
        // Reine FA-seitige Entkopplung, KEIN Core-Eingriff. Bleibt kein Provider übrig,
        // typisiert werfen (KiNichtVerfuegbarException) statt roher BindingResolutionException,
        // damit die catch(\RuntimeException)-Guards der Entry-Points greifen (kein 500).
        if (app()->bound(LLMProviderContract::class)) {
            try {
                return app(LLMProviderContract::class);
            } catch (\Throwable $e) {
                throw new \Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException($e);
            }
        }

        if (app()->bound(LLMProviderRegistry::class)) {
            try {
                $registryProvider = app(LLMProviderRegistry::class)->getDefaultProvider();
            } catch (\Throwable $e) {
                throw new \Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException($e);
            }
            if ($registryProvider !== null) {
                return $registryProvider;
            }
        }

        throw new \Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException();
    }
}
