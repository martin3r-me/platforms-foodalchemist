<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use RuntimeException;

/**
 * Ändert eine team-eigene Schnellstart-Vorlage: umbenennen (label) und/oder aktiv-schalten (active).
 * Kuratierte Globals sind read-only → Owns-Guard wirft. Kein Umschreiben von Brief/Regler-Snapshot
 * (eine neue Konfiguration = neue Vorlage via POST + alte via DELETE — Snapshots bleiben stabil).
 */
class BriefTemplatesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.brief_templates.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine team-eigene Schnellstart-Vorlage (Brief-Template): umbenennen (label) und/oder '
            . 'aktiv/inaktiv schalten (active). Kuratierte Globals sind read-only. Brief/Leitplanken-Snapshot werden '
            . 'NICHT geändert — für eine neue Konfiguration eine neue Vorlage via foodalchemist.brief_templates.POST anlegen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID der eigenen Vorlage (aus brief_templates.LIST).'],
                'label' => ['type' => 'string', 'description' => 'Neuer Anzeigename (optional).'],
                'active' => ['type' => 'boolean', 'description' => 'Aktiv/inaktiv schalten (optional). Inaktive erscheinen nicht als Chip.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $hatLabel = array_key_exists('label', $arguments) && trim((string) $arguments['label']) !== '';
        $hatActive = array_key_exists('active', $arguments);
        if (! $hatLabel && ! $hatActive) {
            return ToolResult::error('Nichts zu ändern — label und/oder active angeben.', 'NO_CHANGE');
        }
        $svc = app(BriefTemplateService::class);
        try {
            if ($hatLabel) {
                $tpl = $svc->umbenennen($team, $id, (string) $arguments['label']);
            }
            if ($hatActive) {
                $tpl = $svc->setActive($team, $id, (bool) $arguments['active']);
            }
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        return ToolResult::success(BriefTemplatesListTool::arr($tpl));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'planung', 'vorlage', 'template', 'schnellstart', 'brief', 'update', 'rename'],
            'related_tools' => ['foodalchemist.brief_templates.LIST', 'foodalchemist.brief_templates.POST', 'foodalchemist.brief_templates.DELETE'],
            'examples' => ['Benenne Vorlage 12 in „Winter-Buffet" um', 'Deaktiviere Vorlage 7'],
        ];
    }
}
