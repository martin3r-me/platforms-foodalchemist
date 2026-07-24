<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 (write): fügt ein Ziel (Konzept+Personen ODER Gericht+Portionen) zum
 * Produktionsauftrag EINES Tages hinzu — legt den Auftrag bei Bedarf an
 * (production_date). Löst danach die VOLLE Neu-Explosion über alle Ziele des
 * Tages aus (nicht additiv, Rundungs-Korrektheit). source_ref = Quell-Kennung;
 * erneutes Hinzufügen derselben Quelle ersetzt ihren Beitrag (idempotent).
 *
 * Spec 20 P1b: chapter_id (Foodbook-Kapitel) wird beim Hinzufügen in AUFGELÖSTE
 * Einzel-Ziele (concept/recipe) expandiert und eingefroren gespeichert (V2 „kein
 * Live-Bezug") — je Teil-Ziel eine source_ref-Ableitung „<source_ref>:c<index>".
 */
class ProductionOrdersAddTargetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.ADD_TARGET';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Ziel (concept_id+persons ODER recipe_id+portions ODER chapter_id+persons) zu einem '
            . 'Produktionsauftrag hinzu. portions ist doppeldeutig: VK-Gericht = Portionen, Basisrezept = Anzahl Ansätze; '
            . 'beim Basisrezept alternativ amount_kg (Ziel-Kilogramm → kg ÷ Basis-Yield, auf ganze Ansätze aufgerundet). '
            . 'chapter_id = Foodbook-Kapitel: wird in aufgelöste Einzel-Ziele expandiert und eingefroren gespeichert '
            . '(kein Live-Bezug); variant_choices {variant_group_id: block_id} wählt in Wahl-Gruppen (sonst erster Block). '
            . 'Adressierung: entweder order_id (bestehender, geplanter Auftrag) ODER production_date '
            . '(+ optional name; legt bei Bedarf an — mehrere Aufträge pro Tag sind erlaubt, name grenzt ab). '
            . 'Rechnet die Ansätze für ALLE Ziele dieses Auftrags gemeinsam neu (nicht additiv gerundet). '
            . 'source_ref = Quell-Kennung; gleiche Quelle erneut ⇒ ersetzt ihren Beitrag (idempotent; chapter_id: je Teil-Ziel „:c<index>").';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestehender geplanter Auftrag; Alternative zu production_date'],
                'production_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (legt bei Bedarf an, wenn kein order_id)'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Auftrags-Name beim Anlegen/Abgrenzen über production_date'],
                'concept_id' => ['type' => 'integer'],
                'recipe_id' => ['type' => 'integer'],
                'chapter_id' => ['type' => 'integer', 'description' => 'Foodbook-Kapitel-ID (mit persons) — wird in aufgelöste Einzel-Ziele expandiert'],
                'persons' => ['type' => 'number'],
                'portions' => ['type' => 'number', 'description' => 'VK-Gericht: Portionen. Basisrezept: Anzahl Ansätze.'],
                'amount_kg' => ['type' => 'number', 'description' => 'Nur Basisrezept: Ziel-Kilogramm (Alternative zu portions/Ansätze).'],
                'variant_choices' => ['type' => 'object', 'description' => 'Nur chapter_id: {variant_group_id: block_id} je Wahl-Gruppe (Default: erster Block).'],
                'source_ref' => ['type' => 'string', 'description' => 'Quell-Kennung; Re-Import ersetzt diesen Schlüssel'],
            ],
            'required' => ['source_ref'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $hatConcept = ! empty($arguments['concept_id']);
        $hatRecipe = ! empty($arguments['recipe_id']);
        $hatChapter = ! empty($arguments['chapter_id']);
        if (((int) $hatConcept + (int) $hatRecipe + (int) $hatChapter) !== 1) {
            return ToolResult::error('Genau eines von concept_id/recipe_id/chapter_id angeben.', 'VALIDATION_ERROR');
        }
        if (empty($arguments['order_id']) && empty($arguments['production_date'])) {
            return ToolResult::error('order_id ODER production_date erforderlich.', 'VALIDATION_ERROR');
        }
        $sourceRef = (string) $arguments['source_ref'];

        // chapter_id: in aufgelöste Einzel-Ziele expandieren (V2 kein Live-Bezug), je Teil-Ziel eine source_ref.
        $ziele = [];
        if ($hatChapter) {
            $personen = max(1, (int) ($arguments['persons'] ?? 0));
            $variantChoices = isset($arguments['variant_choices']) && is_array($arguments['variant_choices'])
                ? $arguments['variant_choices'] : [];
            $res = app(PlanungsblattService::class)->kapitelZiele($team, (int) $arguments['chapter_id'], $personen, $variantChoices);
            if ($res['ziele'] === []) {
                return ToolResult::error('Kapitel liefert keine auflösbaren Ziele (nur sichtbare Gericht-/Konzept-Blocks).', 'VALIDATION_ERROR');
            }
            foreach ($res['ziele'] as $i => $z) {
                $ziele[] = [$z, $sourceRef . ':c' . $i];
            }
        } else {
            $ziel = [];
            if ($hatConcept) {
                $ziel['concept_id'] = (int) $arguments['concept_id'];
                $ziel['persons'] = (float) ($arguments['persons'] ?? 0);
            } else {
                $ziel['recipe_id'] = (int) $arguments['recipe_id'];
                if (isset($arguments['amount_kg']) && (float) $arguments['amount_kg'] > 0) {
                    $ziel['amount_kg'] = (float) $arguments['amount_kg']; // Basisrezept nach kg (P1)
                } else {
                    $ziel['portions'] = (float) ($arguments['portions'] ?? $arguments['persons'] ?? 0);
                }
            }
            $ziele[] = [$ziel, $sourceRef];
        }

        try {
            $svc = app(ProductionOrderService::class);
            $order = $svc->resolveOrCreate(
                $team,
                ! empty($arguments['order_id']) ? (int) $arguments['order_id'] : null,
                ! empty($arguments['production_date']) ? (string) $arguments['production_date'] : null,
                $arguments['name'] ?? null,
                $context->user->id,
            );
            foreach ($ziele as [$z, $ref]) {
                $order = $svc->addTarget($team, (int) $order->id, $z, $ref);
            }
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'ERROR');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'name' => $order->name,
            'production_date' => $order->production_date?->toDateString(),
            'aufgeloeste_ziele' => count($ziele),
            'targets' => $order->targets,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'ziel'],
            'read_only' => false,
            'idempotent' => true, // gleiche (source_ref, ziel, production_date) ⇒ gleicher Endzustand
            'risk_level' => 'low',
        ];
    }
}
