<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/** Phase B: Kapitel anlegen (auch verschachtelt) — nur solange das Foodbook draft ist. */
class FoodbookKapitelPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Kapitel in einem draft-Foodbook an (parent_id für Unterkapitel, Position ans Ende). '
            . 'Optional: konsumententitel, claim, description, preis_pro_person (setzt preis_modus=fix — '
            . 'sonst auto-Aggregation aus den Blöcken).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'foodbook_id' => ['type' => 'integer'],
                'titel' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'description' => 'Übergeordnetes Kapitel für Verschachtelung'],
                'konsumententitel' => ['type' => 'string', 'description' => 'Kundenseitiger Titel, falls abweichend'],
                'claim' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'preis_pro_person' => ['type' => 'number', 'description' => 'Fix-Preis p. P.; weglassen = auto aus Blöcken'],
            ],
            'required' => ['foodbook_id', 'titel'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->whereKey((int) $arguments['foodbook_id'])->first();
        if ($fb === null) {
            return ToolResult::error('Foodbook nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ((string) $fb->status !== 'draft') {
            return ToolResult::error("Foodbook hat Status \"{$fb->status}\" — via MCP ist nur draft editierbar.", 'ACCESS_DENIED');
        }
        $svc = app(FoodbookService::class);

        try {
            $k = $svc->addKapitel($team, $fb->id, [
                'titel' => (string) $arguments['titel'],
                'preis_modus' => isset($arguments['preis_pro_person']) ? 'fix' : 'auto',
            ], isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null);
            $extras = array_intersect_key($arguments, array_flip(['konsumententitel', 'claim', 'description', 'preis_pro_person']));
            if ($extras !== []) {
                $k = $svc->updateKapitel($team, $k->id, $extras);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel' => [
            'id' => $k->id, 'titel' => $k->titel, 'parent_id' => $k->parent_id,
            'position' => $k->position, 'preis_modus' => $k->preis_modus,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'anlegen', 'draft'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbooks.POST', 'foodalchemist.foodbook_blocks.POST'],
            'examples' => ['Füge dem Foodbook 12 ein Kapitel "Desserts" hinzu'],
        ];
    }
}
