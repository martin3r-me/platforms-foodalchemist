<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** Phase C: Gerichte-Concepter durchsuchen (Katalog, ohne angebots-lokale Entwürfe). */
class ConceptsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Gerichte-Konzepte (Concepter-Katalog) nach Name/Anlass. Liefert id, name, '
            . 'status, klasse, anlass, slot-Anzahl — Details (Slots, Gerichte, Preise) via foodalchemist.concepts.GET. '
            . 'Konzepte sind die Bausteine für Foodbook-Blöcke (concept_ref) und Speiseplan-Einträge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Anlass), leer = alle'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'aktiv', 'archiviert']],
                'vorlagen' => ['type' => 'boolean', 'default' => false, 'description' => 'true = nur Vorlagen'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $treffer = app(ConceptService::class)->paginateBrowser([
            'search' => (string) ($arguments['q'] ?? ''),
            'status' => (string) ($arguments['status'] ?? ''),
            'vorlagen' => (bool) ($arguments['vorlagen'] ?? false),
        ], $team, min(50, max(1, (int) ($arguments['limit'] ?? 15))));

        return ToolResult::success([
            'total' => $treffer->total(),
            'concepts' => collect($treffer->items())->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'klasse' => $c->klasse,
                'anlass' => $c->anlass, 'niveau' => $c->niveau, 'slots' => $c->slots_count,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'concept', 'concepter', 'konzept', 'paket', 'search'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.GET', 'foodalchemist.foodbook_blocks.POST'],
            'examples' => ['Suche Konzepte für Flying Buffet', 'Welche aktiven Konzepte gibt es?'],
        ];
    }
}
