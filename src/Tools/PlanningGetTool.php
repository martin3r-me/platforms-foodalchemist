<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningFrameService;

/**
 * R4.1: Planungs-Gerüst lesen — das messbare SOLL eines Foodbooks/Konzepts
 * (Mengengerüst, Preisarchitektur, Diät-Quoten, Saison, No-Gos, Dramaturgie).
 * Dieselbe Messlatte für Mensch und KI: R4.2 misst dagegen, R6.1 promptet daraus.
 */
class PlanningGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planning.GET';
    }

    public function getDescription(): string
    {
        return 'Liest das Planungs-Gerüst (Soll-Rahmen) eines Foodbooks oder Konzepts: Preisarchitektur p. P., '
            . 'Slots (Gänge/Stationen mit Soll-Gerichtszahl + Preis-Anker/Spanne, Dramaturgie-Reihenfolge) und Regeln '
            . '(diet_quota, season_coverage, nogo_ingredient, nogo_allergen, allergen_line). '
            . 'VOR KI-Konzept-/Foodbook-Befüllung lesen — das Gerüst ist der verbindliche Soll-Rahmen. '
            . 'Liefert zusätzlich prompt_kontext (fertiger KI-Kontext-Block).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept']],
                'owner_id' => ['type' => 'integer', 'description' => 'ID des Foodbooks bzw. Konzepts'],
            ],
            'required' => ['owner_type', 'owner_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(PlanningFrameService::class);
        $ownerType = (string) $arguments['owner_type'];
        $ownerId = (int) $arguments['owner_id'];

        try {
            $svc->resolveOwner($team, $ownerType, $ownerId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $frame = $svc->find($ownerType, $ownerId);
        if ($frame === null) {
            return ToolResult::success([
                'existiert' => false,
                'hinweis' => 'Noch kein Gerüst — mit foodalchemist.planning.PUT anlegen.',
            ]);
        }

        return ToolResult::success([
            'existiert' => true,
            'geruest' => $svc->summary($frame),
            'prompt_kontext' => $svc->promptKontext($frame),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'planung', 'geruest', 'soll', 'coverage', 'brief'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planning.PUT', 'foodalchemist.canvas.GET', 'foodalchemist.concepts.GET', 'foodalchemist.foodbooks.GET'],
            'examples' => ['Welche Soll-Vorgaben hat Foodbook 12?', 'Lies das Planungs-Gerüst von Konzept 7'],
        ];
    }
}
