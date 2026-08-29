<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\Route;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Phase N: Seiten-Navigation (kein DB-Write). Validiert route_key gegen den Katalog (ui.ROUTES),
 * prüft bei id die Sichtbarkeit des Datensatzes und liefert den Laravel-Route-Namen + URL zurück.
 * Das Frontend führt den Redirect aus (type→route-Map im JS-Listener).
 */
class UiNavigateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** record-Typ (aus dem Katalog) → sichtbarkeits-prüfbares Model. */
    private const RECORD_MODELS = [
        'gp' => \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
    ];

    public function getName(): string
    {
        return 'foodalchemist.ui.NAVIGATE';
    }

    public function getDescription(): string
    {
        return 'Navigiert zu einer FA-Seite (route_key aus ui.ROUTES; optional id für Detail-Seiten, optional params). '
            . 'Reine UI-Aktion — liefert Route-Name + URL, den Redirect führt das Frontend aus.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route_key' => ['type' => 'string', 'description' => 'Schlüssel aus foodalchemist.ui.ROUTES.'],
                'id' => ['type' => 'integer', 'description' => 'Datensatz-id (nur bei Detail-Seiten mit expects_record).'],
                'params' => ['type' => 'object', 'description' => 'Optionale Query-/Routen-Parameter (z.B. tab, sektion).'],
            ],
            'required' => ['route_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $key = trim((string) ($arguments['route_key'] ?? ''));
        $catalog = $this->uiRouteCatalog();
        if (! isset($catalog[$key])) {
            return ToolResult::error('Unbekannter route_key — Katalog via foodalchemist.ui.ROUTES.', 'NOT_FOUND');
        }
        $def = $catalog[$key];
        $params = is_array($arguments['params'] ?? null) ? $arguments['params'] : [];

        // Detail-Seite: id validieren + Sichtbarkeit prüfen.
        if (isset($def['record'])) {
            $id = (int) ($arguments['id'] ?? 0);
            if ($id <= 0) {
                return ToolResult::error("route_key „{$key}“ erwartet eine id (Datensatz {$def['record']}).", 'VALIDATION_ERROR');
            }
            $model = self::RECORD_MODELS[$def['record']] ?? null;
            if ($model !== null && ! $model::visibleToTeam($team)->whereKey($id)->exists()) {
                return ToolResult::error('Datensatz nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
            $params = ['grundprodukt' => $id] + $params;
        }

        $url = null;
        if (Route::has($def['route'])) {
            try {
                $url = route($def['route'], $params, false);
            } catch (\Throwable) {
                $url = null;   // Parameter-Mismatch: Route-Name reicht dem Frontend
            }
        }

        return ToolResult::success([
            'navigate' => [
                'route_key' => $key,
                'route' => $def['route'],
                'label' => $def['label'],
                'url' => $url,
                'params' => $params,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'utility',
            'tags' => ['foodalchemist', 'ui', 'navigation'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.ui.ROUTES', 'foodalchemist.ui.OPEN'],
            'examples' => ['Geh zur Bestellwesen-Seite', 'Öffne die Grundprodukt-Detailseite von GP 123'],
        ];
    }
}
