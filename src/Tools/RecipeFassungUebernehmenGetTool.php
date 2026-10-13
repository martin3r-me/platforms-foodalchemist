<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RezeptFolgeService;

/** Spec 82 §12 B / 4d · Vorschau „Übernehmen am Rezept“: neue Fassung, Allergen-/Zusatzstoff-Diff (Endstand), Zwischenfassungen, ob Bestätigung nötig ist. Schreibt nichts. */
class RecipeFassungUebernehmenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_fassungen.UEBERNEHMEN_GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt, was das Übernehmen einer neueren Abo-Fassung an einem EIGENEN Rezept täte (das Rezept nutzt eine ältere Fassung als Zutat): neue Fassung mit Änderungsnotiz, '
            .'Allergen-/Zusatzstoff-Diff (Endstand), Zwischenfassungen, ob eine Bestätigung nötig ist (nur Rolle Freigeben) und ob das Rezept in einem eingefrorenen Angebot steckt. '
            .'Rezepte ohne Allergen-Änderung folgen der neuen Fassung automatisch beim Veröffentlichen; dieses Tool betrifft die, die stehen geblieben sind.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer'], 'familie_id' => ['type' => 'integer', 'description' => 'Arbeitsrezept-ID der Fassungs-Familie (fassung_von_id), aus dem Signal.']], 'required' => ['recipe_id', 'familie_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            return ToolResult::success(['vorschau' => app(RezeptFolgeService::class)->uebernahmeVorschau($team, (int) $arguments['recipe_id'], (int) $arguments['familie_id'])]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'recipe', 'fassung', 'allergen', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.recipe_fassungen.UEBERNEHMEN_POST', 'foodalchemist.recipe_fassungen.GET'],
            'examples' => ['Was passiert, wenn mein Rezept die neue Jus-Fassung übernimmt?'],
        ];
    }
}
