<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M7-10 / Phase N: UI-Aktions-Tool (kein DB-Write) — signalisiert dem Frontend »öffne Datensatz X«.
 * Das Frontend setzt die Aktion um (record-selected-Event → Livewire-Redirect/Detail-Panel).
 * Erweitert (Phase N) auf alle Haupt-Datensatztypen, jeweils mit Sichtbarkeits-Guard.
 */
class UiOpenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Datensatztyp → sichtbarkeits-prüfbares Model. */
    private const MODELS = [
        'recipe' => \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
        'verkaufsrezept' => \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
        'gp' => \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
        'concept' => \Platform\FoodAlchemist\Models\FoodAlchemistConcept::class,
        'paket' => \Platform\FoodAlchemist\Models\FoodAlchemistPaket::class,
        'foodbook' => \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,
        'speisekarte' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::class,
        'speiseplan' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::class,
        'angebot' => \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::class,
        'format' => \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class,
        'supplier' => \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
        'order' => \Platform\FoodAlchemist\Models\FoodAlchemistOrder::class,
        'production_order' => \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::class,
    ];

    public function getName(): string
    {
        return 'foodalchemist.ui.OPEN';
    }

    public function getDescription(): string
    {
        return 'Öffnet einen Datensatz in der Oberfläche (reine UI-Aktion, kein Schreiben). '
            . 'type: recipe|verkaufsrezept|gp|concept|paket|foodbook|speisekarte|speiseplan|angebot|format|supplier|order|production_order. '
            . 'Vorher per SEARCH/LIST die id ermitteln. Zum Wechseln der SEITE: foodalchemist.ui.NAVIGATE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_keys(self::MODELS)],
                'id' => ['type' => 'integer'],
            ],
            'required' => ['type', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $type = (string) ($arguments['type'] ?? '');
        $model = self::MODELS[$type] ?? null;
        if ($model === null) {
            return ToolResult::error('Unbekannter Datensatztyp.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        // Sichtbarkeits-Guard: nur öffnen, was das Team sehen darf. Verkaufsrezept = VK-Gericht.
        $q = $model::visibleToTeam($team)->whereKey($id);
        if ($type === 'verkaufsrezept') {
            $q->where('is_sales_recipe', true);
        } elseif ($type === 'recipe') {
            $q->where('is_sales_recipe', false);
        }
        if (! $q->exists()) {
            return ToolResult::error('Datensatz nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['open' => ['type' => $type, 'id' => $id]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'utility',
            'tags' => ['foodalchemist', 'ui', 'navigation', 'open', 'voice'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ui.NAVIGATE', 'foodalchemist.ui.ROUTES'],
            'examples' => ['Öffne Rezept 456 in der Oberfläche', 'Öffne Angebot 12'],
        ];
    }
}
