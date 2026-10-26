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

/** Marken-Nachlauf (Plan GP-Stamm 3g, write): Kürzel zuordnen und die passenden marke_offen-GPs neu benennen — nur Plattform-Admin und Master-Admin. */
class MarkeOffenZuordnenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marke_offen.ZUORDNEN';
    }

    public function getDescription(): string
    {
        return 'Ordnet ein Marken-Kürzel zu (Eintrag in supplier_marken wie supplier_marken.PUT) und benennt die marke_offen-GPs mit diesem Kürzel neu: '
            . 'die Marke kommt in die Klammer „/ (Marke)“, aber NUR wenn alle Artikel des GP danach dieselbe Marke ergeben. Zwei Marken → fall „teilen“, '
            . 'ein Artikel ohne Marke → fall „fall_b“; beide bleiben offen. dry_run = true (Standard) zeigt nur die Vorschau. Nur Plattform-Admin/Master-Admin.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kuerzel' => ['type' => 'string', 'description' => 'wie in der Bezeichnung, ohne Punkt (B&B, SBS)'],
                'marke' => ['type' => 'string', 'description' => 'Schreibweise in der Klammer, z. B. Bake & Bite'],
                'supplier_id' => ['type' => 'integer', 'description' => 'Kürzel gilt nur für diesen Lieferanten; leer = für alle Lieferanten'],
                'typ' => ['type' => 'string', 'enum' => ['herstellermarke', 'eigenmarke']],
                'position' => ['type' => 'string', 'enum' => ['anfang', 'ende'], 'description' => 'anfang = erste drei Wörter (Standard), ende = letztes Wort'],
                'vorrang' => ['type' => 'integer', 'description' => '0–9'],
                'dry_run' => ['type' => 'boolean', 'description' => 'nur Vorschau (Standard true)'],
            ],
            'required' => ['kuerzel', 'marke'],
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
            return ToolResult::error('Marken-Kürzel zuordnen nur Plattform-Admin und Admin im Master-Team.', 'ACCESS_DENIED');
        }
        $id = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        if ($id !== null && FoodAlchemistSupplier::visibleToTeam($team)->find($id) === null) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $ergebnis = app(MarkeOffenService::class)->zuordnen($user, $id, (string) ($arguments['kuerzel'] ?? ''), (string) ($arguments['marke'] ?? ''),
                (string) ($arguments['typ'] ?? 'herstellermarke'), (string) ($arguments['position'] ?? 'anfang'), (int) ($arguments['vorrang'] ?? 0),
                (bool) ($arguments['dry_run'] ?? true));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['supplier_id' => $id] + $ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'marke', 'kuerzel', 'rezepturware', 'nachlauf', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.marke_offen.GET', 'foodalchemist.supplier_marken.PUT'],
            'examples' => ['Ordne bei Chefs Culinar B&B der Marke Bake & Bite zu (erst Vorschau).'],
        ];
    }
}
