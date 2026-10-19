<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 85 D-7 PR 2 — Hausstandard des Teams entfernen (nur `ebene=team`): ab sofort gilt wieder Oberteam bzw. global.
 * Globale Regeln werden nie gelöscht (Plattform-Admin deaktiviert sie in Einstellungen › Regeln).
 */
class RulesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.rules.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt den Hausstandard des Teams zu einer übersteuerbaren Regel (ebene=team) — danach gilt wieder der Wert des '
            . 'Oberteams bzw. der globale. Nur ein Mensch mit FA-Admin; die KI schlägt vor, entfernt nicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['schluessel', 'ebene'],
            'properties' => [
                'schluessel' => ['type' => 'string', 'description' => 'z. B. haccp.heisshalten'],
                'ebene' => ['type' => 'string', 'enum' => ['team']],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['ebene'] ?? '') !== 'team') {
            return ToolResult::error('Nur ebene=team — globale Regeln werden nicht gelöscht.', 'VALIDATION_ERROR');
        }
        try {
            $weg = app(RegelService::class)->entferneTeam($team, trim((string) ($arguments['schluessel'] ?? '')), $context->user);
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        }

        return ToolResult::success(['ebene' => 'team', 'schluessel' => (string) $arguments['schluessel'], 'entfernt' => $weg,
            'hinweis' => $weg ? 'Es gilt wieder der Wert des Oberteams bzw. der globale.' : 'Das Team hatte keine eigene Abweichung.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config', 'tags' => ['foodalchemist', 'regeln', 'hausstandard', 'team', 'haccp'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.rules.GET', 'foodalchemist.rules.PUT'],
            'examples' => ['Setze unseren Hausstandard für Heißhalten zurück auf den globalen Wert'],
        ];
    }
}
