<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;

/**
 * #1002: offene KI-Aufträge des externen Wegs — voller Prompt (Messages inkl. Wissens-Kanon, Kontext, Antwort-Umschlag),
 * den der MCP-Client mit seinem Modell beantwortet. Antwort per foodalchemist.ki_auftraege.ANTWORT.
 */
class KiAuftraegeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ki_auftraege.GET';
    }

    public function getDescription(): string
    {
        return 'Listet OFFENE KI-Aufträge (externer Anbieter-Weg, Paket bis 50) mit vollem Prompt (messages) und Antwort-Vorgabe. '
            . 'Beantworte jeden Auftrag exakt nach dem Umschlag in den messages (JSON) und sende ihn per foodalchemist.ki_auftraege.ANTWORT zurück. '
            . 'Aufträge entstehen, wenn ein KI-Lauf im externen Modus (z. B. Backfill --anbieter=extern) keinen Plattform-Provider ruft.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Paketgröße, 1–50 (Standard 50)'],
                'feature' => ['type' => 'string', 'description' => 'optional: nur dieses Feature (Prompt-Key, z. B. gp.sensorik)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zeilen = app(KiAuftragService::class)->offene((int) $team->id, (int) ($arguments['limit'] ?? 50), $arguments['feature'] ?? null);

        return ToolResult::success([
            'anzahl' => count($zeilen),
            'auftraege' => array_map(fn ($z) => [
                'auftrag_id' => $z->uuid,
                'feature' => $z->feature,
                'ziel' => $z->target_table ? ['tabelle' => $z->target_table, 'id' => $z->target_id] : null,
                'laeuft_ab_at' => $z->laeuft_ab_at,
                'messages' => json_decode((string) $z->messages, true),
                // 2d-2: Websuche-Auftrag — selbst im Web suchen und die gefundenen Seiten als quellen mitsenden (sonst abgelehnt)
                'web_suche' => (json_decode((string) ($z->optionen ?? ''), true)['include_web_search'] ?? false) === true,
            ], $zeilen),
            'note' => 'Je Auftrag: Antwort als JSON-Umschlag laut messages an foodalchemist.ki_auftraege.ANTWORT (auftrag_id, werte, anbieter, modell).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'ki', 'auftrag', 'extern', 'mcp'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => [],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ki_auftraege.ANTWORT'],
            'examples' => ['Hole die offenen KI-Aufträge (Paket 50) und beantworte sie'],
        ];
    }
}
