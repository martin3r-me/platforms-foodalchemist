<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\MasterTeam;

/** Spec 82 §8 Punkt 3 · Änderungsprotokoll der Fassungen eines Rezepts (Nummer, Datum, Notiz, Allergen-Hinweis — kein Inhalt, keine Kosten). */
class RecipeFassungenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_fassungen.GET';
    }

    public function getDescription(): string
    {
        return 'Änderungsprotokoll eines Abo- bzw. Master-Rezepts: alle Fassungen mit Nummer, Datum, Notiz und ob sich das Allergenbild '
            .'geändert hat. recipe_id = Arbeitszeile (Master) oder eine sichtbare Fassung (Mandant). Liefert keine Rezeptur und keine Kosten.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer']], 'required' => ['recipe_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $id = (int) ($arguments['recipe_id'] ?? 0);
            $familie = null;
            if (MasterTeam::istMaster($team)) {
                $familie = FoodAlchemistRecipe::where('team_id', $team->id)->where('ist_fassung', false)->whereKey($id)->value('id');
            }
            if ($familie === null) {   // Mandant: nur über eine Fassung, die er sieht
                $familie = FoodAlchemistRecipe::visibleToTeam($team)->where('foodalchemist_recipes.ist_fassung', true)
                    ->whereKey($id)->value('fassung_von_id');
            }
            if ($familie === null) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            }

            return ['fassungen' => \Platform\FoodAlchemist\Support\AboSicht::historie($team, 'recipe', (int) $familie)   // Spec 84 B3: Historie ist keine Nutzung
                ->orderByDesc('fassung_nr')->get(['id', 'name', 'fassung_nr', 'veroeffentlicht_at', 'aenderungsnotiz', 'fassung_allergen_geaendert'])
                ->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name, 'nr' => (int) $f->fassung_nr,
                    'veroeffentlicht_at' => $f->veroeffentlicht_at, 'notiz' => $f->aenderungsnotiz,
                    'allergen_geaendert' => (bool) $f->fassung_allergen_geaendert])->all()];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['rezepte', 'fassung'], ['Was hat sich am Abo-Jus geändert?'], ['foodalchemist.recipe_fassungen.PUBLISH']), [
            'tags' => ['foodalchemist', 'rezepte', 'fassung', 'abo'],
        ]);
    }
}
