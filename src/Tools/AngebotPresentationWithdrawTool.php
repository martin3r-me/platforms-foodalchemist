<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * #380 Composer / Spec 43 (write): Zieht die Veröffentlichung eines Angebot-Kundenbuchs zurück — der
 * Public-Link liefert danach 404. Snapshot + Token bleiben erhalten (Wieder-Freigabe möglich).
 * Spiegelt FoodbookPresentationWithdrawTool.
 */
class AngebotPresentationWithdrawTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_presentation.WITHDRAW';
    }

    public function getDescription(): string
    {
        return 'Zieht die Veröffentlichung eines Angebot-Kundenbuchs zurück (Public-Link → 404). '
            . 'Snapshot und Token bleiben erhalten. Nur eigene Angebote.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angebot_id' => ['type' => 'integer'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: zieht NUR den Betriebs-Link dieses Betriebs zurück; ohne outlet_id den Standard-Link am Dokument-Kopf.'],
            ],
            'required' => ['angebot_id'],
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
                app(PresentationService::class)->withdrawForOutlet($team, 'angebot', (int) $arguments['angebot_id'], (int) $arguments['outlet_id']);
            } else {
                app(PresentationService::class)->withdraw($team, 'angebot', (int) $arguments['angebot_id']);
            }
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['ok' => true, 'angebot_id' => (int) $arguments['angebot_id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'angebot', 'praesentation', 'zurueckziehen'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.angebot_presentation.PUBLISH'],
            'examples' => ['Zieh die Veröffentlichung von Angebot 5 zurück.'],
        ];
    }
}
