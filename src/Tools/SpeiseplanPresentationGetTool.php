<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 43 (read): Präsentations-Status eines Speiseplans (aktiv/live, Design, Datumsangaben, Link).
 */
class SpeiseplanPresentationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_presentation.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert den Präsentations-Status eines Speiseplans: aktiv/live, Design, Freigabe-/'
            . 'Ablaufdatum und den öffentlichen Aushang-Link (falls aktiv).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['speiseplan_id' => ['type' => 'integer']],
            'required' => ['speiseplan_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->find((int) $arguments['speiseplan_id']);
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'speiseplan_id' => (int) $plan->id,
            'enabled' => (bool) $plan->presentation_enabled,
            'live' => $plan->isPresentationLive(),
            'design' => $plan->presentation_design,
            'published_at' => $plan->presentation_published_at?->toIso8601String(),
            'expires_at' => $plan->presentation_expires_at?->toIso8601String(),
            'slug' => $plan->presentation_slug,
            'url' => ($plan->presentation_enabled && $plan->presentationPublicRef())
                ? url('/p/speiseplan/' . $plan->presentationPublicRef()) : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'speiseplan', 'praesentation', 'status'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speiseplan_presentation.PUBLISH'],
            'examples' => ['Ist Speiseplan 3 als Aushang veröffentlicht?'],
        ];
    }
}
