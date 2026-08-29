<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: KI-Kundentext-Vorschlag für ein Foodbook-KAPITEL. Grundet (Workstream W)
 * wie die Buch-Ebene auf Cross-Cutting + Food-DNA. Persistiert nichts (Vorschlag).
 */
class FoodbookKapitelKundentextTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE';
    }

    public function getDescription(): string
    {
        return 'Erzeugt einen KI-Kundentext-Vorschlag für ein Foodbook-Kapitel. Persistiert nichts — '
            . 'gibt Text + Konfidenz zurück; Übernehmen via foodbook_kapitel.PUT (description).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['kapitel_id' => ['type' => 'integer', 'description' => 'Kapitel-Id (team-eigen).']], 'required' => ['kapitel_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        $fb = $this->foodbookVonKapitel($team, $kapitelId);
        if ($fb === null) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $fb->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team des Foodbooks.', 'ACCESS_DENIED');
        }

        try {
            $res = app(FoodbookService::class)->kiKapitelKundentextVorschlag($team, $kapitelId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel_id' => $kapitelId, 'text' => $res['text'], 'confidence' => $res['confidence'] ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'kundentext', 'ki'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.foodbook.KUNDENTEXT_GENERATE'],
            'examples' => ['Schlag einen Kundentext für Kapitel 12 vor.'],
        ];
    }
}
