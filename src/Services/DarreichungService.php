<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;

/**
 * Darreichungs-CRUD + Preisrechnung (Umbau-Spec Darreichungen, Phase 5).
 *
 * Invarianten:
 *  - genau EINE Standard-Darreichung pro Gericht (setzeStandard swappt transaktional)
 *  - eine Servierform höchstens einmal pro Gericht (DB-Unique)
 *  - Deltas nur auf Zutatenzeilen des Kernrezepts (FK) — Grenzregel E5:
 *    weglassen/umgewichten ja, neue Zutaten strukturell unmöglich
 *  - FA-native Anlagen tragen created_via (F12; WaWi-Importe haben legacy_id)
 *
 * Preise (spiegelt WaWi-Recompute 206 Stufe 4):
 *  - Stufe 1 (keine Deltas): ek_portion = EK/g des Rezepts × Grammatur × Anzahl
 *  - Stufe 2 (Deltas): Misch-Preis/g über Komponenten NACH Delta (weggelassen raus,
 *    Kosten skalieren linear mit der Masse), dann × Grammatur × Anzahl
 *  - preis_modus auto: vk_netto = ek_portion × (1 + rohaufschlag/100); manuell bleibt
 *  - vk_brutto in beiden Modi aus MwSt der Aufschlagsklasse
 *  - Standard-Darreichung spiegelt vk_netto nach recipes.vk_netto (Anzeige-Cache)
 */
class DarreichungService
{
    public function __construct(private RecipeRecomputeService $recompute) {}

    public function anlegen(Team $team, int $recipeId, int $servierformId, array $attrs = [], string $createdVia = 'fa_ui'): FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if ($recipe->darreichungen()->where('servierform_id', $servierformId)->exists()) {
            throw new \RuntimeException('Diese Servierform existiert schon an diesem Gericht.');
        }

        // Soft-gelöschte Zeile derselben Form blockiert den DB-Unique → reaktivieren statt einfügen
        $trashed = FoodAlchemistRecipeDarreichung::onlyTrashed()
            ->where('recipe_id', $recipe->id)->where('servierform_id', $servierformId)->first();
        if ($trashed !== null) {
            $trashed->forceDelete();
        }

