<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * #505 Generator-Grounding (Slice 1): hybrider, retrieval-getriebener Grounding-
 * Kontext für die KI-Rezept-/Gericht-Generierung. NICHT der komplette Bestand,
 * sondern auf der Aroma-/Beschreibungs-Achse gezogene Kandidaten:
 *
 *  - gp_kandidaten   : reale GPs zu den Beschreibungs-Tokens (IngredientMatchService) →
 *                      die KI soll Zutaten auf EXISTIERENDE GPs benennen statt zu erfinden
 *                      (weniger Post-Match-Drift, weniger auto-neue GPs).
 *  - rezept_kandidaten: bestehende (Basis-)Rezepte als Komponenten (v. a. VK).
 *  - pairing         : Anker-Graph (PairingService) ROLLENABHÄNGIG —
 *                      Basisrezept = Aroma ausschöpfen (Partner je Hauptzutat),
 *                      Gericht (VK) = Komposition (dieselben Partner, Kompositions-Framing).
 *
 * Food DNA wird NICHT hier gezogen — die injiziert AiGatewayService::propose bereits
 * (FOOD_DNA_KEYS für recipe.generator/vk.generator). Brand Voice bleibt bewusst draußen
 * (Produktionsebene). Read-only; geteilt für Recipe- + Concept-Generator + künftiges
 * recipes.GENERATE.
 */
class GenerationContextService
{
    /** Max. Tokens aus der Beschreibung, die wir erden (Prompt-Budget). */
    private const MAX_TOKENS = 6;
    /** GP-Kandidaten je Token bzw. gesamt. */
    private const CAND_PER_TOKEN = 3;
    private const GP_CAND_MAX = 24;
    private const REZEPT_CAND_MAX = 12;
    /** Pairing-Partner je Hauptzutat. */
    private const PAIRING_PER_TOKEN = 8;

    public function __construct(
        private IngredientMatchService $matcher,
        private PairingService $pairing,
        private TokenEngine $tokens,
    ) {
    }

    /**
     * Grounding-Kontext-Keys, die additiv in den Generator-$kontext gemerged werden.
     *
     * @return array{gp_kandidaten?: list<array>, rezept_kandidaten?: list<array>, pairing?: array}
     */
    public function forGeneration(Team $team, string $description, bool $vkModus = false): array
    {
        $tokens = $this->leitTokens($description);
        if ($tokens === []) {
            return [];
        }

        $gp = [];
        $rezepte = [];
        $pairing = [];
        foreach ($tokens as $token) {
            // Kandidaten (kind=gp|recipe, score) — dieselbe Retrieval-Logik wie der Resolver.
            foreach ($this->matcher->candidatesFor($team, $token, null, self::CAND_PER_TOKEN) as $c) {
                $kind = $c['kind'] ?? null;
                $id = $c['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                if ($kind === 'gp' && ! isset($gp[$id])) {
                    $gp[$id] = ['id' => (int) $id, 'name' => $c['name'] ?? null, 'score' => round((float) ($c['score'] ?? 0), 3)];
                } elseif ($kind === 'sub' && ! isset($rezepte[$id])) {
                    $rezepte[$id] = ['id' => (int) $id, 'name' => $c['name'] ?? null, 'score' => round((float) ($c['score'] ?? 0), 3)];
                }
            }
            // Anker-Graph: Pairing-Partner der Hauptzutat.
            $nb = $this->pairing->neighborsForName($token, null, self::PAIRING_PER_TOKEN);
            $partner = collect($nb['partner'] ?? [])
                ->map(fn ($p) => is_array($p) ? ($p['display_de'] ?? $p['slug'] ?? null) : ($p->display_de ?? $p->slug ?? null))
                ->filter()->values()->all();
            if ($partner !== []) {
                $pairing[$token] = $partner;
            }
        }

        $out = [];
        if ($gp !== []) {
            $out['gp_kandidaten'] = [
                'hinweis' => 'Benenne Zutaten wenn möglich exakt auf diese EXISTIERENDEN Grundprodukte (gp_id nutzen) statt neue zu erfinden.',
                'treffer' => array_slice(array_values($gp), 0, self::GP_CAND_MAX),
            ];
        }
        if ($rezepte !== []) {
            $out['rezept_kandidaten'] = [
                'hinweis' => 'Vorhandene Rezepte als Komponente wiederverwenden (referenced_recipe_id) statt nachzubauen.',
                'treffer' => array_slice(array_values($rezepte), 0, self::REZEPT_CAND_MAX),
            ];
        }
        if ($pairing !== []) {
            $out['pairing'] = [
                'rolle' => $vkModus ? 'komposition' : 'aroma_ausschoepfen',
                'hinweis' => $vkModus
                    ? 'Anker-Graph-Partner je Hauptzutat — für eine zusammenhängende Komposition (Teller-Kohärenz) nutzen.'
                    : 'Anker-Graph-Partner je Hauptzutat — um das Aroma der Zutat voll auszuschöpfen (abrunden/vertiefen).',
                'partner' => $pairing,
            ];
        }

        return $out;
    }

    /**
     * Leit-Tokens der Beschreibung (≥4 Zeichen, dedupe, gekappt) — dieselbe
     * Token-Basis wie bestandsInventar, damit Erdung und Reuse konsistent sind.
     *
     * @return list<string>
     */
    private function leitTokens(string $description): array
    {
        $tokens = array_values(array_unique(array_filter(
            $this->tokens->tokenize($description),
            fn ($t) => mb_strlen((string) $t) >= 4,
        )));

        return array_slice($tokens, 0, self::MAX_TOKENS);
    }
}
