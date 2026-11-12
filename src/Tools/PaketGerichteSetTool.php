<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Support\TeamScope;

/** Gericht-Positionen eines Concepter-Pakets (Entwurf) als Vollersatz setzen. */
class PaketGerichteSetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.SET';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Setzt die Gericht-Positionen eines team-eigenen Pakets im Entwurf (Vollersatz der Liste, Reihenfolge = items). '
            . 'items: [{sales_recipe_id, quantity?, unit_vocab_id?, presentation_id?}]. Bestehende Positionen behalten Menge/Einheit/Darreichung, '
            . 'solange das Item das Feld nicht mitbringt. Überschrift-/Text-Positionen bleiben erhalten. presentation_id = Darreichung (0 = Standard).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Ziel-Liste der Gericht-Positionen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sales_recipe_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'number'],
                            'unit_vocab_id' => ['type' => 'integer'],
                            'presentation_id' => ['type' => 'integer', 'description' => 'Darreichung des Gerichts (0 = Standard, weglassen = unverändert).'],
                        ],
                        'required' => ['sales_recipe_id'],
                    ],
                ],
            ],
            'required' => ['paket_id', 'items'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_array($arguments['items'] ?? null)) {
            return ToolResult::error('items muss ein Array sein.', 'VALIDATION_ERROR');
        }
        $paket = $this->paketConcept($team, (int) ($arguments['paket_id'] ?? 0));
        if ($paket instanceof ToolResult) {
            return $paket;
        }
        $items = [];
        $recipeIds = [];
        foreach ($arguments['items'] as $row) {
            $rid = (int) ($row['sales_recipe_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            $recipeIds[] = $rid;
            $item = ['sales_recipe_id' => $rid];
            if (array_key_exists('quantity', $row)) {
                $item['quantity'] = $row['quantity'] !== null ? (float) $row['quantity'] : null;
            }
            if (array_key_exists('unit_vocab_id', $row)) {
                $item['unit_vocab_id'] = $row['unit_vocab_id'] !== null ? (int) $row['unit_vocab_id'] : null;
            }
            if (array_key_exists('presentation_id', $row)) {
                $item['presentation_id'] = (int) ($row['presentation_id'] ?? 0) > 0 ? (int) $row['presentation_id'] : null;
            }
            $items[] = $item;
        }

        try {
            if ($recipeIds !== []) {
                TeamScope::referenzen(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class, $recipeIds, $team, 'sales_recipe_id');
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');   // fremdes/unsichtbares Gericht
        }

        try {
            $svc = app(ConceptPaketService::class);
            $paket = $svc->syncGerichte($team, $paket->id, $items);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $svc->payload($paket, true)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'gericht', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes', 'updates'],
            'related_tools' => ['foodalchemist.paket_gerichte.MENGE', 'foodalchemist.paket_gerichte.REORDER', 'foodalchemist.concept_slots.POST'],
            'examples' => ['Setze für Paket 12 die Gerichte 4, 7 und 9.'],
        ];
    }
}