        $standard = $recipe->standardDarreichung;
        $darreichung = FoodAlchemistRecipeDarreichung::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'servierform_id' => $servierformId,
            'ist_standard' => $standard === null,               // erste Form = Standard
            // Vorbefüllung aus der Standard-Form (F2: KEIN Pauschal-Faktor — User passt an)
            'menge_pro_einheit_g' => $attrs['menge_pro_einheit_g'] ?? $standard?->menge_pro_einheit_g,
            'einheit_vocab_id' => $attrs['einheit_vocab_id'] ?? $standard?->einheit_vocab_id,
            'anzahl_einheiten' => $attrs['anzahl_einheiten'] ?? $standard?->anzahl_einheiten,
            'aufschlagsklasse_id' => $attrs['aufschlagsklasse_id'] ?? $standard?->aufschlagsklasse_id,
            'preis_modus' => $attrs['preis_modus'] ?? 'auto',
            'vk_netto' => $attrs['vk_netto'] ?? null,
            'note' => $attrs['note'] ?? null,
            'created_via' => $createdVia,
        ]);

        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    private const FELDER = [
        'menge_pro_einheit_g', 'einheit_vocab_id', 'anzahl_einheiten',
        'aufschlagsklasse_id', 'preis_modus', 'vk_netto',
        'behaelter_warm_vocab_id', 'behaelter_kalt_vocab_id',
        'regeneration_temp_c', 'regeneration_dauer_min', 'regeneration_kerntemp_c',
        'regeneration_geraet_vocab_id', 'servier_vehikel_vocab_id',
        'arbeitszeit_zuschlag_min', 'angebotstext_override', 'note',
    ];

    public function aktualisieren(Team $team, int $darreichungId, array $attrs): FoodAlchemistRecipeDarreichung
    {
        $darreichung = $this->find($team, $darreichungId);
        $update = array_intersect_key($attrs, array_flip(self::FELDER));
        foreach ($update as $k => $v) {
            if ($v === '') {
                $update[$k] = null;
            }
        }
        $darreichung->update($update);
        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    public function loeschen(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        if ($darreichung->ist_standard && $darreichung->recipe->darreichungen()->count() > 1) {
            throw new \RuntimeException('Standard-Darreichung zuerst auf eine andere Form übertragen.');
        }
        $darreichung->delete();
    }

    public function setzeStandard(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        DB::transaction(function () use ($darreichung) {
            // Reihenfolge wichtig: partieller Unique-Index erlaubt nur EIN ist_standard=1
            $darreichung->recipe->darreichungen()
                ->where('id', '!=', $darreichung->id)
                ->update(['ist_standard' => false]);
            $darreichung->update(['ist_standard' => true]);
        });
        $this->spiegleStandardVk($darreichung->recipe->fresh());
    }

    /** Delta setzen/ändern — nur Zutatenzeilen des eigenen Rezepts (E5 strukturell). */
    public function setzeDelta(Team $team, int $darreichungId, int $recipeIngredientId, ?float $mengeOverrideG, bool $weggelassen): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $gehoertZumRezept = $darreichung->recipe->ingredients()
            ->where('id', $recipeIngredientId)->exists();
        if (! $gehoertZumRezept) {
            throw new \RuntimeException('Zutat gehört nicht zum Kernrezept (E5: keine neuen Zutaten).');
        }
        if ($mengeOverrideG === null && ! $weggelassen) {
            $this->entferneDelta($team, $darreichungId, $recipeIngredientId);

            return;
        }
        FoodAlchemistRecipeDarreichungDelta::withTrashed()->updateOrCreate(
            ['darreichung_id' => $darreichung->id, 'recipe_ingredient_id' => $recipeIngredientId],
            ['team_id' => $darreichung->team_id, 'menge_override_g' => $mengeOverrideG,
                'weggelassen' => $weggelassen, 'deleted_at' => null],
        );
        $this->recomputePreise($darreichung);
    }

    public function entferneDelta(Team $team, int $darreichungId, int $recipeIngredientId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $darreichung->deltas()->where('recipe_ingredient_id', $recipeIngredientId)->forceDelete();
        $this->recomputePreise($darreichung);
    }

    /** EK/VK einer Darreichung neu rechnen (Stufe 1 + Stufe-2-Deltas). */
    public function recomputePreise(FoodAlchemistRecipeDarreichung $darreichung): void
    {
        $recipe = $darreichung->recipe()->with('ingredients.einheit', 'ingredients.gp', 'ingredients.referencedRecipe')->first();
        $ekProG = $this->ekProGramm($recipe, $darreichung);

        $ekPortion = null;
        if ($ekProG !== null && (float) $darreichung->menge_pro_einheit_g > 0) {
            $ekPortion = round($ekProG * (float) $darreichung->menge_pro_einheit_g
                * (float) ($darreichung->anzahl_einheiten ?: 1), 4);
        }

        $klasse = $darreichung->aufschlagsklasse;
        $vkNetto = $darreichung->preis_modus === 'manuell'
            ? $darreichung->vk_netto
            : (($ekPortion !== null && $klasse !== null)
                ? round($ekPortion * (1 + ((float) $klasse->rohaufschlag_pct) / 100), 2)
                : null);
        $vkBrutto = ($vkNetto !== null && $klasse !== null)
            ? round((float) $vkNetto * (1 + ((float) $klasse->mwst_satz) / 100), 2)
            : null;

        $darreichung->update(['ek_portion' => $ekPortion, 'vk_netto' => $vkNetto, 'vk_brutto' => $vkBrutto]);

        if ($darreichung->ist_standard) {
            $this->spiegleStandardVk($recipe);
        }
    }

    /** Alle Darreichungen eines Gerichts neu rechnen (nach Zutaten-/EK-Änderung). */
    public function recomputeFuerRezept(int $recipeId): void
    {
        foreach (FoodAlchemistRecipeDarreichung::where('recipe_id', $recipeId)->get() as $d) {
            $this->recomputePreise($d);
        }
    }

    /** EK/g: ohne Deltas = ek_per_kg/1000; mit Deltas = Misch-Preis über Komponenten nach Delta. */
    private function ekProGramm(FoodAlchemistRecipe $recipe, FoodAlchemistRecipeDarreichung $darreichung): ?float
    {
        $basis = $recipe->ek_per_kg_eur !== null ? (float) $recipe->ek_per_kg_eur / 1000.0 : null;
        $deltas = $darreichung->deltas()->get();
        if ($deltas->isEmpty()) {
            return $basis;
        }

        $zeilen = $this->recompute->zeilenKostenUndMassen($recipe);
        $deltaMap = $deltas->keyBy('recipe_ingredient_id');
        $kosten = 0.0;
        $masse = 0.0;
        $nBepreist = 0;
        foreach ($zeilen as $ingId => $zeile) {
            $delta = $deltaMap->get($ingId);
            if ($delta !== null && $delta->weggelassen) {
                continue;
            }
            $m = $delta?->menge_override_g !== null ? (float) $delta->menge_override_g : $zeile['masse_g'];
            $skala = ($delta?->menge_override_g !== null && $zeile['masse_g'] > 0)
                ? $m / $zeile['masse_g'] : 1.0;
            if ($zeile['kosten'] !== null) {
                $nBepreist++;
            }
            $kosten += ((float) ($zeile['kosten'] ?? 0)) * $skala;
            $masse += $m;
        }

        // Keine einzige bepreiste Komponente → EK ehrlich unbekannt (nicht 0,00 €)
        if ($nBepreist === 0) {
            return $basis;
        }

        return $masse > 0 ? $kosten / $masse : $basis;
    }

    /** recipes.vk_netto = Anzeige-Cache der Standard-Darreichung (Preis-Wahrheit = Darreichung). */
    private function spiegleStandardVk(FoodAlchemistRecipe $recipe): void
    {
        $standard = $recipe->standardDarreichung()->first();
        if ($standard !== null) {
            DB::table('foodalchemist_recipes')->where('id', $recipe->id)
                ->update(['vk_netto' => $standard->vk_netto]);
        }
    }

    private function find(Team $team, int $darreichungId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = FoodAlchemistRecipeDarreichung::with('recipe')->findOrFail($darreichungId);
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($darreichung->recipe_id);

        return $darreichung;
    }
}
