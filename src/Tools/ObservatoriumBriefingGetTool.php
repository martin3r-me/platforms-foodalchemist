<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ObservatoriumBriefingService as BP;
use Platform\FoodAlchemist\Services\ObservatoriumService;

/** „Mein Briefing“ lesen: die persönliche Einstellung und die aktuellen Top-Meldungen dazu. */
class ObservatoriumBriefingGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.observatorium_briefing.GET';
    }

    public function getDescription(): string
    {
        return 'Liest „Mein Briefing“ im Gastro-Observatorium für den angemeldeten Nutzer: die gespeicherte Einstellung (branchen, region, tage, '
            .'themen; null = keine) und die neuesten Meldungen dazu (max. limit, Standard 5). Ohne Einstellung kommen keine Meldungen. '
            .'Einstellen: foodalchemist.observatorium_briefing.PUT.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => '1–20, Standard 5.']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null || $context->user === null) {
            return ToolResult::error('Kein Team oder Nutzer im Kontext.', 'NO_TEAM');
        }
        $p = app(BP::class)->profil($team, (int) $context->user->id);
        if ($p === null) {
            return ToolResult::success(['einstellung' => null, 'meldungen' => []]);
        }
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 5)));
        $meldungen = app(ObservatoriumService::class)->gruppiert($team, BP::filter($p))->limit($limit)->get()
            ->map(fn ($e) => ['eingang_id' => (int) $e->id, 'titel' => $e->titel, 'herausgeber' => $e->herausgeber ?: $e->quelle?->name,
                'veroeffentlicht_am' => $e->veroeffentlicht_am?->toDateString(), 'zusammenfassung' => $e->zusammenfassung ?: $e->teaser, 'url' => $e->url])->all();

        return ToolResult::success(['einstellung' => ['branchen' => $p->branchen ?? [], 'region' => $p->region, 'tage' => $p->tage,
            'themen' => $p->themen ?? [], 'kurz' => BP::zusammenfassung($p)], 'meldungen' => $meldungen]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'observatorium', 'briefing'], 'read_only' => true,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.observatorium_briefing.PUT']];
    }
}
