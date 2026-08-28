<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 43 (write): Veröffentlicht einen Speiseplan als digitalen GV-Aushang (Public-Link ohne
 * Login, Snapshot, Pflicht-Datum). Preislos + LMIV-Pflichtkennzeichnung. Nur eigene Pläne.
 */
class SpeiseplanPresentationPublishTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_presentation.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht einen Speiseplan als teilbaren digitalen Aushang (Public-Link ohne Login). '
            . 'Wochen-Raster + LMIV-Kennzeichnung + Kostformen/DGE, preislos. expires_at ist Pflicht. Nur eigene Pläne.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speiseplan_id' => ['type' => 'integer'],
                'expires_at' => ['type' => 'string', 'description' => 'Gültig bis (Datum ISO, Pflicht)'],
                'design' => ['type' => 'string', 'description' => 'editorial|menu|kiosk oder design:{id}'],
                'mahlzeit' => ['type' => 'string', 'description' => 'mittag|abend … (Default mittag)'],
                'montag' => ['type' => 'string', 'description' => 'Wochen-Montag ISO (Default: Plan-Start)'],
                'cta_text' => ['type' => 'string'],
                'cta_link' => ['type' => 'string'],
            ],
            'required' => ['speiseplan_id', 'expires_at'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $res = app(PresentationService::class)->publish($team, 'speiseplan', (int) $arguments['speiseplan_id'], [
                'expires_at' => $arguments['expires_at'] ?? null,
                'design' => $arguments['design'] ?? null,
                'mahlzeit' => $arguments['mahlzeit'] ?? 'mittag',
                'montag' => $arguments['montag'] ?? null,
                'cta' => ['text' => $arguments['cta_text'] ?? null, 'link' => $arguments['cta_link'] ?? null],
            ]);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Speiseplan nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'speiseplan', 'praesentation', 'aushang', 'snapshot', 'freigabe', 'publish'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speiseplan_presentation.WITHDRAW', 'foodalchemist.speiseplan_presentation.GET'],
            'examples' => ['Veröffentliche Speiseplan 3 als digitalen Aushang, gültig bis 2027-01-31.'],
        ];
    }
}
