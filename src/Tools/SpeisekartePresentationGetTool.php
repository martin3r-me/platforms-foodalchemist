<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;

/**
 * Spec 43 (read): Präsentations-Status einer Speisekarte (aktiv/live, Design, Datumsangaben, Link).
 */
class SpeisekartePresentationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_presentation.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert den Präsentations-Status einer Speisekarte: aktiv/live, Design, Freigabe-/'
            . 'Ablaufdatum und den öffentlichen Kundenlink (falls aktiv).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['speisekarte_id' => ['type' => 'integer']],
            'required' => ['speisekarte_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->find((int) $arguments['speisekarte_id']);
        if ($karte === null) {
            return ToolResult::error('Speisekarte nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'speisekarte_id' => (int) $karte->id,
            'enabled' => (bool) $karte->presentation_enabled,
            'live' => $karte->isPresentationLive(),
            'design' => $karte->presentation_design,
            'published_at' => $karte->presentation_published_at?->toIso8601String(),
            'expires_at' => $karte->presentation_expires_at?->toIso8601String(),
            'url' => ($karte->presentation_enabled && $karte->presentation_token) ? url('/p/speisekarte/' . $karte->presentation_token) : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'speisekarte', 'praesentation', 'status'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_presentation.PUBLISH'],
            'examples' => ['Ist Speisekarte 7 veröffentlicht?'],
        ];
    }
}
