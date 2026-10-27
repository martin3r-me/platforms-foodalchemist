<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;

/** Lieferanten → Marken (write): Marke-offen-GPs für ein schon gepflegtes Kürzel neu benennen — nur Plattform-Admin und Master-Admin. */
class SupplierMarkenNachrendernTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_marken.NACHRENDERN';
    }

    public function getDescription(): string
    {
        return 'Benennt die marke_offen-GPs neu, deren Artikel ein schon gepflegtes Kürzel tragen (nach supplier_marken.PUT): die Marke kommt in die Klammer, '
            . 'aber NUR wenn alle Artikel des GP dieselbe Marke ergeben — sonst fall „teilen“ bzw. „fall_b“ (bleibt offen). Legt kein Kürzel an '
            . '(dafür marke_offen.ZUORDNEN). dry_run = true (Standard) zeigt nur die Vorschau. Nur Plattform-Admin/Master-Admin.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kuerzel' => ['type' => 'string', 'description' => 'wie gepflegt, ohne Punkt'],
                'supplier_id' => ['type' => 'integer', 'description' => 'Lieferant des Kürzels; leer = globales Kürzel'],
                'position' => ['type' => 'string', 'enum' => ['anfang', 'ende']],
                'dry_run' => ['type' => 'boolean', 'description' => 'nur Vorschau (Standard true)'],
            ],
            'required' => ['kuerzel'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $user = $context->user ?? null;
        if (! app(MarkenPflegeService::class)->darf($user)) {
            return ToolResult::error('Marke-offen-GPs neu benennen nur Plattform-Admin und Admin im Master-Team.', 'ACCESS_DENIED');
        }
        $id = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        if ($id !== null && FoodAlchemistSupplier::visibleToTeam($team)->find($id) === null) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $r = app(MarkeOffenService::class)->nachrendern($user, $id, (string) ($arguments['kuerzel'] ?? ''), (string) ($arguments['position'] ?? 'anfang'),
                (bool) ($arguments['dry_run'] ?? true));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['supplier_id' => $id] + $r);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'marke', 'kuerzel', 'nachlauf', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.supplier_marken.GET', 'foodalchemist.supplier_marken.PUT', 'foodalchemist.marke_offen.ZUORDNEN'],
            'examples' => ['Benenne nach der Pflege von B&B bei Chefs Culinar die passenden offenen GPs neu (erst Vorschau).'],
        ];
    }
}
