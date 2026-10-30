<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;

/** Spec 93 C · Trend-Automatik einstellen: Stufe, Schatten-Modus, Schwellen, Trend-KI-Budget (FA-Admin, wie alle Einstellungen). */
class TrendAutomatikPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_automatik.PUT';
    }

    public function getDescription(): string
    {
        return 'Stellt die Trend-Automatik des Teams ein (FA-Admin): stufe (aus|bis_geprueft|radar), schatten (true = nur rechnen), regeln (Objekt mit '
            .'Schwellen, z. B. kandidat_sichtungen; null-Wert = Startwert), regeln_zuruecksetzen=true (alle Startwerte), ki_budget_eur (Monat; null = kein '
            .'eigener Deckel). Schwellen werden gegen Grenzen geprüft.';
    }

    public function getSchema(): array
    {
        $regeln = [];
        foreach (TrendAutomatikRegeln::SCHLUESSEL as $k => [$label]) {
            $regeln[$k] = ['type' => 'number', 'description' => $label.' (null = Startwert)'];
        }

        return ['type' => 'object', 'properties' => [
            'stufe' => ['type' => 'string', 'enum' => array_keys(TrendAutomatikRegeln::STUFEN)],
            'schatten' => ['type' => 'boolean'],
            'regeln' => ['type' => 'object', 'properties' => $regeln],
            'regeln_zuruecksetzen' => ['type' => 'boolean'],
            'ki_budget_eur' => ['type' => 'number', 'description' => 'Euro je Monat; null = kein eigener Deckel'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $settings = app(TeamSettingsService::class);
        $update = [];
        try {
            if (array_key_exists('stufe', $arguments)) {
                if (! array_key_exists((string) $arguments['stufe'], TrendAutomatikRegeln::STUFEN)) {
                    throw new \RuntimeException('stufe muss aus, bis_geprueft oder radar sein.');
                }
                $update['trend_automatik'] = (string) $arguments['stufe'];
            }
            if (array_key_exists('schatten', $arguments)) {
                $update['trend_automatik_schatten'] = (bool) $arguments['schatten'];
            }
            if (! empty($arguments['regeln_zuruecksetzen'])) {
                $update['trend_automatik_regeln'] = null;
            } elseif (isset($arguments['regeln']) && is_array($arguments['regeln'])) {
                $alt = $settings->trendAutomatikRegeln($team) ?? [];
                $neu = array_merge($alt, TrendAutomatikRegeln::pruefen(array_filter($arguments['regeln'], fn ($v) => $v !== null)));
                foreach ($arguments['regeln'] as $k => $v) {
                    if ($v === null) {
                        unset($neu[$k]);
                    }
                }
                $update['trend_automatik_regeln'] = $neu !== [] ? $neu : null;
            }
            if (array_key_exists('ki_budget_eur', $arguments)) {
                $b = $arguments['ki_budget_eur'];
                $update['trend_ki_budget_eur'] = $b === null || $b === '' ? null : max(0, min(10000, (float) $b));
            }
            if ($update === []) {
                return ToolResult::error('Nichts zu ändern.', 'VALIDATION_ERROR');
            }
            $settings->update($team, $update);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(app(TrendAutomatikService::class)->einstellungen($team));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'automatik', 'einstellungen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.trend_automatik.GET'],
            'examples' => ['Setz die Trend-Automatik auf „bis geprüft“, Schatten-Modus aus.', 'Kandidat erst ab 4 Sichtungen.'],
        ];
    }
}
