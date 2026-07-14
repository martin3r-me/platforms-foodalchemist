<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/**
 * #496: Vollständige, seiten-basierte Auflistung der Catering-Wissensbasis —
 * ergänzt SEARCH (das braucht einen Suchbegriff und cappt bei 50). Damit ist
 * der gesamte Bestand (~1.000 Dokumente: trend, pairing, cross_cutting, domain,
 * niveau, regelwerk) für MCP-Clients abrufbar (offset-Paging). Liefert je
 * Dokument slug/title/category + Frontmatter (thema, sub_thema, relevanz,
 * recherche_datum, tags). Volltext weiter via foodalchemist.knowledge.GET.
 */
class KnowledgeListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Catering-Wissensbasis vollständig und seitenweise auf (ohne Suchbegriff, '
            . 'ohne 50er-Cap). Optional pro Kategorie (trend/pairing/cross_cutting/domain/niveau/regelwerk) '
            . 'gefiltert; offset/limit-Paging (next_offset zum Weiterblättern). Liefert slug/title/category '
            . '+ Frontmatter (thema, sub_thema, relevanz, recherche_datum, tags). Volltext via '
            . 'foodalchemist.knowledge.GET, gezielte Stichwortsuche via foodalchemist.knowledge.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => ['trend', 'pairing', 'cross_cutting', 'domain', 'niveau', 'regelwerk', 'kueche', 'skill'],
                    'description' => 'Optionaler Kategorie-Filter. Ohne Angabe: alle Kategorien.',
                ],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Start-Offset fürs Paging (next_offset aus der Vorantwort).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
                'with_frontmatter' => ['type' => 'boolean', 'default' => true, 'description' => 'Frontmatter je Dokument mitliefern (thema/sub_thema/relevanz/recherche_datum/tags). false = schlanke, schnellere Enumeration.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $ergebnis = app(KnowledgeContextService::class)->listDocuments(
            isset($arguments['category']) ? (string) $arguments['category'] : null,
            (int) ($arguments['offset'] ?? 0),
            (int) ($arguments['limit'] ?? 100),
            (bool) ($arguments['with_frontmatter'] ?? true),
        );

        return ToolResult::success($ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'list', 'katalog', 'inventar', 'paging'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.GET'],
            'examples' => ['Liste alle Trend-Wissensdokumente auf', 'Zeig mir den kompletten Pairing-Wissensbestand seitenweise'],
        ];
    }
}
