<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\Route;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Phase N: selbstbeschreibender Navigations-Katalog. Listet die verfügbaren Seiten (route_key + Label
 * + ob ein Datensatz-id erwartet wird), damit ein Agent Ziele nicht hartkodieren muss. Read-only.
 */
class UiRoutesTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ui.ROUTES';
    }

    public function getDescription(): string
    {
        return 'Listet die navigierbaren FA-Seiten (route_key, label, ob ein Datensatz-id erwartet wird). '
            . 'Ziel-Navigation via foodalchemist.ui.NAVIGATE; einzelnen Datensatz öffnen via foodalchemist.ui.OPEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $routes = [];
        foreach ($this->uiRouteCatalog() as $key => $def) {
            $routes[] = [
                'route_key' => $key,
                'label' => $def['label'],
                'expects_record' => $def['record'] ?? null,
                'registered' => Route::has($def['route']),
            ];
        }

        return ToolResult::success([
            'routes' => $routes,
            'record_types' => ['recipe', 'verkaufsrezept', 'gp', 'concept', 'paket', 'foodbook', 'speisekarte', 'speiseplan', 'angebot', 'format', 'supplier', 'order', 'production_order'],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'ui', 'navigation', 'routes'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.ui.NAVIGATE', 'foodalchemist.ui.OPEN'],
            'examples' => ['Welche Seiten kann ich ansteuern?'],
        ];
    }
}
