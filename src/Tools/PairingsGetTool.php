<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PairingService;

/** Phase K: Pairing-Partner einer Zutat aus dem Anker-Graph (767 Anker, 24k Kanten). */
class PairingsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pairings.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert Flavor-Pairing-Partner für eine Zutat (Name oder Anker-Slug) aus dem '
            . 'kuratierten Anker-Graph. typ filtert auf klassisch|modern. Bei Geschmacks-Kombinationen '
            . '(Rezept, Komposition, Menü) IMMER zuerst hier nachschlagen statt zu raten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zutat' => ['type' => 'string', 'description' => 'Zutat-Name oder Anker-Slug, z. B. "Kürbis" oder "kuerbis"'],
                'type' => ['type' => 'string', 'enum' => ['klassisch', 'modern'], 'description' => 'Optionaler Kanten-Filter'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'required' => ['zutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $ergebnis = app(PairingService::class)->neighborsForName(
            (string) $arguments['zutat'],
            isset($arguments['type']) ? (string) $arguments['type'] : null,
            min(100, max(1, (int) ($arguments['limit'] ?? 20))),
        );
        if ($ergebnis['anker'] === null) {
            return ToolResult::error('Kein Pairing-Anker für diese Zutat gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success($ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'flavor', 'aroma', 'kombination', 'zutat'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.pairings.SUGGEST', 'foodalchemist.knowledge.SEARCH'],
            'examples' => ['Was passt zu Kürbis?', 'Zeig mir klassische Pairings für Zander'],
        ];
    }
}
