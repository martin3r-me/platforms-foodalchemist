<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\RezeptFolgeService;

/** Spec 82 §12 B / 4d · Neuere Abo-Fassung an einem eigenen Rezept übernehmen. Bei geändertem Allergenbild nur Rolle Freigeben und mit bestaetigt=true. */
class RecipeFassungUebernehmenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_fassungen.UEBERNEHMEN_POST';
    }

    public function getDescription(): string
    {
        return 'Übernimmt die neuere Abo-Fassung an einem eigenen Rezept: die Zutat zeigt danach auf die neue Fassung, das Rezept rechnet mit den Team-Kosten neu. '
            .'Ändert die neue Fassung das Allergen- oder Zusatzstoffbild, braucht es bestaetigt=true (nach Durchsicht von recipe_fassungen.UEBERNEHMEN_GET) und die Rolle Freigeben; '
            .'Person und Zeitpunkt werden protokolliert. Sonst genügt Kuratieren. Eingefrorene Angebote behalten ihren Stand.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recipe_id' => ['type' => 'integer'], 'familie_id' => ['type' => 'integer'],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'Pflicht bei geändertem Allergenbild.'],
        ], 'required' => ['recipe_id', 'familie_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $bericht = app(RezeptFolgeService::class)->rezeptUebernehmen($team, $context->user, (int) $arguments['recipe_id'], (int) $arguments['familie_id'], ! empty($arguments['bestaetigt']));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bericht' => $bericht]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'recipe', 'fassung', 'allergen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_fassungen.UEBERNEHMEN_GET'],
            'examples' => ['Übernimm die neue Jus-Fassung an meinem Rezept Lamm mit Jus.'],
        ];
    }
}
