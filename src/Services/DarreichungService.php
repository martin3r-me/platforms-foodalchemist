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
 *  - Stufe 2 (Deltas): Misch-Preis/g über Komponenten NACH Delta (omitted raus,
 *    Kosten skalieren linear mit der Masse), dann × Grammatur × Anzahl
 *  - preis_modus auto: sales_net = ek_portion × (1 + rohaufschlag/100); manuell bleibt
 *  - sales_gross in beiden Modi aus MwSt der Aufschlagsklasse
 *  - Standard-Darreichung spiegelt sales_net nach recipes.sales_net (Anzeige-Cache)
 */
class DarreichungService
{
    public function __construct(private RecipeRecomputeService $recompute) {}

    public function anlegen(Team $team, int $recipeId, int $servierformId, array $attrs = [], string $createdVia = 'fa_ui'): FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if ($recipe->presentations()->where('serving_form_id', $servierformId)->exists()) {
            throw new \RuntimeException('Diese Servierform existiert schon an diesem Gericht.');
        }

        // Soft-gelöschte Zeile derselben Form blockiert den DB-Unique → reaktivieren statt einfügen
        $trashed = FoodAlchemistRecipeDarreichung::onlyTrashed()
            ->where('recipe_id', $recipe->id)->where('serving_form_id', $servierformId)->first();
        if ($trashed !== null) {
            $trashed->forceDelete();
        }

