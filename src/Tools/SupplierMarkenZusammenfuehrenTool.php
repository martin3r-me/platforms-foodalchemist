<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkenUebersichtService;

/**
 * Lieferanten → Marken (write): Herstellermarken, die bei mehreren Lieferanten gleich angelegt sind, zu EINER Zeile für alle zusammenführen. Eigenes Tool statt
 * Modus von NACHRENDERN: es ändert die Regel-Daten (supplier_marken), NACHRENDERN nur GP-Namen — getrennte Rechte-/Prüfspur und klare Vorschau.
 */
class SupplierMarkenZusammenfuehrenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_marken.ZUSAMMENFUEHREN';
    }

    public function getDescription(): string
    {
        return 'Ohne schluessel: listet Herstellermarken, die bei ≥ 2 Lieferanten gleich angelegt sind (gleiche Marke, Kürzel, Position). Mit schluessel: legt EINE '
            . 'Zeile für alle Lieferanten an (vorhandene globale wird übernommen, höchster Vorrang), entfernt die Lieferanten-Zeilen und benennt danach die '
            . 'Marke-offen-GPs streng neu. Eigenmarken und Sperren nie. dry_run = true (Standard) zeigt nur die Vorschau. Nur Plattform-Admin/Master-Admin.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'schluessel' => ['type' => 'string', 'description' => 'aus der Liste (marke|kürzel|position, klein)'],
            'dry_run' => ['type' => 'boolean', 'description' => 'nur Vorschau (Standard true)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $user = $context->user ?? null;
        if (! app(MarkenPflegeService::class)->darf($user)) {
            return ToolResult::error('Marken zusammenführen nur Plattform-Admin und Admin im Master-Team.', 'ACCESS_DENIED');
        }
        $dienst = app(MarkenUebersichtService::class);
        if (trim((string) ($arguments['schluessel'] ?? '')) === '') {
            return ToolResult::success(['dubletten' => $dienst->dubletten()]);
        }
        try {
            return ToolResult::success($dienst->zusammenfuehren($user, (string) $arguments['schluessel'], (bool) ($arguments['dry_run'] ?? true)));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'marke', 'kuerzel', 'lieferant', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes', 'updates'],
            'related_tools' => ['foodalchemist.supplier_marken.GET', 'foodalchemist.supplier_marken.NACHRENDERN'],
            'examples' => ['Welche Herstellermarken sind bei mehreren Lieferanten gleich angelegt?', 'Führe Knorr (kn) für alle zusammen (erst Vorschau).'],
        ];
    }
}
