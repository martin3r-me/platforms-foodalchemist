<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Services\BriefTemplateService;

/**
 * Schnellstart-Vorlagen (Brief-Templates) des Teams auflisten — kuratierte Globals ∪ team-eigene,
 * inkl. inaktive. Eine Vorlage = benannter Startpunkt (Brief + Kreativ-Modus + Leitplanken-Snapshot)
 * je Scope. Anlegen/Ändern/Löschen via brief_templates.POST/PUT/DELETE.
 */
class BriefTemplatesListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.brief_templates.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Schnellstart-Vorlagen (Brief-Templates) des Teams — kuratierte Globals (read-only) ∪ '
            . 'team-eigene (editierbar), inkl. inaktive. Eine Vorlage ist ein benannter Startpunkt für die Planung-Erzeugung: '
            . 'Brief + Kreativ-Modus + Leitplanken-Snapshot, je Scope (rezept|gericht|concept). Optional scope-gefiltert. '
            . 'Anlegen via foodalchemist.brief_templates.POST, ändern via .PUT, löschen via .DELETE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['rezept', 'gericht', 'concept'], 'description' => 'Optional: nur Vorlagen dieses Creation-Tabs. Leer = alle.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = trim((string) ($arguments['scope'] ?? '')) ?: null;
        $rows = app(BriefTemplateService::class)->verwaltung($team, $scope);

        return ToolResult::success([
            'total' => $rows->count(),
            'brief_templates' => $rows->map(fn (FoodAlchemistBriefTemplate $t) => self::arr($t))->all(),
        ]);
    }

    /** Vorlage → MCP-Array (auch von POST/PUT genutzt). */
    public static function arr(FoodAlchemistBriefTemplate $t): array
    {
        $payload = is_array($t->payload) ? $t->payload : [];

        return [
            'id' => (int) $t->id,
            'label' => $t->label,
            'scope' => $t->scope,
            'brief' => $t->brief,
            'titel' => $t->titel,
            'creative_mode' => $payload['creative_mode'] ?? null,
            'regler' => is_array($payload['regler'] ?? null) ? $payload['regler'] : [],
            'active' => (bool) $t->active,
            'is_global' => $t->team_id === null,
            'editable' => $t->team_id !== null,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'planung', 'vorlage', 'template', 'schnellstart', 'brief', 'list'],
            'related_tools' => ['foodalchemist.brief_templates.POST', 'foodalchemist.brief_templates.PUT', 'foodalchemist.brief_templates.DELETE'],
            'examples' => ['Liste die Schnellstart-Vorlagen', 'Welche Gericht-Vorlagen hat mein Team?'],
        ];
    }
}
