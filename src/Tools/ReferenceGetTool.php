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
        return 'Kulinarische Referenz-Werte (deterministisch, mit Quelle). '
            . 'kind=core_temp: Kerntemperaturen als QUALITÄTS-Zielwerte (Rind rosa 52 °C, Geflügel 68 °C) — '
            . 'KEINE harte Vorschrift: Sicherheit ist Zeit-Temperatur; is_hard_safety nur bei Hack/Brät. '
            . 'kind=hydrocolloid: Hydrokolloid-Dosier-Ranges (Agar/Xanthan/Gellan/Alginat …, % vom Ansatz). '
            . 'kind=hlb: HLB-Werte von Emulgatoren (<6 W/O, >8 O/W). Optional filter (protein|agent|emulsifier). '
            . 'Jede Zeile trägt Hinweis + Quelle; amtliche HACCP-Grenzwerte bzw. Herstellerangaben haben Vorrang.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['core_temp', 'hydrocolloid', 'hlb'], 'default' => 'core_temp', 'description' => 'Referenz-Art'],
                'filter' => ['type' => 'string', 'description' => 'Optionaler Filter — Protein (core_temp) / Agent (hydrocolloid) / Emulgator (hlb)'],
                'protein' => ['type' => 'string', 'description' => 'Alias für filter bei kind=core_temp'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $kind = (string) ($arguments['kind'] ?? 'core_temp');
        $svc = app(CulinaryReferenceService::class);
        $filter = isset($arguments['filter']) ? (string) $arguments['filter']
            : (isset($arguments['protein']) ? (string) $arguments['protein'] : null);

        return match ($kind) {
            'core_temp' => ToolResult::success([
                'kind' => 'core_temp',
                'disclaimer' => 'Qualitäts-Zielwerte für Textur/Saftigkeit, KEINE Vorschrift. Sicherheit = Zeit-Temperatur '
                    . '(Pasteurisierung); amtliche/lokale HACCP-Grenzwerte haben Vorrang. is_hard_safety=true = echter '
                    . 'Sicherheitsboden (durcherhitzen).',
                'proteine' => $svc->proteine(),
                'rows' => $svc->kerntemperaturen($filter),
            ]),
            'hydrocolloid' => ToolResult::success([
                'kind' => 'hydrocolloid',
                'disclaimer' => 'Publizierte Dosier-Ranges (% vom Ansatzgewicht = Extraprozent). Herstellerangabe/Charge '
                    . 'hat Vorrang; im Zweifel Vorversuch.',
                'rows' => $svc->hydrokolloidDosierungen($filter),
            ]),
            'hlb' => ToolResult::success([
                'kind' => 'hlb',
                'disclaimer' => 'HLB-Skala 0–20: <6 Wasser-in-Öl, >8 Öl-in-Wasser. Richtwerte, variieren je Quelle.',
                'rows' => $svc->hlbWerte($filter),
            ]),
            default => ToolResult::error("Unbekannte kind »{$kind}« (core_temp|hydrocolloid|hlb).", 'VALIDATION_ERROR'),
        };
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'kerntemperatur', 'garstufe', 'hydrokolloid', 'dosierung', 'hlb', 'emulgator', 'referenz', 'gastronomie', 'wissen'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => false,   // universelles Nachschlage-Wissen, kein Team-Bezug
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.proportion.CALC', 'foodalchemist.recipes.GET'],
            'examples' => [
                'Auf welche Kerntemperatur gare ich Rind rosa?',
                'Wie viel Agar für ein festes Gel? (kind=hydrocolloid)',
                'HLB-Wert von Sojalecithin? (kind=hlb)',
            ],
        ];
    }
}
