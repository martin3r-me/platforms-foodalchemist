<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceChangeAudit;
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
 *  - auto: dynamischer Vorschlag aus Unternehmens-Basissatz × relativem Klassenfaktor
 *  - fixed: Live-VK bleibt, der Vergleichsvorschlag rechnet weiter; Begründung ist Pflicht
 *  - MwSt kommt als Profil-Schlüssel aus Darreichung → Preisklasse → Team
 *  - Standard-Darreichung spiegelt sales_net nach recipes.sales_net (Anzeige-Cache)
 */
class DarreichungService
{
    public function __construct(
        private RecipeRecomputeService $recompute,
        private CatalogPricingService $catalogPricing,
        private TeamSettingsService $settings,
    ) {}

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
            'markup_class_id' => $attrs['markup_class_id'] ?? $standard?->markup_class_id
                ?? $recipe->markup_class_id ?? $this->settings->defaultMarkupClassId($team),
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

        $unbestimmt = $this->unbestimmtId($team);

        $quantityPerUnitG = $recipe->sales_quantity_per_unit_g;
        if ($quantityPerUnitG === null && $recipe->yield_kg !== null && (int) $recipe->sales_unit_count > 0) {
            $quantityPerUnitG = round((float) $recipe->yield_kg * 1000 / (int) $recipe->sales_unit_count, 1);
        }

