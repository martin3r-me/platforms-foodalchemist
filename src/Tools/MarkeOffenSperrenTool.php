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

/** Marken-Nachlauf (write): „Kein Kürzel“ — ein Wort der Nachlauf-Liste dauerhaft sperren (nie eine Marke). Nur Plattform-Admin und Master-Admin. */
class MarkeOffenSperrenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marke_offen.SPERREN';
    }

    public function getDescription(): string
    {
        return 'Sperrt ein Wort aus der Nachlauf-Liste „Marke offen“ als „Kein Kürzel“ (Eintrag typ sperre in supplier_marken, wie MSC): es verschwindet aus der Liste '
            . 'und wird nie als Marke genutzt, auch nicht über ein globales Kürzel gleichen Namens. Für Abkürzungen von Lebensmitteln (Häh., Dess.) und Füllwörter. '
            . 'dry_run = true (Standard) zählt nur die betroffenen GPs/Artikel. Rückgängig über supplier_marken.PUT kuerzel_entfernen. Nur Plattform-Admin/Master-Admin.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kuerzel' => ['type' => 'string', 'description' => 'wie in der Bezeichnung, ohne Punkt'],
                'supplier_id' => ['type' => 'integer', 'description' => 'nur für diesen Lieferanten; leer = für alle Lieferanten'],
                'position' => ['type' => 'string', 'enum' => ['anfang', 'ende'], 'description' => 'anfang = erste drei Wörter (Standard), ende = letztes Wort'],
                'dry_run' => ['type' => 'boolean', 'description' => 'nur zählen (Standard true)'],
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
            return ToolResult::error('Marken-Kürzel sperren nur Plattform-Admin und Admin im Master-Team.', 'ACCESS_DENIED');
        }
        $id = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        if ($id !== null && FoodAlchemistSupplier::visibleToTeam($team)->find($id) === null) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $r = app(MarkeOffenService::class)->sperren($user, $id, (string) ($arguments['kuerzel'] ?? ''), (string) ($arguments['position'] ?? 'anfang'),
                (bool) ($arguments['dry_run'] ?? true));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($r);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'marke', 'kuerzel', 'sperre', 'nachlauf', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.marke_offen.GET', 'foodalchemist.marke_offen.ZUORDNEN', 'foodalchemist.supplier_marken.PUT'],
            'examples' => ['Sperre bei Chefs Culinar „Häh.“ als Kein Kürzel (erst Vorschau).'],
        ];
    }
}
