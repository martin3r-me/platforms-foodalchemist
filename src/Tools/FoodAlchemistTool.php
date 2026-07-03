<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;

/**
 * M8-01: Basis für Modul-Tools (ToolContract) — Naming `<modul>.resource.VERB`
 * (REST-Verben; Punkte werden vom MCP-Server zu __). Tools rufen SERVICES,
 * nie Models (LLM-First-Prinzip); Team kommt aus dem ToolContext.
 */
abstract class FoodAlchemistTool
{
    /** Phase A: Nur in diesen Status darf der MCP-Pfad Rezepte mutieren (Draft-Quarantäne). */
    protected const KI_EDITIERBARE_STATUS = ['stub', 'draft'];

    protected function team(ToolContext $context): ?Team
    {
        $team = $context->team;
        if ($team instanceof Team) {
            return $team;
        }
        // Kontext liefert je nach Aufrufpfad das Core-Team-Objekt oder nichts —
        // dann auf die User-Relation zurückfallen (gleiches Verhalten wie UI)
        $user = $context->user;

        return method_exists($user, 'currentTeamRelation') ? $user->currentTeamRelation : null;
    }

    /**
     * Phase A: MCP-Zutat-Zeilen → syncIngredients-Format. Löst `einheit`
     * (Slug/Name) in einheit_vocab_id auf; wirft RuntimeException mit
     * verfügbaren Einheiten, wenn nichts passt.
     *
     * @return array<int, array>
     */
    protected function normalisiereZutatZeilen(Team $team, array $zeilen): array
    {
        $vocab = app(\Platform\FoodAlchemist\Services\VocabularyService::class);
        $out = [];
        foreach (array_values($zeilen) as $i => $z) {
            $einheit = $vocab->findEinheit($team, (string) ($z['einheit'] ?? ''));
            if ($einheit === null) {
                $verfuegbar = $vocab->listEinheiten($team)->pluck('slug')->implode(', ');
                throw new \RuntimeException('Unbekannte Einheit "' . ($z['einheit'] ?? '') . '" (Zeile ' . ($i + 1) . "). Verfügbar: {$verfuegbar}");
            }
            $out[] = [
                'gp_id' => $z['gp_id'] ?? null,
                'referenced_recipe_id' => $z['referenced_recipe_id'] ?? null,
                'raw_text' => (string) ($z['name'] ?? ''),
                'display_name' => (string) ($z['name'] ?? ''),
                'menge' => $z['menge'] ?? 0,
                'menge_max' => $z['menge_max'] ?? null,
                'einheit_vocab_id' => $einheit->id,
                'putzverlust_pct' => $z['putzverlust_pct'] ?? null,
                'garverlust_pct' => $z['garverlust_pct'] ?? null,
                'garverlust_quelle' => isset($z['garverlust_pct']) ? 'ki' : null,   // GL-07-Lineage
                'is_optional' => (bool) ($z['is_optional'] ?? false),
                'note' => $z['note'] ?? null,
                'rolle' => $z['rolle'] ?? null,
            ];
        }

        return $out;
    }

    /** Status-Wert enum-sicher als String (recipes.status ist RecipeStatus-Enum-Cast). */
    protected function statusWert(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): string
    {
        return $recipe->status instanceof \BackedEnum ? $recipe->status->value : (string) $recipe->status;
    }

    /** Phase A: Draft-Quarantäne-Guard — approved/review/archived sind für den MCP-Pfad gesperrt. */
    protected function kiEditGesperrt(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): ?string
    {
        $status = $this->statusWert($recipe);
        if (! in_array($status, self::KI_EDITIERBARE_STATUS, true)) {
            return "Rezept hat Status \"{$status}\" — via MCP sind nur stub/draft editierbar. "
                . 'Änderungen an gepflegten Rezepten laufen über den Editor (GL-07/Override-First).';
        }

        return null;
    }
}
