<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptOneShotService;

/**
 * Spec 50 · C-4 / E-1 — Anreicherung für ein BESTEHENDES Konzept, das Gegenstück zu
 * `recipes.ENRICH`. Kein neuer Fachpfad: {@see ConceptOneShotService} bündelt den Frame-Kopf
 * (deterministisch) und den Wording-Pass (`concept.wording`, nur Lücken).
 *
 * Synchron, weil es höchstens ein Provider-Call ist — die Antwort trägt schon das Ergebnis
 * und die verbleibenden Lücken, kein Polling nötig.
 */
class ConceptsEnrichTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.ENRICH';
    }

    public function getDescription(): string
    {
        return 'Reichert ein BESTEHENDES Konzept (Menü/Buffet/Paket) an — füllt nur Lücken (Override-First): '
            . 'Zielpreis p. P. und Preisdarstellung aus dem Planungs-Gerüst (ohne KI), dann in EINEM KI-Call '
            . 'Beschreibung, Kundenname, Claim, Gang-/Stationsüberschriften (nur die vom Gerüst gesetzten, '
            . 'nicht von Menschen betitelte) und das Wording gefüllter Positionen. Leere Positionen füllt es NICHT '
            . '(dafür concept_slots.PUT felder.sales_recipe_id). Antwort: run_id, was gefüllt wurde, und welche '
            . 'Lücken bleiben. Idempotent — ein zweiter Lauf ohne Lücken kostet keinen Call.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'writing_style_id' => ['type' => 'integer',
                    'description' => 'Schreibstil für den Wording-Teil. Default: der am Konzept hinterlegte, sonst neutral.'],
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
        $id = (int) ($arguments['concept_id'] ?? 0);
        if ($id <= 0) {
            return ToolResult::error('concept_id ist Pflicht.', 'VALIDATION_ERROR');
        }
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;                                          // D1: nur eigene Konzepte anreichern
        }

        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $stil = isset($arguments['writing_style_id']) ? (int) $arguments['writing_style_id'] : null;

        $ergebnis = app(ConceptOneShotService::class)->anreichern($team, $concept, $stil);
        $concept->refresh();

        return ToolResult::success([
            'concept' => ['id' => (int) $concept->id, 'name' => $concept->name, 'consumer_name' => $concept->consumer_name, 'claim' => $concept->claim],
            'run_id' => $ergebnis['run_id'],
            'schritte' => $ergebnis['schritte'],
            'deterministisch' => $ergebnis['deterministisch'],
            'wording' => $ergebnis['wording'],
            'fehler' => $ergebnis['fehler'],
            'luecken_vorher' => $ergebnis['luecken_vorher'],
            'luecken_nachher' => $ergebnis['luecken_nachher'],
            'hinweis' => $ergebnis['luecken_nachher']['struktur']['slot_wording'] === [] && $ergebnis['luecken_nachher']['kopf'] === []
                ? 'Kopf und Wording vollständig. Leere Positionen (falls vorhanden) über concept_slots.PUT füllen, dann erneut ENRICH.'
                : 'Verbleibende Lücken siehe luecken_nachher. target_price_per_person ohne Gerüst: concepts.PUT setzen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'konzept', 'menue', 'buffet', 'anreicherung', 'enrich', 'wording', 'ki'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'llm_call',
            'related_tools' => [
                'foodalchemist.concepts.GET', 'foodalchemist.concepts.POST', 'foodalchemist.concept_slots.PUT',
                'foodalchemist.concept_wording.GENERATE', 'foodalchemist.runs.GET',
            ],
            'examples' => [
                'Reichere Konzept 42 an (Kopf + Überschriften + Wording)',
                'Konzept 17 im Schreibstil 3 anreichern',
            ],
        ];
    }
}
