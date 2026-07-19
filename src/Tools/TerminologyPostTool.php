<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * #507 Weg-2 · E7-b: einen Terminologie-Eintrag anlegen — Alias-Gruppe (Synonyme) ODER
 * Anti-Marker (Verwechslungs-Sperre). Schreibt in den GLOBALEN Master (team_id NULL,
 * Governance FA=Master); wirkt beim nächsten Matching sofort (kein Deploy). Das ist die
 * runtime-Senke der E7-c-Lernschleife für neue Namen.
 */
class TerminologyPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.terminology.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Terminologie-Eintrag im globalen Master an. kind="alias": members = '
            . 'Satz bedeutungsgleicher Phrasen (≥2, z. B. ["paradeiser","tomate"]). kind="anti_marker": '
            . 'trigger + forbid (+ optional unless) — unterdrückt Verwechslungs-Kandidaten. Wirkt sofort '
            . 'beim nächsten Zutat→GP-Matching. Bestand/IDs via foodalchemist.terminology.LIST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['alias', 'anti_marker']],
                'members' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'alias: ≥2 bedeutungsgleiche Phrasen'],
                'trigger' => ['type' => 'string', 'description' => 'anti_marker: Query-Token'],
                'forbid' => ['type' => 'string', 'description' => 'anti_marker: zu unterdrückendes Kandidaten-Token'],
                'unless' => ['type' => 'string', 'description' => 'anti_marker: Guard-Token (legitimer Treffer)'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['kind'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kind = (string) ($arguments['kind'] ?? '');
        $note = trim((string) ($arguments['note'] ?? '')) ?: null;
        $svc = app(TerminologyService::class);

        try {
            if ($kind === 'alias') {
                $row = $svc->createAlias((array) ($arguments['members'] ?? []), $note, 'mcp');

                return ToolResult::success(['kind' => 'alias', 'id' => $row->id, 'members' => $row->members]);
            }

            if ($kind === 'anti_marker') {
                $row = $svc->createAntiMarker(
                    (string) ($arguments['trigger'] ?? ''),
                    (string) ($arguments['forbid'] ?? ''),
                    (string) ($arguments['unless'] ?? ''),
                    $note,
                    'mcp',
                );

                return ToolResult::success([
                    'kind' => 'anti_marker', 'id' => $row->id,
                    'trigger' => $row->trigger_token, 'forbid' => $row->forbid_token, 'unless' => $row->unless_token,
                ]);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'INVALID');
        }

        return ToolResult::error('kind muss "alias" oder "anti_marker" sein.', 'INVALID');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'terminology', 'alias', 'anti-marker', 'lernschleife'],
            'related_tools' => ['foodalchemist.terminology.LIST'],
            'examples' => [
                'Lege Alias an: Paradeiser = Tomate',
                'Anti-Marker: bei "brie" den Kandidaten "bries" sperren',
            ],
        ];
    }
}
