<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MarktdatenService;

class MarktdatenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marktdaten.GET';
    }

    public function getDescription(): string
    {
        return 'Liest gespeicherte Destatis-Preisindizes mit Monatswerten, Quelle, Original-Kennzeichnungen und Vorjahresvergleich. Kein API- oder KI-Aufruf. Fehlender Zugang, Datenalter und Abruffehler bleiben sichtbar; keine Betriebskosten oder Trendfreigaben.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);

        return $team ? ToolResult::success(app(MarktdatenService::class)->lesen($team)) : ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'destatis', 'markt'], 'read_only' => true,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => []];
    }
}
