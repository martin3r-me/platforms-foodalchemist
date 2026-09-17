<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Spec 53 / Paket D — GL-07 für „Reichere dieses Rezept vollständig an": prüft nur Sichtbarkeit/
 * Ownership und liefert einen Vorschlag zurück (`read_only => true`, schreibt nichts, dispatcht
 * keinen Job). Der Bestätigen-Klick im Modal (`VoiceModal::anreicherungStarten()`) dispatcht
 * {@see \Platform\FoodAlchemist\Jobs\EnrichRecipeJob} — genau wie der bestehende „Alles
 * anreichern"-Knopf im Rezept-Editor ({@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::allesAnreichern}),
 * nur über den Sprachpfad ausgelöst statt per Klick.
 */
class AnreicherungVorschlagPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.anreicherung_vorschlag.POST';
    }

    public function getDescription(): string
    {
        return 'Schlägt vor, ein Rezept vollständig anzureichern (Beschreibung, Pairing, GP-Mint, EK, '
            . 'Step-by-Step) — SCHREIBT NICHTS, nur ein Vorschlag zum Bestätigen. recipe_id: das Rezept '
            . '(muss sichtbar/team-eigen sein). Nutzen bei „reichere dieses Rezept an", „vervollständige das Rezept".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['recipe_id'] ?? 0);
        $recipe = app(RecipeService::class)->detail($team, $id);
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['vorschlag' => [
            'recipe_id' => (int) $recipe->id,
            'name' => (string) $recipe->name,
            'status' => $this->statusWert($recipe),                    // recipes.status ist ein Enum-Cast (RecipeStatus)
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'utility',
            'tags' => ['foodalchemist', 'rezept', 'anreicherung', 'voice', 'proposal', 'gl-07'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Reichere dieses Rezept vollständig an'],
        ];
    }
}
