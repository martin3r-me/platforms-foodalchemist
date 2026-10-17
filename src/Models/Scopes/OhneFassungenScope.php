<?php

namespace Platform\FoodAlchemist\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 B (Bauplan §2, Auflage 3): Fassungs-Zeilen (veröffentlichte, eingefrorene Master-Stände) sind standardmäßig
 * in KEINER Rezept-, Konzept-, Paket- und Format-Abfrage — Listen, Zähler, Suchen, Batch-Commands, MCP. Sonst zählte der Master sie doppelt, und
 * Massen-Pfade (KI-Klassifizierung, Rollen, Recompute-Läufe) würden eingefrorene Zeilen beschreiben.
 *
 * Bewusst aufgehoben (withoutGlobalScope) nur dort, wo Fassungen gemeint sind:
 *  - BelongsToTeamHierarchy::scopeVisibleToTeam (Rezepte) — die Sichtbarkeit regelt InhaltsFreigabe selbst
 *    (normale Sicht ohne Fassungen, Abo-Zweig nur Fassungen),
 *  - Zutaten-Relationen (recipe, referencedRecipe) — eine Fassung verweist auf Unter-Fassungen,
 *  - RecipeRecomputeService::recomputePipeline, FassungService, RecipeFassungenGetTool.
 * Rohe DB::table-Abfragen sind nicht betroffen (Liste und Begründung im PR).
 */
class OhneFassungenScope implements Scope
{
    /** @var array<string, bool> Schema-Merker je Tabelle (ein Scope, mehrere Modelle). */
    private static array $spalteDa = [];

    public function apply(Builder $builder, Model $model): void
    {
        // Spec 84 B3: greift der automatische Sichtfilter, regelt er die Fassungen selbst (Abo-Zweig = nur Fassungen).
        // Ein `ist_fassung = 0` hier oben schnitte den Abo-Zweig ab.
        if (TeamSicht::greiftAuf($builder, $model)) {
            return;
        }
        $tabelle = $model->getTable();
        self::$spalteDa[$tabelle] ??= Schema::hasColumn($tabelle, 'ist_fassung');
        if (self::$spalteDa[$tabelle]) {
            $builder->where($model->getTable() . '.ist_fassung', false);
        }
    }
}
