<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: KI-Kundentext-Vorschlag fürs Foodbook (Buch-Ebene). Grundet (Workstream W)
 * über den Service auf Cross-Cutting-Fakten + Food-DNA. Liefert NUR einen Vorschlag — das Übernehmen
 * (foodbooks.PUT description) bleibt ein bewusster Akt.
 */
class FoodbookKundentextTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook.KUNDENTEXT_GENERATE';
    }

    public function getDescription(): string
    {
        return 'Erzeugt einen KI-Kundentext-Vorschlag fürs Foodbook (Buch-Ebene). Persistiert nichts — '
            . 'gibt Text + Konfidenz zurück; Übernehmen via foodbooks.PUT (description).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['foodbook_id' => ['type' => 'integer', 'description' => 'Foodbook-Id (team-eigen).']], 'required' => ['foodbook_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['foodbook_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFoodbook::class, $id, 'Foodbook')) !== null) {
            return $guard;
        }

        try {
            $res = app(FoodbookService::class)->kiKundentextVorschlag($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['foodbook_id' => $id, 'text' => $res['text'], 'confidence' => $res['confidence'] ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kundentext', 'ki'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE', 'foodalchemist.foodbooks.PUT'],
            'examples' => ['Schlag einen Kundentext für Foodbook 5 vor.'],
        ];
    }
}
