<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2: Rezept-Eignung setzen/entfernen (Niveau + Sektor). Gilt für Basis- und
 * VK-Rezepte. Nur team-eigene Rezepte (Service self-gatet, Tool prüft vor für saubere Codes).
 */
class RecipeEignungPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const TYPEN = ['level', 'sektor'];

    private const SLUGS = [
        'level' => ['haute_cuisine', 'gehoben', 'klassisch'],
        'sektor' => ['business', 'care', 'crew', 'event_privat', 'kita_schule', 'restaurant'],
    ];

    public function getName(): string
    {
        return 'foodalchemist.recipe_eignung.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt/entfernt eine Eignung eines team-eigenen Rezepts. typ=level (haute_cuisine|gehoben|klassisch) '
            . 'oder typ=sektor (business|care|crew|event_privat|kita_schule|restaurant). action=set|remove.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (Basis oder VK, team-eigen).'],
                'typ' => ['type' => 'string', 'enum' => self::TYPEN, 'description' => 'level oder sektor.'],
                'slug' => ['type' => 'string', 'description' => 'Eignungs-Slug passend zum typ.'],
                'action' => ['type' => 'string', 'enum' => ['set', 'remove'], 'description' => 'Setzen oder entfernen.'],
            ],
            'required' => ['recipe_id', 'typ', 'slug', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $typ = (string) ($arguments['typ'] ?? '');
        $slug = (string) ($arguments['slug'] ?? '');
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($typ, self::TYPEN, true)) {
            return ToolResult::error('typ muss level|sektor sein.', 'VALIDATION_ERROR');
        }
        if (! in_array($slug, self::SLUGS[$typ], true)) {
            return ToolResult::error("Unbekannter {$typ}-Slug. Erlaubt: " . implode(', ', self::SLUGS[$typ]), 'VALIDATION_ERROR');
        }
        if (! in_array($action, ['set', 'remove'], true)) {
            return ToolResult::error('action muss set|remove sein.', 'VALIDATION_ERROR');
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Pflege nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        $svc = app(RecipeService::class);
        try {
            if ($action === 'set') {
                $svc->setzeEignung($team, (int) $recipe->id, $typ, $slug, 'manual');
            } else {
                $svc->entferneEignung($team, (int) $recipe->id, $typ, $slug);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'typ' => $typ, 'slug' => $slug, 'action' => $action]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'eignung', 'niveau', 'sektor', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Markiere Rezept 12 als für den Sektor „care" geeignet.'],
        ];
    }
}
