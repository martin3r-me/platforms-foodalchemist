<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\CulinaryReferenceService;

/**
 * #513 Tier 1 / Punkt 2 — Kulinarische Referenz-Werte als MCP-Tool (read-only, deterministisch).
 * Aktuell: Kerntemperaturen (Qualitäts-Zielwerte, KEINE harte Vorschrift — Sicherheit ist
 * Zeit-Temperatur). Vorwärtskompatibel via kind (später hydrocolloid/hlb). Der LLM ruft das
 * als Fakt mit Provenienz; jede Zeile trägt den Weichheits-/HACCP-Hinweis mit.
 */
class ReferenceGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.reference.GET';
    }

    public function getDescription(): string
    {
        return 'Kulinarische Referenz-Werte (deterministisch, mit Quelle). kind=core_temp: '
            . 'Kerntemperaturen als QUALITÄTS-Zielwerte (z. B. Rind rosa 52 °C, Geflügel 68 °C) — '
            . 'KEINE harte Sicherheitsvorschrift: Sicherheit ist Zeit-Temperatur (Pasteurisierung). '
            . 'is_hard_safety=true nur bei Hackfleisch/Geflügel-Hack (durcherhitzen). Optional protein-Filter. '
            . 'Jede Zeile trägt Hinweis + Quelle; amtliche/lokale HACCP-Grenzwerte haben Vorrang.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['core_temp'], 'default' => 'core_temp', 'description' => 'Referenz-Art (aktuell nur core_temp)'],
                'protein' => ['type' => 'string', 'description' => 'Optionaler Protein-Filter (rind|kalb|lamm|schwein|gefluegel|ente|fisch|hackfleisch|gefluegel_hack|ei)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $kind = (string) ($arguments['kind'] ?? 'core_temp');
        if ($kind !== 'core_temp') {
            return ToolResult::error("Unbekannte kind »{$kind}« (aktuell nur core_temp).", 'VALIDATION_ERROR');
        }

        $svc = app(CulinaryReferenceService::class);
        $protein = isset($arguments['protein']) ? (string) $arguments['protein'] : null;

        return ToolResult::success([
            'kind' => 'core_temp',
            'disclaimer' => 'Qualitäts-Zielwerte für Textur/Saftigkeit, KEINE Vorschrift. Lebensmittelsicherheit '
                . '= Zeit-Temperatur (Pasteurisierung); amtliche/lokale HACCP-Grenzwerte haben Vorrang. '
                . 'is_hard_safety=true = echter Sicherheitsboden (durcherhitzen).',
            'proteine' => $svc->proteine(),
            'rows' => $svc->kerntemperaturen($protein),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'kerntemperatur', 'garstufe', 'referenz', 'gastronomie', 'haccp', 'wissen'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => false,   // universelles Nachschlage-Wissen, kein Team-Bezug
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.proportion.CALC', 'foodalchemist.recipes.GET'],
            'examples' => [
                'Auf welche Kerntemperatur gare ich Rind rosa?',
                'Kerntemperatur Geflügel — und ab wann ist es sicher?',
                'Zeig die Kerntemperatur-Referenz für Fisch',
            ],
        ];
    }
}
