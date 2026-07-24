<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanungsblattService;

/**
 * R7.1 — Produktionsblatt (read-only): Konzept + Personen ODER Gericht +
 * Portionen → zu produzierende Rezepte in Reihenfolge. Top-Gericht linear
 * skaliert, Basisrezepte in GANZEN Ansätzen (wie in FA angelegt) + GP-Bedarfs-
 * Zusammenfassung. Rezept-orientiert = Übergabe zum Nachbauen/Anlegen.
 */
class ProduktionsblattGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktionsblatt.GET';
    }

    public function getDescription(): string
    {
        return 'Produktionsblatt (read-only): skalierte Rezepturen für eine Produktionsmenge. GENAU EINES '
            . 'angeben: concept_id (+ persons) ODER recipe_id (+ portions oder persons). '
            . 'portions ist doppeldeutig: beim VK-Gericht = Portionen, beim Basisrezept = Anzahl Ansätze. '
            . 'Alternativ beim Basisrezept amount_kg (Ziel-Kilogramm → Service rechnet kg ÷ Basis-Yield). '
            . 'Basisrezepte werden in ganzen Ansätzen ausgegeben (nicht runter-fraktioniert), Top-Gericht linear skaliert.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-ID (mit persons)'],
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-/Rezept-ID (mit portions oder persons)'],
                'persons' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Personenzahl (Konzept-Skalierung)'],
                'portions' => ['type' => 'number', 'minimum' => 1, 'description' => 'VK-Gericht: Portionszahl. Basisrezept: Anzahl Ansätze.'],
                'amount_kg' => ['type' => 'number', 'minimum' => 0, 'description' => 'Nur Basisrezept: Ziel-Kilogramm (Alternative zu portions/Ansätze).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $keys = array_values(array_intersect(['concept_id', 'recipe_id'], array_keys(array_filter($arguments))));
        if (count($keys) !== 1) {
            return ToolResult::error('Genau EINES von concept_id oder recipe_id angeben.', 'VALIDATION_ERROR');
        }
        $ziel = array_intersect_key($arguments, array_flip(['concept_id', 'recipe_id', 'persons', 'portions', 'amount_kg']));

        try {
            $blatt = app(PlanungsblattService::class)->produktionsblatt($team, $ziel);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }

        return ToolResult::success($blatt);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'produktion', 'produktionsblatt', 'mengen', 'skalierung', 'planung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.bestellvorschlag.GET', 'foodalchemist.einkaufsliste.GET', 'foodalchemist.kalkulation.GET'],
            'examples' => ['Produktionsblatt für Konzept 42 bei 120 Personen', 'Rezepturen für 100 Portionen von Gericht 456'],
        ];
    }
}
