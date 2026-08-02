<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Spec 32 · C3 (read): Verkaufsjournal — Umsatz und Absatz je Gericht in einem Zeitraum,
 * plus die noch nicht zugeordneten Zeilen.
 *
 * Die offenen Zuordnungen kommen bewusst MIT: eine Umsatz-Auswertung, die verschweigt, dass
 * ein Teil der Zeilen an keinem Gericht hängt, liest sich vollständiger als sie ist.
 */
class SalesFactsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const MAX = 200;

    public function getName(): string
    {
        return 'foodalchemist.sales_facts.GET';
    }

    public function getDescription(): string
    {
        return 'Verkaufsjournal (Ist): Absatz und Umsatz je Gericht in einem Zeitraum. Optional '
            . 'von/bis (YYYY-MM-DD). Liefert zusätzlich `offen` — Verkaufszeilen, die keinem Gericht '
            . 'zugeordnet werden konnten (mit Roh-Bezeichnung und Umsatz), damit die Summen einordenbar '
            . 'bleiben. Gefüllt wird das Journal über den CSV-Import im Controlling-Zentrum; ohne Import '
            . 'ist die Antwort leer und es gibt kein Verkaufs-Ist.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'von' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $von = ($arguments['von'] ?? '') !== '' ? (string) $arguments['von'] : null;
        $bis = ($arguments['bis'] ?? '') !== '' ? (string) $arguments['bis'] : null;

        // Strikt team-eigen — wie das Einkaufsjournal: ein Umsatz gehört dem Betrieb,
        // der ihn gemacht hat, und nicht der Team-Kette.
        // Spalten qualifiziert — die Gerichte-Auswertung joint die Rezepte dazu, und die tragen
        // team_id/deleted_at ebenfalls (sonst: „ambiguous column").
        $t = 'foodalchemist_sales_facts';
        $basis = fn () => DB::table($t)
            ->where($t . '.team_id', $team->id)->whereNull($t . '.deleted_at')
            ->when($von !== null, fn ($q) => $q->whereDate($t . '.sold_at', '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate($t . '.sold_at', '<=', $bis));

        $jeGericht = $basis()->whereNotNull($t . '.recipe_id')
            ->join('foodalchemist_recipes as r', 'r.id', '=', $t . '.recipe_id')
            ->selectRaw($t . '.recipe_id, r.name, SUM(' . $t . '.qty_sold) AS menge, SUM(' . $t . '.revenue_net) AS umsatz')
            ->groupBy($t . '.recipe_id', 'r.name')
            ->orderByDesc('umsatz')->limit(self::MAX)->get();

        $offen = $basis()->whereNull($t . '.recipe_id')
            ->selectRaw($t . '.raw_label, COUNT(*) AS n, SUM(' . $t . '.revenue_net) AS umsatz')
            ->groupBy($t . '.raw_label')->orderByDesc('umsatz')->limit(50)->get();

        return ToolResult::success([
            'von' => $von,
            'bis' => $bis,
            'umsatz_gesamt' => round((float) $basis()->sum($t . '.revenue_net'), 2),
            'umsatz_zugeordnet' => round((float) $basis()->whereNotNull($t . '.recipe_id')->sum($t . '.revenue_net'), 2),
            'gerichte' => $jeGericht->map(fn ($r) => [
                'recipe_id' => (int) $r->recipe_id,
                'name' => (string) $r->name,
                'menge' => $r->menge !== null ? (float) $r->menge : null,
                'umsatz' => round((float) $r->umsatz, 2),
            ])->all(),
            'offen' => $offen->map(fn ($r) => [
                'raw_label' => (string) $r->raw_label,
                'zeilen' => (int) $r->n,
                'umsatz' => round((float) $r->umsatz, 2),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'controlling', 'verkauf', 'umsatz', 'absatz', 'journal'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.menu_engineering.GET', 'foodalchemist.einkauf_spend.GET'],
            'examples' => ['Wie viel Umsatz haben wir im Juli je Gericht gemacht?'],
        ];
    }
}