        $standard = $recipe->standardPresentation;
        $darreichung = FoodAlchemistRecipeDarreichung::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'serving_form_id' => $servierformId,
            'is_standard' => $standard === null,               // erste Form = Standard
            // Vorbefüllung aus der Standard-Form (F2: KEIN Pauschal-Faktor — User passt an)
            'quantity_per_unit_g' => $attrs['quantity_per_unit_g'] ?? $standard?->quantity_per_unit_g,
            'unit_vocab_id' => $attrs['unit_vocab_id'] ?? $standard?->unit_vocab_id,
            'unit_count' => $attrs['unit_count'] ?? $standard?->unit_count,
            'markup_class_id' => $attrs['markup_class_id'] ?? $standard?->markup_class_id,
            'price_mode' => $attrs['price_mode'] ?? 'auto',
            'sales_net' => $attrs['sales_net'] ?? null,
            'note' => $attrs['note'] ?? null,
            'created_via' => $createdVia,
        ]);

        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    /**
     * Stellt sicher, dass ein (VK-)Gericht eine Standard-Darreichung hat. Legt sonst
     * eine auf der Form „unbestimmt" an (Review-Queue, kein teller-Default — Servierform-
     * Regel der Umbau-Spec) und übernimmt die Legacy-VK-Felder als Startwerte. Idempotent.
     * Gibt die Standard-Darreichung zurück (oder null, wenn die unbestimmt-Form fehlt).
     */
    public function ensureStandard(Team $team, int $recipeId, string $createdVia = 'mcp'): ?FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);

        $standard = $recipe->standardPresentation()->first();
        if ($standard !== null) {
            return $standard;
        }
        if ($recipe->presentations()->exists()) {
            return null; // Varianten ohne Standard-Flag: nichts raten (wie syncStandardDarreichung)
        }

        $unbestimmt = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('code', 'unbestimmt')->value('id');
        if ($unbestimmt === null) {
            return null;
        }

        return $this->anlegen($team, $recipe->id, (int) $unbestimmt, [
            'quantity_per_unit_g' => $recipe->sales_quantity_per_unit_g,
            'unit_vocab_id' => $recipe->sales_unit_vocab_id,
            'unit_count' => $recipe->sales_unit_count,
            'markup_class_id' => $recipe->markup_class_id,
        ], $createdVia);
    }

    private const FELDER = [
        'quantity_per_unit_g', 'unit_vocab_id', 'unit_count',
        'markup_class_id', 'price_mode', 'sales_net',
        'container_warm_vocab_id', 'container_cold_vocab_id',
        'regeneration_temp_c', 'regeneration_duration_min', 'regeneration_core_temp_c',
        'regeneration_device_vocab_id', 'serving_vehicle_vocab_id',
        'work_time_surcharge_min', 'offer_text_override', 'note',
        'tableware_item_id', // Default-Geschirr der Form (Concepter-Vorschlag)
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
        if ($darreichung->is_standard && $darreichung->recipe->presentations()->count() > 1) {
            throw new \RuntimeException('Standard-Darreichung zuerst auf eine andere Form übertragen.');
        }
        $darreichung->delete();
    }

    public function setzeStandard(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        DB::transaction(function () use ($darreichung) {
            // Reihenfolge wichtig: partieller Unique-Index erlaubt nur EIN is_standard=1
            $darreichung->recipe->presentations()
                ->where('id', '!=', $darreichung->id)
                ->update(['is_standard' => false]);
            $darreichung->update(['is_standard' => true]);
        });
        $this->spiegleStandardVk($darreichung->recipe->fresh());
    }

    /** Delta setzen/ändern — nur Zutatenzeilen des eigenen Rezepts (E5 strukturell). */
    public function setzeDelta(Team $team, int $darreichungId, int $recipeIngredientId, ?float $mengeOverrideG, bool $omitted): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $gehoertZumRezept = $darreichung->recipe->ingredients()
            ->where('id', $recipeIngredientId)->exists();
        if (! $gehoertZumRezept) {
            throw new \RuntimeException('Zutat gehört nicht zum Kernrezept (E5: keine neuen Zutaten).');
        }
        if ($mengeOverrideG === null && ! $omitted) {
            $this->entferneDelta($team, $darreichungId, $recipeIngredientId);

            return;
        }
        FoodAlchemistRecipeDarreichungDelta::withTrashed()->updateOrCreate(
            ['presentation_id' => $darreichung->id, 'recipe_ingredient_id' => $recipeIngredientId],
            ['team_id' => $darreichung->team_id, 'quantity_override_g' => $mengeOverrideG,
                'omitted' => $omitted, 'deleted_at' => null],
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
        $recipe = $darreichung->recipe()->with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->first();
        $deltas = $darreichung->deltas()->get();

        if ($deltas->isEmpty()) {
            // Stufe 1: proportional — EK/g des Rezepts × Grammatur × Anzahl
            $ekProG = $recipe->ek_per_kg_eur !== null ? (float) $recipe->ek_per_kg_eur / 1000.0 : null;
            $ekPortion = ($ekProG !== null && (float) $darreichung->quantity_per_unit_g > 0)
                ? round($ekProG * (float) $darreichung->quantity_per_unit_g
                    * (float) ($darreichung->unit_count ?: 1), 4)
                : null;
        } else {
            // Stufe 2: Overrides sind ECHTE Gramm je Einheit dieser Form (User-Entscheid
            // 2026-07-03) — die Grammatur der Form ergibt sich aus der Komponenten-Summe,
            // der EK direkt aus Σ (Preis/g × Gramm). Kein Verhältnis-Umweg mehr.
            $proEinheit = $this->standardProEinheit($recipe);
            $deltaMap = $deltas->keyBy('recipe_ingredient_id');
            $kosten = 0.0;
            $masse = 0.0;
            $nBepreist = 0;
            foreach ($proEinheit as $ingId => $zeile) {
                $delta = $deltaMap->get($ingId);
                if ($delta !== null && $delta->omitted) {
                    continue;
                }
                $m = $delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : $zeile['masse_g'];
                if ($zeile['kosten_pro_g'] !== null) {
                    $nBepreist++;
                    $kosten += $zeile['kosten_pro_g'] * $m;
                }
                $masse += $m;
            }
            // Auto-Grammatur: g/Einheit = Summe der Komponenten dieser Form
            $darreichung->quantity_per_unit_g = $masse > 0 ? round($masse, 1) : $darreichung->quantity_per_unit_g;
            $ekPortion = $nBepreist > 0
                ? round($kosten * (float) ($darreichung->unit_count ?: 1), 4)
                : ($recipe->ek_per_kg_eur !== null && $masse > 0
                    ? round((float) $recipe->ek_per_kg_eur / 1000.0 * $masse
                        * (float) ($darreichung->unit_count ?: 1), 4)
                    : null);
        }

        $klasse = $darreichung->markupClass;
        $vkNetto = $darreichung->price_mode === 'manuell'
            ? $darreichung->sales_net
            : (($ekPortion !== null && $klasse !== null)
                ? round($ekPortion * (1 + ((float) $klasse->raw_markup_pct) / 100), 2)
                : null);
        $vkBrutto = ($vkNetto !== null && $klasse !== null)
            ? round((float) $vkNetto * (1 + ((float) $klasse->vat_rate) / 100), 2)
            : null;

        $darreichung->update(['quantity_per_unit_g' => $darreichung->quantity_per_unit_g,
            'ek_portion' => $ekPortion, 'sales_net' => $vkNetto, 'sales_gross' => $vkBrutto]);

        if ($darreichung->is_standard) {
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

    /**
     * Standard-Komposition JE EINHEIT: Batch-Massen der Zutaten skaliert auf die
     * Grammatur der Standard-Darreichung (Fallback: ganze Charge = eine Einheit).
     * Referenz für Delta-Editor (Anzeige „Standard (g)") und Delta-Preisrechnung.
     *
     * @return array<int, array{masse_g: float, kosten_pro_g: ?float}>
     */
    public function standardProEinheit(FoodAlchemistRecipe $recipe): array
    {
        $zeilen = $this->recompute->zeilenKostenUndMassen($recipe);
        $batchG = array_sum(array_map(fn ($z) => $z['masse_g'], $zeilen));
        // Referenz = Grammatur der Standard-Form — aber nur, wenn diese selbst delta-frei
        // ist (sonst wäre die Referenz zirkulär, weil ihre Grammatur aus Deltas entsteht).
        $standard = $recipe->standardPresentation()->first();
        $stdG = ($standard !== null && ! $standard->deltas()->exists()) ? $standard->quantity_per_unit_g : null;
        $faktor = ($batchG > 0 && $stdG !== null && (float) $stdG > 0) ? (float) $stdG / $batchG : 1.0;

        $out = [];
        foreach ($zeilen as $ingId => $z) {
            $out[$ingId] = [
                'masse_g' => $z['masse_g'] * $faktor,
                'kosten_pro_g' => ($z['kosten'] !== null && $z['masse_g'] > 0) ? $z['kosten'] / $z['masse_g'] : null,
            ];
        }

        return $out;
    }

    /** recipes.sales_net = Anzeige-Cache der Standard-Darreichung (Preis-Wahrheit = Darreichung). */
    private function spiegleStandardVk(FoodAlchemistRecipe $recipe): void
    {
        $standard = $recipe->standardPresentation()->first();
        if ($standard !== null) {
            DB::table('foodalchemist_recipes')->where('id', $recipe->id)
                ->update(['sales_net' => $standard->sales_net]);
        }
    }

    private function find(Team $team, int $darreichungId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = FoodAlchemistRecipeDarreichung::with('recipe')->findOrFail($darreichungId);
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($darreichung->recipe_id);

        return $darreichung;
    }
}
