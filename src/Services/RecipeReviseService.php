<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;

/**
 * Spec 03 L1a: die EINE Revise-Strecke für beide Editoren (Basisrezept +
 * Gericht). Vorher lebte sie als `matchVorschau()` + Inline-Zeilenbau in
 * `Livewire\Recipes\RecipeModal`; das VkModal hätte sie kopieren müssen —
 * und damit hätte der #508-Grounding-Fix künftig zwei Orte gehabt.
 *
 * Hier liegt NUR die Lese-/Übersetz-Hälfte:
 *  - vorschau()   : pro vorgeschlagener Zutat der künftige Grounding-Status
 *  - syncZeilen() : KI-Zutaten → Zeilen-Payload für RecipeService::syncIngredients
 *
 * Der Schreib-Moment bleibt bewusst beim Aufrufer (Übernehmen-Klick, GL-07):
 * dieser Service persistiert nichts.
 */
class RecipeReviseService
{
    /**
     * E3 (#508): pro vorgeschlagener Zutat den künftigen Grounding-Status —
     * spiegelt die Logik von syncIngredients:
     *  - matched  : bestehende GP/Sub-Verknüpfung des Originals bleibt
     *  - grounded : Resolver findet ein GP/Sub-Rezept (wird beim Übernehmen verlinkt)
     *  - hardstop : nichts über der Schwelle → nach dem Übernehmen „GP/Basisrezept
     *               anlegen" (Button-Heuristik + Shortlist-Zähler, analog Generator)
     *
     * @param  array<int, mixed>  $zutaten
     * @return array<int, array{status:string, kind:?string, ziel:?string, primaer:?string, shortlist:int}>
     */
    public function vorschau(Team $team, ?FoodAlchemistRecipe $r, array $zutaten): array
    {
        if ($zutaten === [] || $r === null) {
            return [];
        }
        $original = $r->ingredients->keyBy('id');
        $matcher = app(IngredientMatchService::class);
        $heuristik = app(MatchHeuristics::class);

        $out = [];
        foreach (array_values($zutaten) as $i => $z) {
            if (! is_array($z)) {
                continue;
            }
            $text = trim((string) ($z['text'] ?? ''));
            $orig = isset($z['id']) ? $original->get((int) $z['id']) : null;

            if ($orig !== null && ($orig->gp_id !== null || $orig->referenced_recipe_id !== null)) {
                $out[$i] = ['status' => 'matched', 'kind' => $orig->gp_id !== null ? 'gp' : 'sub',
                    'ziel' => $orig->gp?->name ?? $orig->referencedRecipe?->name, 'primaer' => null, 'shortlist' => 0];
                continue;
            }
            if ($text === '') {
                $out[$i] = ['status' => 'hardstop', 'kind' => null, 'ziel' => null, 'primaer' => 'gp_anlegen', 'shortlist' => 0];
                continue;
            }

            $t = $matcher->matchIngredient($team, $text);
            if ($t['target'] === 'gp') {
                $out[$i] = ['status' => 'grounded', 'kind' => 'gp', 'ziel' => $t['gp_name'], 'primaer' => null, 'shortlist' => 0];
            } elseif ($t['target'] === 'sub_recipe') {
                $out[$i] = ['status' => 'grounded', 'kind' => 'sub', 'ziel' => $t['recipe_name'], 'primaer' => null, 'shortlist' => 0];
            } else {
                $out[$i] = ['status' => 'hardstop', 'kind' => null, 'ziel' => null,
                    'primaer' => $heuristik->istSubRezeptKandidat($text) ? 'basisrezept_anlegen' : 'gp_anlegen',
                    'shortlist' => count($matcher->candidatesFor($team, $text, null, 5))];
            }
        }

        return $out;
    }

