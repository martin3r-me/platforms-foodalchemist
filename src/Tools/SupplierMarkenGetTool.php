<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;

/** GP-Regelwerk v3.7.1 §5 Ausnahme 3 (read): Rolle, Kurzname und Marken-Kürzel eines Lieferanten bzw. die globalen Kürzel. */
class SupplierMarkenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_marken.GET';
    }

    public function getDescription(): string
    {
        return 'Marken-Regel-Daten (Regelwerk §5 Ausnahme 3): mit supplier_id Rolle (hersteller|haendler|offen), Kurzname für die GP-Klammer und die Kürzel dieses Lieferanten; '
            . 'ohne supplier_id die globalen Kürzel (gelten für alle Lieferanten, z. B. kn → Knorr). Read-only.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['supplier_id' => ['type' => 'integer', 'description' => 'Lieferant; leer = globale Kürzel']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        $kuerzel = fn (?int $sid) => FoodAlchemistSupplierMarke::query()
            ->when($sid === null, fn ($q) => $q->whereNull('supplier_id'), fn ($q) => $q->where('supplier_id', $sid))
            ->orderBy('kuerzel')->get(['kuerzel', 'marke', 'typ'])->toArray();
        if ($id === null) {
            return ToolResult::success(['global' => true, 'kuerzel' => $kuerzel(null)]);
        }
        $s = FoodAlchemistSupplier::visibleToTeam($team)->find($id);
        if ($s === null) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['supplier_id' => (int) $s->id, 'name' => $s->name, 'rolle' => $s->rolle?->value ?? 'offen', 'kurzname' => $s->kurzname, 'kuerzel' => $kuerzel((int) $s->id)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lieferant', 'marke', 'kuerzel', 'hersteller'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.supplier_marken.PUT', 'foodalchemist.artikel.MARKE'],
            'examples' => ['Ist Bäckerei Heisters als Hersteller eingestuft?', 'Welche globalen Marken-Kürzel gibt es?'],
        ];
    }
}
