<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PromotionService;

/**
 * Spec 33 · P8 (read): was bringt eine laufende Ausgabe — Umsatz je Foodbook, Karte und Plan
 * in ihrem jeweiligen Gültigkeitsfenster.
 *
 * Beide Vorbehalte des Dienstes reisen mit in die Antwort, weil sie ohne sie falsch gelesen
 * würde: der **exklusive Anteil** (ein Gericht in zwei laufenden Ausgaben zählt bei beiden, die
 * Zeilensumme übersteigt deshalb den Gesamtumsatz) und die **Zuordnungs-Abdeckung** des
 * Verkaufsjournals. Ein Konsument, der nur `umsatz` liest, addiert sonst Überlappendes.
 */
class PortfolioPromotionGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.portfolio_promotion.GET';
    }

    public function getDescription(): string
    {
        return 'Umsatz je laufender Ausgabe (Foodbook, Speisekarte, Speiseplan) im jeweiligen '
            . 'Gültigkeitsfenster, aus dem Verkaufsjournal. WICHTIG beim Lesen: `umsatz` darf NICHT '
            . 'über die Zeilen summiert werden — steht ein Gericht in zwei laufenden Ausgaben, zählt '
            . 'sein Umsatz bei beiden; dafür nennt jede Zeile `umsatz_exklusiv`. `abdeckung_pct` sagt, '
            . 'welcher Anteil des Gesamtumsatzes überhaupt an einem Gericht hängt — der Rest ist '
            . 'keiner Ausgabe zurechenbar. Ohne CSV-Import im Controlling-Zentrum gibt es kein '
            . 'Verkaufs-Ist und die Antwort ist leer. Optional `recipe_id`: in welchen Ausgaben '
            . 'steckt dieses Gericht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stichtag' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute'],
                'recipe_id' => ['type' => 'integer',
                    'description' => 'Rückrichtung: in welchen laufenden Ausgaben steckt dieses Gericht'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $promotion = app(PromotionService::class);
        $tag = ($arguments['stichtag'] ?? '') !== '' ? (string) $arguments['stichtag'] : null;
        $recipeId = ($arguments['recipe_id'] ?? null) !== null ? (int) $arguments['recipe_id'] : null;

        // Rückrichtung: kurze, eigene Antwort statt die volle Übersicht mit einem Filter drauf —
        // gefragt ist „wo steckt das drin", nicht „was bringt alles".
        if ($recipeId !== null) {
            $treffer = $promotion->ausgabenFuerGericht($team, $recipeId, $tag);

            return ToolResult::success([
                'stichtag' => $tag ?? now()->toDateString(),
                'recipe_id' => $recipeId,
                'n_ausgaben' => count($treffer),
                'ausgaben' => array_map(fn ($z) => [
                    'art' => $z['art'], 'id' => $z['id'], 'name' => $z['name'],
                    'outlet_name' => $z['outlet_name'], 'kunde' => $z['kunde'],
                    'von' => $z['von'], 'bis' => $z['bis'],
                ], $treffer),
            ]);
        }

        $p = $promotion->uebersicht($team, $tag);

        return ToolResult::success([
            'stichtag' => $p['stichtag'],
            'umsatz_gesamt' => $p['umsatz_gesamt'],
            'umsatz_zugeordnet' => $p['umsatz_zugeordnet'],
            'abdeckung_pct' => $p['abdeckung_pct'],
            'hinweis' => $p['hinweis'],
            // Ausdrücklich benannt, nicht nur implizit über zwei Spalten: ein Konsument, der die
            // Warnung überliest, rechnet sonst mit einer Summe, die es nicht gibt.
            'summierbar' => false,
            'summen_hinweis' => 'Die Zeilen überlappen sich, wenn ein Gericht in mehreren laufenden '
                . 'Ausgaben steht. Für eine Summe nur `umsatz_exklusiv` verwenden.',
            'ausgaben' => array_map(fn ($z) => [
                'art' => $z['art'], 'id' => $z['id'], 'name' => $z['name'],
                'outlet_name' => $z['outlet_name'], 'kunde' => $z['kunde'],
                'von' => $z['von'], 'bis' => $z['bis'],
                'n_gerichte' => $z['n_gerichte'], 'n_gerichte_exklusiv' => $z['n_gerichte_exklusiv'],
                'menge' => $z['menge'], 'umsatz' => $z['umsatz'],
                'umsatz_exklusiv' => $z['umsatz_exklusiv'], 'exklusiv_pct' => $z['exklusiv_pct'],
            ], $p['zeilen']),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'controlling', 'portfolio', 'promotion', 'umsatz', 'ausgaben'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.portfolio.GET', 'foodalchemist.sales_facts.GET',
                'foodalchemist.menu_engineering.GET'],
            'examples' => [
                'Welche laufende Karte bringt gerade am meisten Umsatz?',
                'In welchen Ausgaben steckt Gericht 412?',
            ],
        ];
    }
}
