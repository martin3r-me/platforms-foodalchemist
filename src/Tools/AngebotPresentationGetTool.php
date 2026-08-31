<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;

/**
 * #380 Composer / Spec 43 (read): Präsentations-Status eines Angebots — aktiv?, live?, Design, Freigabe-/
 * Ablaufdatum, öffentlicher Link (+ Betriebs-Links). Zeigt bewusst KEINE Snapshot-Interna.
 * Spiegelt FoodbookPresentationGetTool.
 */
class AngebotPresentationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_presentation.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert den Präsentations-Status eines Angebots: aktiv/live, gewähltes Design, '
            . 'Freigabe- und Ablaufdatum sowie den öffentlichen Kundenlink (falls aktiv).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['angebot_id' => ['type' => 'integer']],
            'required' => ['angebot_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find((int) $arguments['angebot_id']);
        if ($angebot === null) {
            return ToolResult::error('Angebot nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'angebot_id' => (int) $angebot->id,
            'enabled' => (bool) $angebot->presentation_enabled,
            'live' => $angebot->isPresentationLive(),
            'design' => $angebot->presentation_design,
            'published_at' => $angebot->presentation_published_at?->toIso8601String(),
            'expires_at' => $angebot->presentation_expires_at?->toIso8601String(),
            'slug' => $angebot->presentation_slug,
            'url' => ($angebot->presentation_enabled && $angebot->presentationPublicRef())
                ? url('/p/angebot/' . $angebot->presentationPublicRef()) : null,
            // Zusätzliche Betriebs-Links (je Betrieb eigener Link/Vorlage/Freigabe).
            'betriebs_links' => app(\Platform\FoodAlchemist\Services\PresentationService::class)
                ->outletPresentations($team, 'angebot', (int) $angebot->id),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'angebot', 'praesentation', 'status'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.angebot_presentation.PUBLISH'],
            'examples' => ['Ist Angebot 5 als Kundenbuch veröffentlicht?'],
        ];
    }
}
