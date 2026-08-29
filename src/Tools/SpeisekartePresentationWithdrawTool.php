<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 43 (write): Zieht die Veröffentlichung einer Speisekarte zurück (Public-Link → 404).
 * Snapshot + Token bleiben. Nur eigene Karten.
 */
class SpeisekartePresentationWithdrawTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_presentation.WITHDRAW';
    }

    public function getDescription(): string
    {
        return 'Zieht die Veröffentlichung einer Speisekarte zurück (Public-Link → 404). Nur eigene Karten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional (Slice F): zieht NUR den Betriebs-Link dieses Betriebs zurück; ohne outlet_id den Standard-Link am Dokument-Kopf.'],
            ],
            'required' => ['speisekarte_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            if (($arguments['outlet_id'] ?? null) !== null) {
                app(PresentationService::class)->withdrawForOutlet($team, 'speisekarte', (int) $arguments['speisekarte_id'], (int) $arguments['outlet_id']);
            } else {
                app(PresentationService::class)->withdraw($team, 'speisekarte', (int) $arguments['speisekarte_id']);
            }
        } catch (ModelNotFoundException) {
            return ToolResult::error('Speisekarte nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['ok' => true, 'speisekarte_id' => (int) $arguments['speisekarte_id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'speisekarte', 'praesentation', 'zurueckziehen'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_presentation.PUBLISH'],
            'examples' => ['Zieh die Veröffentlichung von Speisekarte 7 zurück.'],
        ];
    }
}