    /**
     * KI-Zutaten → Zeilen für syncIngredients. Die Verknüpfung (gp_id /
     * referenced_recipe_id) und die Pflege-Felder des Originals bleiben
     * erhalten; NEUE Zeilen kommen ohne Verknüpfung heraus und laufen im
     * Sync durch den GL-04-Resolver (#508).
     *
     * @param  array<int, mixed>  $zutaten
     * @return array<int, array<string, mixed>>
     */
    public function syncZeilen(FoodAlchemistRecipe $r, array $zutaten): array
    {
        $original = $r->ingredients->keyBy('id');
        $einheiten = FoodAlchemistRecipe::query()->getConnection()->table('foodalchemist_vocab_units')
            ->whereNull('deleted_at')->pluck('id', 'slug');

        $zeilen = [];
        foreach ($zutaten as $z) {
            if (! is_array($z)) {
                continue;
            }
            $orig = isset($z['id']) ? $original->get((int) $z['id']) : null;
            $roh = str_replace(',', '.', (string) ($z['quantity'] ?? ''));
            $quantity = is_numeric($roh) ? (float) $roh : null;
            $zeile = $this->bestandsZeile($orig);
            $zeile['raw_text'] = (string) ($z['text'] ?? $orig?->raw_text ?? '');
            $zeile['display_name'] = (string) ($z['text'] ?? $orig?->display_name ?? '');
            $zeile['quantity'] = $quantity ?? (float) ($orig?->quantity ?? 1);
            $zeile['unit_vocab_id'] = $einheiten[$z['einheit_slug'] ?? ''] ?? $orig?->unit_vocab_id ?? $einheiten['g'] ?? null;
            $zeilen[] = $zeile;
        }

        return $zeilen;
    }

    /**
     * Eine Bestands-Zeile → Sync-Payload, verlustfrei.
     *
     * `syncIngredients` hat Voll-Ersatz-Semantik: was nicht im Payload steht,
     * ist danach weg (Zeile) bzw. genullt (Feld — Wurzel von V-027). Jeder
     * Teil-Schreiber (Revise, Copilot-Apply) muss die unangetasteten Zeilen
     * also vollständig mitschicken. Damit diese Abbildung nur EINMAL existiert,
     * liegt sie hier — `syncZeilen()` und `RecipeReviewService` teilen sie.
     *
     * @param  ?\Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient  $orig
     * @return array<string, mixed>
     */
    public function bestandsZeile($orig): array
    {
        return [
            'id' => $orig?->id,
            'gp_id' => $orig?->gp_id,                                 // Verknüpfung des Originals bleibt
            'referenced_recipe_id' => $orig?->referenced_recipe_id,
            'raw_text' => (string) ($orig?->raw_text ?? ''),
            'display_name' => (string) ($orig?->display_name ?? ''),
            'quantity' => (float) ($orig?->quantity ?? 1),
            'unit_vocab_id' => $orig?->unit_vocab_id,
            'cooking_loss_pct' => $orig?->cooking_loss_pct,
            'cooking_loss_source' => $orig?->cooking_loss_source,
            'trimming_loss_pct' => $orig?->trimming_loss_pct,
            'quantity_max' => $orig?->quantity_max,
            'is_optional' => (bool) ($orig?->is_optional ?? false),
            'is_value_relevant' => (bool) ($orig?->is_value_relevant ?? false),
            'note' => $orig?->note,
            // VK-Kontext: die Rolle ist eine Verkaufs-Facette und wird von einem
            // Zutaten-Revise nie neu gesetzt (nur 🎭 Rollen verteilen schreibt sie).
            'role' => $orig?->role,
        ];
    }

    /**
     * Workstream W (2026-08): grounded Freitext-Revision (`recipe.ueberarbeiten`). Baut den Rezept-Kontext,
     * zieht das Wissens-Grounding (Regelwerk Basisrezepte + Cross-Cutting via KnowledgeContextService::contextFor)
     * und ruft den LLM. Persistiert NICHTS (GL-07) — der Aufrufer übernimmt (Editor: ueberarbeitungUebernehmen;
     * MCP: recipes.REVISE mit accept=true). Web + MCP fahren damit dieselbe geerdete Strecke.
     *
     * @return array{werte: array<string,mixed>, confidence: float}
     */
    public function freitextVorschlag(Team $team, FoodAlchemistRecipe $r, string $anweisung): array
    {
        $r->loadMissing(['ingredients.gp', 'ingredients.referencedRecipe', 'ingredients.unit']);

        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
            ->contextFor('recipe.ueberarbeiten', (string) ($r->description ?: $r->name));

        $vorschlag = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('recipe.ueberarbeiten', [
            'anweisung' => trim($anweisung),
            'name' => $r->name,
            'description' => $r->description,
            'preparation' => $r->preparation,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'quantity' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
            ])->values()->all(),
        ], ['knowledge' => $wissen['block'] ?? null, 'knowledge_used' => $wissen['files_used'] ?? null]);

        return ['werte' => (array) $vorschlag->werte, 'confidence' => max(0.0, min(1.0, (float) $vorschlag->confidence))];
    }
}
