<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumPin as Pin;
use Platform\FoodAlchemist\Services\ObservatoriumPinnwandService;

/** Team-Pinnwand im Observatorium lesen (gemeinsam fürs Team): angepinnte Meldungen und eigene Ideen. */
class ObservatoriumPinnwandGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.observatorium_pinnwand.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Team-Pinnwand im Gastro-Observatorium (gemeinsam für alle im Team, anders als die persönliche Merkliste): '
            .'angepinnte Meldungen (eingang_id, Titel, Herausgeber, URL) und eigene Ideen (Titel, Notiz, Link), neueste zuerst.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        return ToolResult::success(['pins' => app(ObservatoriumPinnwandService::class)->liste($team)->map(fn (Pin $p) => [
            'id' => (int) $p->id, 'art' => $p->eingang_id ? 'meldung' : 'idee', 'eingang_id' => $p->eingang_id ? (int) $p->eingang_id : null,
            'titel' => $p->eingang?->titel ?? $p->titel, 'herausgeber' => $p->eingang?->herausgeber, 'url' => $p->eingang?->url ?? $p->link,
            'notiz' => $p->notiz, 'angeheftet_am' => $p->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'observatorium', 'pinnwand'], 'read_only' => true,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.observatorium_pinnwand.POST', 'foodalchemist.observatorium_pinnwand.DELETE']];
    }
}
