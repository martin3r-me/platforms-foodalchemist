<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * Phase C: Slot in einem draft-Konzept anlegen und optional direkt befüllen
 * (Gericht via sales_recipe_id ODER Paket via package_id) + Wording setzen.
 */
class ConceptSlotsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slots.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Slot in einem draft-Konzept an (Position ans Ende) und befüllt ihn optional: '
            . 'sales_recipe_id = Gericht (via verkaufsrezepte.SEARCH), package_id = Concepter-Paket (concepts mit kind=paket, '
            . 'IDs via pakete.LIST / anlegen via pakete.POST oder concepts.POST kind=paket) (XOR). role z. B. '
            . 'vorspeise/hauptgang/dessert, wording = kundenseitiger Anzeigename. '
            . 'dish_brief = Gericht-Vorgabe für eine LEERE Position: welches Gericht hier entstehen soll (z. B. „Graved Karotte | Dillcreme | Brot-Crumble"). '
            . 'Die Erstellung baut dann genau dieses Gericht statt frei zu erfinden; dish_brief_note = Notiz dazu (Zubereitung, Produkte). Nicht zusammen mit sales_recipe_id/package_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer'],
                'role' => ['type' => 'string', 'description' => 'z. B. vorspeise, hauptgang, dessert, snack'],
                'title' => ['type' => 'string'],
                'is_pflicht' => ['type' => 'boolean', 'default' => true],
                'sales_recipe_id' => ['type' => 'integer'],
                'package_id' => ['type' => 'integer', 'description' => 'Concepter-Paket-Id (concept mit kind=paket) — wird als Station eingehängt.'],
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'description' => 'Einheiten-Slug, z. B. stk, portion'],
                'wording' => ['type' => 'string', 'description' => 'Kundenseitiger Anzeigename der Position'],
                'dish_brief' => ['type' => 'string', 'description' => 'Gericht-Vorgabe für eine leere Position: der gewünschte Gerichtstitel. Die Erstellung baut genau dieses Gericht (Bestand zuerst: ein freigegebenes Gericht gleichen Namens wird vorgeschlagen). Nicht mit sales_recipe_id/package_id.'],
                'dish_brief_note' => ['type' => 'string', 'description' => 'Notiz zur Gericht-Vorgabe (Zubereitung, Produkte, was nicht hinein soll). Nur mit dish_brief.'],
                'presentation' => ['type' => 'string', 'description' => 'Servierform-Code/Label — pinnt die Darreichung dieses Gerichts am Slot (überschreibt die Konzept-Servierform-Auflösung). Nur mit sales_recipe_id.'],
            ],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereKey((int) $arguments['concept_id'])->first();
        if ($concept === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (($draft = $this->guardDraft($concept)) !== null) {
            return $draft;
        }
        if (isset($arguments['sales_recipe_id'], $arguments['package_id'])) {
            return ToolResult::error('sales_recipe_id und package_id sind XOR — nur eines angeben.', 'VALIDATION_ERROR');
        }
        $vorgabe = trim((string) ($arguments['dish_brief'] ?? ''));
        if ($vorgabe !== '' && (isset($arguments['sales_recipe_id']) || isset($arguments['package_id']))) {
            return ToolResult::error('dish_brief ist die Vorgabe für eine LEERE Position — nicht zusammen mit sales_recipe_id oder package_id.', 'VALIDATION_ERROR');
        }
        if (isset($arguments['sales_recipe_id'])
            && ! FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['sales_recipe_id'])->exists()) {
            return ToolResult::error('sales_recipe_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $paketId = null;
        if (isset($arguments['package_id'])) {
            // package_id = Concepter-Paket (kind=paket-Concept) → embedded_concept_id (Kaskade-Pfad).
            $paketId = $this->paketReferenz($team, (int) $arguments['package_id'], (int) $concept->id);
            if ($paketId instanceof ToolResult) {
                return $paketId;
            }
        }
        $svc = app(ConceptService::class);

        try {
            $slot = $svc->addSlot($team, $concept->id, [
                'role' => $arguments['role'] ?? null,
                'title' => $arguments['title'] ?? null,
                'is_pflicht' => (bool) ($arguments['is_pflicht'] ?? true),
            ]);
            if (isset($arguments['sales_recipe_id']) || isset($arguments['package_id'])) {
                $fill = array_intersect_key($arguments, array_flip(['sales_recipe_id', 'quantity']));
                if ($paketId !== null) {
                    $fill['embedded_concept_id'] = $paketId;
                }
                if (($arguments['unit'] ?? '') !== '') {
                    $unit = app(VocabularyService::class)->findEinheit($team, (string) $arguments['unit']);
                    if ($unit === null) {
                        return ToolResult::error('Unbekannte Einheit "' . $arguments['unit'] . '".', 'VALIDATION_ERROR');
                    }
                    $fill['unit_vocab_id'] = $unit->id;
                }
                $slot = $svc->fillSlot($team, $slot->id, $fill);
            }
            if (($arguments['wording'] ?? '') !== '') {
                $slot = $svc->setSlotWording($team, $slot->id, (string) $arguments['wording']);
            }
            if ($vorgabe !== '') {
                $slot = app(\Platform\FoodAlchemist\Services\ConceptVorgabenService::class)->setze($team, $slot->id, $vorgabe, isset($arguments['dish_brief_note']) ? (string) $arguments['dish_brief_note'] : null);
            }
            // M3: Darreichung am Slot pinnen (Servierform des Gerichts).
            if (($arguments['presentation'] ?? '') !== '' && $slot->sales_recipe_id !== null) {
                $formId = $this->resolveServierformId($team, (string) $arguments['presentation']);
                $darreichung = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $slot->sales_recipe_id)
                    ->where('serving_form_id', $formId)->first();
                if ($darreichung === null) {
                    $formen = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $slot->sales_recipe_id)
                        ->with('servingForm')->get()->map(fn ($d) => $d->servingForm?->code)->filter()->implode(', ');
                    return ToolResult::error("Gericht hat keine Darreichung „{$arguments['presentation']}\". Vorhandene Formen: " . ($formen ?: '—'), 'VALIDATION_ERROR');
                }
                $slot = $svc->setSlotDarreichung($team, $slot->id, $darreichung->id);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot' => [
            'id' => $slot->id, 'position' => $slot->position, 'type' => $slot->type,
            'role' => $slot->role, 'sales_recipe_id' => $slot->sales_recipe_id, 'package_id' => $slot->embedded_concept_id,
            'presentation_id' => $slot->presentation_id, 'dish_brief' => $slot->dish_brief, 'dish_brief_note' => $slot->dish_brief_note,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'gericht', 'paket', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.POST', 'foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Füge Konzept 42 das Verkaufsrezept 1373 als Hauptgang hinzu'],
        ];
    }
}
