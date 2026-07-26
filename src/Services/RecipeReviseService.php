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
            $zeilen[] = [
                'id' => $orig?->id,
                'gp_id' => $orig?->gp_id,                             // Verknüpfung des Originals bleibt
                'referenced_recipe_id' => $orig?->referenced_recipe_id,
                'raw_text' => (string) ($z['text'] ?? $orig?->raw_text ?? ''),
                'display_name' => (string) ($z['text'] ?? $orig?->display_name ?? ''),
                'quantity' => $quantity ?? (float) ($orig?->quantity ?? 1),
                'unit_vocab_id' => $einheiten[$z['einheit_slug'] ?? ''] ?? $orig?->unit_vocab_id ?? $einheiten['g'] ?? null,
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

        return $zeilen;
    }
}
