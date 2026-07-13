<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\CoverageService;

/**
 * R4.2: Soll/Ist-Coverage lesen — DIESELBE Messlatte für Mensch und KI. Die KI
 * misst ihr eigenes Konzept an demselben Gerüst wie der Mensch im Editor (R6.1-DoD).
 */
class CoverageGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.coverage.GET';
    }

    public function getDescription(): string
    {
        return 'Misst das Ist eines Foodbooks/Konzepts gegen sein Planungs-Gerüst (foodalchemist.planning.GET/PUT): '
            . 'Menge je Slot, Diät-Quoten, Preisrahmen p. P. + je Slot, Saison-Abdeckung, Dramaturgie (Pflicht-Slots), '
            . 'No-Gos (Zutat/Allergen). Ampel je Befund: erfuellt|teilerfuellt|verletzt|info. '
            . 'NACH jeder KI-Befüllung aufrufen — verletzt-Befunde beheben oder dem Menschen benennen, nie ignorieren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept']],
                'owner_id' => ['type' => 'integer'],
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

        try {
            $cov = app(CoverageService::class)->coverage($team, (string) $arguments['owner_type'], (int) $arguments['owner_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        if (! $cov['hat_geruest']) {
            return ToolResult::success([
                'hat_geruest' => false,
                'hinweis' => 'Kein Planungs-Gerüst — erst mit foodalchemist.planning.PUT anlegen, dann messen.',
            ]);
        }

        return ToolResult::success($cov);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'coverage', 'soll-ist', 'ampel', 'planung', 'geruest'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planning.GET', 'foodalchemist.planning.PUT', 'foodalchemist.concepts.GET', 'foodalchemist.foodbook.GET'],
            'examples' => ['Erfüllt Konzept 7 sein Planungs-Gerüst?', 'Welche Coverage-Lücken hat Foodbook 12?'],
        ];
    }
}
