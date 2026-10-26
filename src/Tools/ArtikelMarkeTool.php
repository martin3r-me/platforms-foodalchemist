<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;

/** GP-Regelwerk v3.7.1 §5 Ausnahme 3 (read): welche Marke ein Lieferantenartikel für die GP-Klammer hat — dieselbe Stelle wie Abgleich und GP-Dialog. */
class ArtikelMarkeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.MARKE';
    }

    public function getDescription(): string
    {
        return 'Marke eines Lieferantenartikels für die GP-Klammer „/ (…)“ (Regelwerk §5 Ausnahme 3, Rezepturware). Reihenfolge: Necta-Marke → Marken-Kürzel in den ersten drei '
            . 'Wörtern der Bezeichnung (allein oder vor einem Punkt, z. B. „Kn.“ → Knorr) → Kurzname, wenn der Lieferant Hersteller ist → offen. Händlernamen nie. '
            . 'Liefert marke, quelle (necta_brand|kuerzel|kurzname|offen), roh und offen. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['item_id' => ['type' => 'integer', 'description' => 'Id des Lieferantenartikels']],
            'required' => ['item_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['item_id'] ?? 0));
        if ($la === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['item_id' => (int) $la->id, 'bezeichnung' => (string) $la->designation] + app(MarkeAusArtikel::class)->fuer($la)->toArray());
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'artikel', 'marke', 'hersteller', 'rezepturware'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.supplier_marken.GET', 'foodalchemist.la.GP_ABGLEICH', 'foodalchemist.gps.POST'],
            'examples' => ['Welche Marke kommt bei Artikel 4711 in die GP-Klammer?'],
        ];
    }
}
