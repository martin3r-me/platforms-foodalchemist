<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * MCP-Steuerbarkeit · D5c: Aroma-Kohäsion der Gerichte eines Konzepts (read-only). Spiegelt den
 * Concepter-Editor „Kohärenz prüfen": Score, Abdeckung, schwächstes Paar, Warnung.
 */
class ConceptsCohesionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.COHESION';
    }

    public function getDescription(): string
    {
        return 'Berechnet die Aroma-Kohäsion der Gerichte eines (sichtbaren) Konzepts: Score/Abdeckung/schwächstes Paar/Warnung. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (sichtbar).'],
            ],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if ($conceptId <= 0) {
            return ToolResult::error('concept_id ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $kohaesion = app(ConceptService::class)->menueKohaesion($team, $conceptId);
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Konzept nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'concept_id' => $conceptId,
            'score' => $kohaesion['score'] ?? null,
            'coverage_pct' => $kohaesion['coverage_pct'] ?? null,
            'rated_pairs' => $kohaesion['rated_pairs'] ?? null,
            'total_pairs' => $kohaesion['total_pairs'] ?? null,
            'weakest_pair' => $kohaesion['weakest_pair'] ?? null,
            'warnung' => $kohaesion['warnung'] ?? null,
            'zu_wenig' => $kohaesion['zu_wenig'] ?? false,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'concept', 'pairing', 'cohesion', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.concepts.GET'],
            'examples' => ['Wie kohärent ist Konzept 7 aromatisch?'],
        ];
    }
}
