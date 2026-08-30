<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Composer-MCP: Anker suchen/browsen → IDs. Spiegelt {@see PairingService::composerAnkerBrowse}
 * (der Anker-Picker im Composer-Tab). Der Einstieg in den headless Composer: von einem Namen/
 * Suchbegriff zu Anker-IDs (für composer.KOHAESION) bzw. Slugs (für recipes.GENERATE via seed_anker).
 * Read-only. Anker sind globales Vokabular (nicht team-gefiltert).
 */
class ComposerAnkerSucheTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.composer.ANKER_SUCHE';
    }

    public function getDescription(): string
    {
        return 'Composer: durchsucht das Aroma-Anker-Vokabular (Freitext auf Slug/Anzeigename + optional Kategorie) '
            . 'und liefert Anker mit id + slug + Best/Good-Badge (typ: stern3/stern2) relativ zur bereits gewählten '
            . 'Menge (gewaehlte_ids) oder einem Fokus-Anker (fokus_id). Der Einstieg in den headless Composer: von '
            . 'einem Namen zu Anker-IDs für composer.KOHAESION, bzw. zu Slugs für recipes.GENERATE (seed_anker). '
            . 'Liefert zusätzlich alle verfügbaren Kategorien für den Filter. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Freitext-Suche (LIKE auf slug ODER Anzeigename), z. B. "rauch" oder "vanille". Leer = alle (bis limit).'],
                'kategorie' => ['type' => 'string', 'description' => 'Optionaler Kategorie-Filter (Anker-Kategorie). Verfügbare Kategorien stehen im Ergebnis unter kategorien.'],
                'gewaehlte_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Bereits gewählte Anker-IDs — werden aus der Liste ausgeschlossen und sind Basis für die Badges (was passt zur Auswahl).'],
                'fokus_id' => ['type' => 'integer', 'description' => 'Optional: EIN Anker, relativ zu dem die Badges berechnet werden ("was passt zu X") statt zur ganzen Auswahl.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 200],
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
        $q = trim((string) ($arguments['q'] ?? ''));
        $kategorie = trim((string) ($arguments['kategorie'] ?? ''));
        $gewaehlt = array_values(array_unique(array_map('intval', (array) ($arguments['gewaehlte_ids'] ?? []))));
        $fokus = isset($arguments['fokus_id']) && $arguments['fokus_id'] !== '' ? (int) $arguments['fokus_id'] : null;
        $limit = min(500, max(1, (int) ($arguments['limit'] ?? 200)));

        $res = app(PairingService::class)->composerAnkerBrowse(
            $team,
            $q,
            $kategorie !== '' ? $kategorie : null,
            $gewaehlt,
            $limit,
            $fokus,
        );

        return ToolResult::success($res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'composer', 'pairing', 'anker', 'aroma', 'suche'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.composer.KOHAESION', 'foodalchemist.pairings.GET', 'foodalchemist.recipes.GENERATE'],
            'examples' => ['Suche Aroma-Anker "rauch" für den Composer', 'Welche Anker passen zu meiner Auswahl?'],
        ];
    }
}
