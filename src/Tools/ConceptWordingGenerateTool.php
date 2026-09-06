<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * MCP-Steuerbarkeit · D5c: KI-Wording für ein team-eigenes Konzept (Intro + Positions-Texte).
 * Grundet (Workstream W) über den ConceptService auf Cross-Cutting-Wissen + Food-DNA — identisch
 * zum Concepter-Editor (Web↔MCP-Parität).
 */
class ConceptWordingGenerateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_wording.GENERATE';
    }

    public function getDescription(): string
    {
        return 'Erzeugt KI-Wording für ein team-eigenes Konzept: Intro (→ Beschreibung) + Positions-Texte '
            . '+ Gang-/Stations-Überschriften (Header-Slots, nur solange sie noch das neutrale Frame-Label tragen) '
            . '+ consumer_name/claim (nur wenn leer). Optional writing_style_id für den Schreibstil; '
            . 'nur_luecken=true füllt nur leere Felder (regeneriert nichts). Schreibt die Texte direkt ans Konzept.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'writing_style_id' => ['type' => 'integer', 'description' => 'Optionaler Schreibstil.'],
                'nur_luecken' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur leere Felder füllen (Intro/Wording/Header), nichts regenerieren.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $conceptId, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            $res = app(ConceptService::class)->generateWording(
                $team,
                $conceptId,
                isset($arguments['writing_style_id']) ? (int) $arguments['writing_style_id'] : null,
                ['nur_luecken' => (bool) ($arguments['nur_luecken'] ?? false)]
            );
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Konzept nicht sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => $conceptId, 'intro' => $res['intro'], 'slots_set' => $res['slots_set'],
            'header_set' => $res['header_set'], 'kopf_set' => $res['kopf_set']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'wording', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slots.PUT', 'foodalchemist.concepts.PUT'],
            'examples' => ['Erzeuge Wording für Konzept 7 im Schreibstil 3.'],
        ];
    }
}
