<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SurplusToDishService;

/**
 * R6.10 (Pairing-Offense): Überschuss-zu-Gericht — Lager meldet Überschuss (Mock/
 * Contract-Bestand `[{gp_id, menge}]`), FA schlägt über den Pairing-Anker-Graph
 * Gerichte vor, die den GP geschmacklich *tragen* (Anker-Relevanz, nicht bloß
 * „enthalten") + welche Überschuss-Menge sie verwerten + Kohäsions-Begründung.
 * READ-ONLY: Draft-Konzept anlegen ist ein expliziter Folgeschritt (concepts.POST).
 * Grenze: FA rechnet/schlägt vor; Bestandsführung + Bestellung = Nachbar-Modul.
 */
class SurplusSuggestTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.surplus.SUGGEST';
    }

    public function getDescription(): string
    {
        return 'Schlägt zu einem Überschuss-Bestand (Liste aus gp_id + Menge) Gerichte aus dem '
            . 'eigenen VK-Portfolio vor, die diese Grundprodukte geschmacklich TRAGEN (Aroma-Anker-'
            . 'Relevanz, nicht bloß „enthält"), inkl. verwertbarer Menge + Kohäsions-Begründung + '
            . 'Liste nicht verwertbarer Überschüsse. Read-only — Draft-Konzept via foodalchemist.concepts.POST. '
            . 'Bestand ist Mock/Contract-Input; FA führt keine eigene Lagerhaltung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'surplus' => [
                    'type' => 'array',
                    'description' => 'Überschuss-Bestand',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'gp_id' => ['type' => 'integer'],
                            'menge' => ['type' => 'number'],
                            'einheit' => ['type' => 'string'],
                        ],
                        'required' => ['gp_id'],
                    ],
                ],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 8],
            ],
            'required' => ['surplus'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $surplus = $arguments['surplus'] ?? [];
        if (! is_array($surplus) || $surplus === []) {
            return ToolResult::error('surplus (Liste aus gp_id + menge) ist erforderlich.', 'VALIDATION_ERROR');
        }
        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 8)));

        return ToolResult::success(app(SurplusToDishService::class)->suggest($team, array_values($surplus), $limit));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'überschuss', 'verwertung', 'aroma', 'gericht', 'contract'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.pairings.SUGGEST', 'foodalchemist.dish.REVERSE', 'foodalchemist.concepts.POST'],
            'examples' => [
                'Wir haben 12 kg Rote Bete über — welche Gerichte verwerten das?',
                'Überschuss GP 812 (8 kg) + GP 344 (3 kg): was schlägt der Graph vor?',
            ],
        ];
    }
}
