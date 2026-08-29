<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * MCP-Steuerbarkeit · D11 (cross-module aus D10): ein Angebot in die Produktion geben — erzeugt
 * Produktionsaufträge für die Menüs des Angebots. Confirm=true Pflicht.
 */
class AngeboteAnproduktionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.ANPRODUKTION';
    }

    public function getDescription(): string
    {
        return 'Gibt ein team-eigenes Angebot in die Produktion — erzeugt Produktionsaufträge für die Menüs. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (erzeugt Produktionsaufträge).'],
            ],
            'required' => ['id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Anproduktion erfordert confirm=true (erzeugt Produktionsaufträge).', 'CONFIRM_REQUIRED');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot')) !== null) {
            return $guard;
        }

        try {
            $res = app(AngebotService::class)->anProduktion($team, $id, $context->user->id ?? null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'result' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'produktion', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.angebote.STATUS'],
            'examples' => ['Gib Angebot 5 in die Produktion (confirm=true).'],
        ];
    }
}