        return $this->anlegen($team, $recipe->id, $unbestimmt, [
            'quantity_per_unit_g' => $quantityPerUnitG,
            'unit_vocab_id' => $recipe->sales_unit_vocab_id,
            // recipes.sales_unit_count ist die Ausbeute des Rezeptlaufs (Nenner für
            // Yield/Anzahl), nicht die Zahl verkaufter Einheiten IN einer Darreichung.
            // Der Standard repräsentiert genau eine Verkaufseinheit.
            'unit_count' => 1,
            'markup_class_id' => $recipe->markup_class_id,
        ], $createdVia);
    }

    private const FELDER = [
        'quantity_per_unit_g', 'unit_vocab_id', 'unit_count',
        'markup_class_id', 'price_mode', 'sales_net',
        'vat_profile_key', 'price_override_reason', 'price_override_expires_at',
        'container_warm_vocab_id', 'container_cold_vocab_id',
        'regeneration_temp_c', 'regeneration_duration_min', 'regeneration_core_temp_c',
        'regeneration_device_vocab_id', 'serving_vehicle_vocab_id',
        'work_time_surcharge_min', 'offer_text_override', 'note',
        'tableware_item_id', // Default-Geschirr der Form (Concepter-Vorschlag)
    ];

    private const DEZIMAL_FELDER = [
        'quantity_per_unit_g' => 'Grammatur',
        'unit_count' => 'Anzahl',
        'sales_net' => 'VK netto',
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
        foreach (self::DEZIMAL_FELDER as $feld => $bezeichnung) {
            if (! array_key_exists($feld, $update) || $update[$feld] === null) {
                continue;
            }
            $wert = str_replace(',', '.', trim((string) $update[$feld]));
            if (! is_numeric($wert)) {
                throw new \RuntimeException($bezeichnung . ' muss eine gültige Zahl sein.');
            }
            $update[$feld] = (float) $wert;
        }
        if (array_key_exists('vat_profile_key', $update) && $update['vat_profile_key'] !== null
            && ! in_array($update['vat_profile_key'], ['regulaer', 'ermaessigt'], true)) {
            throw new \RuntimeException('Unbekanntes MwSt-Profil.');
        }
        $legacyManual = ($update['price_mode'] ?? null) === 'manuell';
        if (array_key_exists('price_mode', $update)) {
            $update['price_mode'] = $legacyManual ? 'fixed' : $update['price_mode'];
            if (! in_array($update['price_mode'], ['auto', 'fixed'], true)) {
                throw new \RuntimeException('Unbekannter Preismodus.');
            }
        }
        $wirdFixiert = ($update['price_mode'] ?? $darreichung->price_mode) === 'fixed';
        if ($wirdFixiert) {
            $reason = trim((string) ($update['price_override_reason'] ?? $darreichung->price_override_reason ?? ''));
            if ($reason === '' && $legacyManual) {
                $reason = 'Legacy-Übernahme aus Preismodus manuell';
            }
            $fixedPrice = $update['sales_net'] ?? $darreichung->sales_net;
            if ($reason === '' || $fixedPrice === null) {
                throw new \RuntimeException('Ein fixierter Preis benötigt einen VK und eine Begründung.');
            }
            $update['price_override_reason'] = $reason;
            $update['price_override_user_id'] = Auth::id();
            $update['price_override_at'] = now();
        } elseif (($update['price_mode'] ?? null) === 'auto') {
            $update = array_merge($update, [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ]);
        }
        $darreichung->update($update);
        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    /**
     * Servierform einer bestehenden Darreichung wechseln (Entscheid Dominique 2026-09-04).
     *
     * `unbestimmt` ist ein REVIEW-ZUSTAND, kein Label. Steht fest, wie das Gericht
     * ausgegeben wird, wird die FORM der Zeile gesetzt — das Vokabular wird nie
     * umbenannt (es ist WaWi-Master und an allen Gerichten dasselbe). Darum ist
     * `serving_form_id` bewusst kein Eintrag in {@see self::FELDER}: der Formwechsel
     * ist ein eigener Übergang mit Kollisionsprüfung, kein Attribut-Update.
     *
     * Preisneutral: die Aufschlagsklasse bleibt eigenständig (Umbau-Spec F7, kein
     * Servierform→Aufschlag-Mapping), deshalb kein Recompute.
     */
    public function setzeServierform(Team $team, int $darreichungId, int $servierformId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = $this->find($team, $darreichungId);
        if ((int) $darreichung->serving_form_id === $servierformId) {
            return $darreichung;                                  // nichts zu tun
        }

        // Sichtbarkeit statt Eigentum: geerbte und globale Formen müssen am eigenen
        // Gericht wählbar bleiben (Master-Vererbung, wie TeamScope::referenz()).
        $form = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->whereKey($servierformId)->first();
        if ($form === null) {
            throw new \RuntimeException('Unbekannte Servierform.');
        }
        if ($form->is_inactive) {
            throw new \RuntimeException('Diese Servierform ist deaktiviert.');
        }
        if ($darreichung->recipe->presentations()
            ->where('serving_form_id', $servierformId)
            ->whereKeyNot($darreichung->id)->exists()) {
            throw new \RuntimeException('Diese Servierform existiert schon an diesem Gericht.');
        }

        // Soft-gelöschte Zeile derselben Form blockiert den DB-Unique (wie in anlegen()).
        FoodAlchemistRecipeDarreichung::onlyTrashed()
            ->where('recipe_id', $darreichung->recipe_id)
            ->where('serving_form_id', $servierformId)
            ->get()->each->forceDelete();

        $darreichung->update(['serving_form_id' => $servierformId]);

        return $darreichung->refresh();
    }

    public function loeschen(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        // Die Standard-Darreichung ist der Anker der Preis-Wahrheit und der Slot-Auflösung.
        // Sie darf nie verschwinden — vorher (2026-09-04) griff der Guard nur bei >1 Zeile,
        // die EINZIGE Darreichung war über den MCP-Pfad löschbar (die UI versteckt den
        // Knopf unabhängig von der Anzahl, deshalb fiel es nie auf).
        if ($darreichung->is_standard) {
            throw new \RuntimeException($darreichung->recipe->presentations()->count() > 1
                ? 'Standard-Darreichung zuerst auf eine andere Form übertragen.'
                : 'Die einzige Darreichung eines Gerichts kann nicht gelöscht werden — stattdessen die Servierform wechseln.');
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
    public function recomputePreise(FoodAlchemistRecipeDarreichung $darreichung, ?FoodAlchemistRecipe $recipe = null): void
    {
        $recipe ??= $darreichung->recipe()->with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->first();
        $team = Team::findOrFail($darreichung->team_id);
        $deltas = $darreichung->deltas()->get();

        if ($deltas->isEmpty()) {
            // Stufe 1: proportional — EK/g des Rezepts × Grammatur × Anzahl
            $ekProG = $recipe->ek_per_kg_eur !== null ? (float) $recipe->ek_per_kg_eur / 1000.0 : null;
            $grammatur = (float) ($darreichung->quantity_per_unit_g ?? 0);
            if ($grammatur <= 0 && $darreichung->is_standard) {
                $legacyGrammatur = (float) ($recipe->sales_quantity_per_unit_g ?? 0);
                $rezeptEinheiten = max(1, (int) ($recipe->sales_unit_count ?? 0));
                $grammatur = $legacyGrammatur > 0
                    ? $legacyGrammatur
                    : (($recipe->yield_kg !== null && (float) $recipe->yield_kg > 0)
                        ? (float) $recipe->yield_kg * 1000 / $rezeptEinheiten
                        : 0.0);
            }
            $ekPortion = ($ekProG !== null && $grammatur > 0)
                ? round($ekProG * $grammatur
                    * (float) ($darreichung->unit_count ?: 1), 4)
                : null;
        } else {
            // Stufe 2: Overrides sind ECHTE Gramm je Einheit dieser Form (User-Entscheid
            // 2026-07-03) — die Grammatur der Form ergibt sich aus der Komponenten-Summe,
            // der EK direkt aus Σ (Preis/g × Gramm). Kein Verhältnis-Umweg mehr.
            $proEinheit = $this->standardProEinheit($recipe, $team);
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

        $oldCalculated = $darreichung->calculated_sales_net;
        $oldEffective = $darreichung->sales_net;
        $darreichung->forceFill(['quantity_per_unit_g' => $darreichung->quantity_per_unit_g, 'ek_portion' => $ekPortion]);
        $price = $this->catalogPricing->catalogPrice($team, $darreichung);
        $darreichung->update([
            'quantity_per_unit_g' => $darreichung->quantity_per_unit_g,
            'ek_portion' => $ekPortion,
            'price_mode' => $price['price_mode'],
            'calculated_sales_net' => $price['calculated_sales_net'],
            'sales_net' => $price['sales_net'],
            'sales_gross' => $price['sales_gross'],
            'vat_profile_key' => $price['vat_profile_key'],
            'price_calculation_source' => $price['base_source'],
            'price_calculation_version' => $price['calculation_version'],
            'price_calculated_at' => now(),
            ...($price['override_expired'] ? [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ] : []),
        ]);

        if ($oldCalculated !== $darreichung->calculated_sales_net || $oldEffective !== $darreichung->sales_net) {
            FoodAlchemistPriceChangeAudit::create([
                'team_id' => $darreichung->team_id,
                'entity_type' => 'presentation',
                'entity_id' => $darreichung->id,
                'old_calculated_net' => $oldCalculated,
                'new_calculated_net' => $darreichung->calculated_sales_net,
                'old_effective_net' => $oldEffective,
                'new_effective_net' => $darreichung->sales_net,
                'price_mode' => $darreichung->price_mode,
                'source' => $price['base_source'],
                'reason' => $darreichung->price_override_reason,
                'user_id' => $darreichung->price_override_user_id,
                'metadata' => [
                    'base_factor' => $price['base_factor'],
                    'class_factor_pct' => $price['class_factor_pct'],
                    'calculation_version' => $price['calculation_version'],
                ],
            ]);
        }

        if ($darreichung->is_standard) {
            $this->spiegleStandardVk($recipe);
        }
    }

    /** Alle Darreichungen eines Gerichts neu rechnen (nach Zutaten-/EK-Änderung). */
    public function recomputeFuerRezept(int $recipeId): void
    {
        $darreichungen = FoodAlchemistRecipeDarreichung::where('recipe_id', $recipeId)->get();
        if ($darreichungen->isEmpty()) {
            return;
        }
        // Rezept + Zutaten-Relationen EINMAL laden statt je Darreichung neu (N+1).
        $recipe = FoodAlchemistRecipe::with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->find($recipeId);
        foreach ($darreichungen as $d) {
            $this->recomputePreise($d, $recipe);
        }
    }

    /**
     * Standard-Komposition JE EINHEIT: Batch-Massen der Zutaten skaliert auf die
     * Grammatur der Standard-Darreichung (Fallback: ganze Charge = eine Einheit).
     * Referenz für Delta-Editor (Anzeige „Standard (g)") und Delta-Preisrechnung.
     *
     * @return array<int, array{masse_g: float, kosten_pro_g: ?float}>
     */
    public function standardProEinheit(FoodAlchemistRecipe $recipe, ?Team $team = null): array
    {
        $zeilen = $this->recompute->zeilenKostenUndMassen($recipe, $team);
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
            $team = Team::find($recipe->team_id);
            $vatDefaults = $team !== null ? app(TeamSettingsService::class)->mwst($team) : TeamSettingsService::MWST_DEFAULTS;
            $vatKey = in_array($standard->vat_profile_key, ['regulaer', 'ermaessigt'], true)
                ? $standard->vat_profile_key : $vatDefaults['default_satz'];
            DB::table('foodalchemist_recipes')->where('id', $recipe->id)
                ->update([
                    'sales_net' => $standard->sales_net,
                    'sales_gross' => $standard->sales_gross,
                    'vat_rate' => (float) ($vatDefaults[$vatKey] ?? 0),
                ]);
        }
    }

    /** Technische Standardform für alte Gerichte ohne Darreichung selbstheilend bereitstellen. */
    private function unbestimmtId(Team $team): int
    {
        $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->where('code', 'unbestimmt')->first();
        if ($model === null) {
            $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create([
                'team_id' => $team->id,
                'code' => 'unbestimmt',
                'label' => 'Unbestimmt',
            ]);
        }

        return (int) $model->id;
    }

    private function find(Team $team, int $darreichungId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = FoodAlchemistRecipeDarreichung::with('recipe')->findOrFail($darreichungId);
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($darreichung->recipe_id);

        return $darreichung;
    }
}
