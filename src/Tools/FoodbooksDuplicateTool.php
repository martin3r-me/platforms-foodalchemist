<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * 98 · 2.3c: Foodbook duplizieren — wie „Duplizieren" in der Foodbook-Übersicht (FoodbookService::dupliziere).
 * Wie in der Oberfläche nur EIGENE Foodbooks (die Kopie übernimmt Bildpfade der Quelle; Bild entfernen an
 * einer Kopie fremder Foodbooks würde die Datei des Besitzer-Teams löschen). Die Kopie gehört dem eigenen Team.
 * Löschen bleibt bewusst human-only (kein foodbooks.DELETE via MCP).
 */
class FoodbooksDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Kopiert ein sichtbares Foodbook als neues, team-eigenes Foodbook: Kopf mit Branding, Kapitelbaum, Blöcke, Staffeln. '
            . 'Auch geerbte Foodbooks (z. B. vom Master) lassen sich kopieren; Bilder werden als eigene Dateien des Teams kopiert. '
            . 'Die Kopie heißt „<Name> (Kopie)“, steht im Status Entwurf und ist weder freigegeben noch veröffentlicht. '
            . 'Löschen eines Foodbooks gibt es per MCP bewusst nicht.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Foodbook-Id (Quelle).'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $quelle = FoodAlchemistFoodbook::visibleToTeam($team)->find($id);
        if ($quelle === null) {
            return ToolResult::error('Foodbook nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $kopie = app(FoodbookService::class)->dupliziere($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'foodbook' => ['id' => (int) $kopie->id, 'label' => $kopie->label, 'status' => $kopie->statusWert()->value],
            'source_id' => $id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kopieren', 'duplicate', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.foodbooks.GET', 'foodalchemist.foodbooks.PUT'],
            'examples' => ['Dupliziere Foodbook 5.'],
        ];
    }
}
