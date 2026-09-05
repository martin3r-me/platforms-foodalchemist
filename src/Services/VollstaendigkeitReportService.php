<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 50 · Etappe 0 — Messbericht VOLLSTÄNDIGKEIT. **Read-only**, schreibt nichts.
 *
 * Dieselbe Reihenfolge wie Etappe 22·H2 und {@see MoneyTruthReportService}: „Messung zuerst,
 * Umbau danach — die Messzahl entscheidet, ob das ein stiller Dauerfehler oder ein Randfall
 * ist." Die Pakete A–C der Spec ändern Schrittfolgen und Erzeugungspfade; ohne diese Zahlen
 * VORHER ist hinterher nicht belegbar, dass sie gewirkt haben.
 *
 * Kein Signal, keine Zeitreihe, keine `bulk_runs`-Zeile — eine Messung für einen Menschen.
 * Wird eine Kennzahl später zur Ampel, gehört sie in {@see DataQualityService}, dann aber mit
 * dem dortigen Prädikat statt als zweite Wahrheit.
 *
 * Fünf Blöcke:
 *  A · VK-Vorbedingungen — was den Auto-VK blockiert (Aufschlagsklasse, Portion, Darreichung).
 *  B · Anreicherung — welche Zielfelder der Schrittfolgen leer sind, getrennt nach Basisrezept
 *      und Gericht, plus die beiden Felder, die in KEINER Schrittfolge stehen
 *      (`work_time_min` → FEK=0, `dichteklasse` → Behälterbedarf nicht rechenbar).
 *  C · Grundprodukte — die Felder ohne LA-Quelle (Anker, Domain) UND der A8-Schaden:
 *      GPs, die `allergens_source='ki'` tragen, OBWOHL ihre LAs ein Allergenprofil haben —
 *      die sind aus der LA-Kaskade ausgeschert ({@see GpAggregateService::backfillAllergenKonfidenz}
 *      überspringt sie).
 *  D · Concept-Struktur — Concepts ohne eine einzige gerenderte Überschrift. Der Renderer
 *      verlangt `type ∈ header|header_preis` UND nicht-leeren `title`
 *      ({@see WordingResolver}); `role` allein erzeugt keine Zeile.
 *  E · KI-Kosten — Calls je Feature mit Tokens und Übernahmequote. Das ist die Basis für den
 *      Vor/Nach-Vergleich der Entscheidung „automatisch vollständig anreichern".
 *
 * Scoping wie im Money-Bericht: `visibleToTeam` (global + Ancestry). Ein Datensatz kann damit
 * in mehreren Teams gezählt werden — für einen Vor/Nach-Vergleich je Team ist das richtig,
 * für eine Bestandssumme über alle Teams nicht.
 */
class VollstaendigkeitReportService
{
    /** Zielfelder der Basisrezept-Schrittfolge ({@see BulkEnrichService::SCHRITTE}). */
    private const FELDER_BASIS = [
        'description' => 'description',
        'category' => 'category_id',
        'geschmack' => 'taste_direction',
    ];

    /** Zielfelder der Gericht-Schrittfolge ({@see BulkEnrichService::SCHRITTE_VK}). */
    private const FELDER_VK = [
        'description' => 'description',
        'wording' => 'sales_wording_standard',
        'plating' => 'plating_text',
        'speisen_klasse' => 'dish_class_id',
    ];

    /**
     * Felder, die in KEINER Schrittfolge stehen, aber eine Kette tragen.
     * `work_time_min` → `KalkulationService::recipeHk` rechnet FEK/FGK = 0.
     * `dichteklasse` → `BehaelterBedarfService` kann den Bedarf nicht rechnen (Spec 51).
     */
    private const FELDER_OHNE_SCHRITT = ['work_time_min', 'dichteklasse'];

    /**
     * @return array{team_id:int, a_vk_vorbedingungen:array, b_anreicherung:array,
     *               c_grundprodukte:array, d_concept_struktur:array, e_ki_kosten:array}
     */
    public function messe(Team $team, int $limit = 5): array
    {
        return [
            'team_id' => (int) $team->id,
            'a_vk_vorbedingungen' => $this->blockVkVorbedingungen($team, $limit),
            'b_anreicherung' => $this->blockAnreicherung($team),
            'c_grundprodukte' => $this->blockGrundprodukte($team, $limit),
            'd_concept_struktur' => $this->blockConceptStruktur($team, $limit),
            'e_ki_kosten' => $this->blockKiKosten($team),
        ];
    }

    // ── A · VK-Vorbedingungen ────────────────────────────────────────────────────────────

    private function blockVkVorbedingungen(Team $team, int $limit): array
    {
        $gesamt = $this->gerichte($team)->count();

        $ohneKlasse = (clone $this->gerichte($team))->whereNull('markup_class_id')->count();
        $ohnePortionAmRezept = (clone $this->gerichte($team))->whereNull('sales_quantity_per_unit_g')->count();

        $ohneDarreichung = (clone $this->gerichte($team))
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_recipe_presentations AS p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('p.deleted_at'))
            ->count();

        $ohneStandard = (clone $this->gerichte($team))
            ->whereExists(fn ($q) => $q->from('foodalchemist_recipe_presentations AS p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('p.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_recipe_presentations AS p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('p.deleted_at')->where('p.is_standard', true))
            ->count();

        // `unbestimmt` ist laut DarreichungService ein REVIEW-Zustand, kein Label: die Form
        // steht, die Servierform ist noch nicht entschieden.
        $unbestimmt = (clone $this->gerichte($team))
            ->whereExists(fn ($q) => $q->from('foodalchemist_recipe_presentations AS p')
                ->join('foodalchemist_serving_forms AS f', 'f.id', '=', 'p.serving_form_id')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('p.deleted_at')->where('p.is_standard', true)
                ->where('f.code', 'unbestimmt'))
            ->count();

        $ohneVk = (clone $this->gerichte($team))
            ->where(fn ($q) => $q->whereNull('sales_net')->orWhere('sales_net', '<=', 0))
            ->count();

        $beispiele = (clone $this->gerichte($team))
            ->whereNull('markup_class_id')
            ->orderBy('id')->limit($limit)
            ->get(['id', 'name', 'markup_class_id', 'sales_quantity_per_unit_g', 'sales_net'])
            ->map(fn ($r) => [
                'recipe_id' => (int) $r->id,
                'name' => (string) $r->name,
                'portion_g' => $r->sales_quantity_per_unit_g !== null ? (float) $r->sales_quantity_per_unit_g : null,
                'vk_netto_eur' => $r->sales_net !== null ? (float) $r->sales_net : null,
            ])->all();

        return [
            'vk_gerichte' => $gesamt,
            'ohne_aufschlagsklasse' => $ohneKlasse,
            'ohne_portion_am_rezept' => $ohnePortionAmRezept,
            'ohne_darreichung' => $ohneDarreichung,
            'darreichung_ohne_standard' => $ohneStandard,
            'standard_auf_unbestimmt' => $unbestimmt,
            'ohne_vk' => $ohneVk,
            'beispiele_ohne_klasse' => $beispiele,
        ];
    }

    // ── B · Anreicherung ────────────────────────────────────────────────────────────────

    private function blockAnreicherung(Team $team): array
    {
        $basis = ['gesamt' => $this->basisrezepte($team)->count()];
        foreach (self::FELDER_BASIS as $schritt => $feld) {
            $basis["leer_{$schritt}"] = (clone $this->basisrezepte($team))->whereNull($feld)->count();
        }

        $vk = ['gesamt' => $this->gerichte($team)->count()];
        foreach (self::FELDER_VK as $schritt => $feld) {
            $vk["leer_{$schritt}"] = (clone $this->gerichte($team))->whereNull($feld)->count();
        }

        $ohneSchritt = [];
        foreach (self::FELDER_OHNE_SCHRITT as $feld) {
            $ohneSchritt["basisrezept_{$feld}_leer"] = (clone $this->basisrezepte($team))->whereNull($feld)->count();
            $ohneSchritt["gericht_{$feld}_leer"] = (clone $this->gerichte($team))->whereNull($feld)->count();
        }

        // Coverage-Glieder: was `completeCoverage` füllen würde, gemessen über die Satelliten.
        $coverage = [
            'ohne_steps' => $this->ohneSatellit($team, 'foodalchemist_recipe_steps'),
            'ohne_sensorik' => $this->ohneSatellit($team, 'foodalchemist_recipe_taste_vectors'),
            'ohne_aromaanker' => $this->ohneSatellit($team, 'foodalchemist_recipe_anchor_mappings'),
            'ohne_pairings' => $this->ohneSatellit($team, 'foodalchemist_recipe_pairings'),
            'ohne_equipment' => $this->ohneSatellit($team, 'foodalchemist_recipe_equipment'),
        ];

        return ['basisrezept' => $basis, 'gericht' => $vk, 'ohne_schrittfolge' => $ohneSchritt, 'coverage' => $coverage];
    }

    /** Rezepte (beide Ebenen) ohne eine einzige Zeile in der Satelliten-Tabelle. */
    private function ohneSatellit(Team $team, string $tabelle): int
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->whereNotExists(fn ($q) => $q->from("{$tabelle} AS s")
                ->whereColumn('s.recipe_id', 'foodalchemist_recipes.id'))
            ->count();
    }

    // ── C · Grundprodukte ───────────────────────────────────────────────────────────────

    private function blockGrundprodukte(Team $team, int $limit): array
    {
        $gesamt = FoodAlchemistGp::visibleToTeam($team)->count();
        $tentative = FoodAlchemistGp::visibleToTeam($team)->where('status', 'tentative')->count();

        $ohneAnker = FoodAlchemistGp::visibleToTeam($team)
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_gp_anchor_mappings AS m')
                ->whereColumn('m.gp_id', 'foodalchemist_gps.id'))
            ->count();

        $ohneDomain = FoodAlchemistGp::visibleToTeam($team)->whereNull('food_domain_source')->count();

        // A8-Schaden: `allergens_source='ki'` schirmt den GP dauerhaft von der LA-Kaskade ab
        // (backfillAllergenKonfidenz:124 überspringt manual|ki). Kritisch ist nur der Fall MIT
        // LA-Allergenprofil — ohne LA-Daten ist der KI-Wert der dokumentierte Fallback.
        $abgeschirmt = FoodAlchemistGp::visibleToTeam($team)
            ->where('allergens_source', 'ki')
            ->whereExists(fn ($q) => $q->from('foodalchemist_item_allergens AS a')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'a.supplier_item_id')
                ->whereColumn('s.gp_id', 'foodalchemist_gps.id')
                ->whereNull('s.deleted_at')
                ->where(function ($w) {
                    foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                        $w->orWhereNotNull("a.allergen_{$feld}");
                    }
                }));

        $beispiele = (clone $abgeschirmt)->orderBy('id')->limit($limit)->get(['id', 'name'])
            ->map(fn ($g) => ['gp_id' => (int) $g->id, 'name' => (string) $g->name])->all();

        return [
            'gps' => $gesamt,
            'tentative' => $tentative,
            'ohne_aromaanker' => $ohneAnker,
            'ohne_food_domain' => $ohneDomain,
            'ki_abgeschirmt_trotz_la_profil' => $abgeschirmt->count(),
            'beispiele_abgeschirmt' => $beispiele,
        ];
    }

    // ── D · Concept-Struktur ────────────────────────────────────────────────────────────

    private function blockConceptStruktur(Team $team, int $limit): array
    {
        $gesamt = FoodAlchemistConcept::visibleToTeam($team)->count();

        $ohneHeader = FoodAlchemistConcept::visibleToTeam($team)
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_concept_slots AS s')
                ->whereColumn('s.concept_id', 'foodalchemist_concepts.id')
                ->whereNull('s.deleted_at')
                ->whereIn('s.type', ['header', 'header_preis'])
                ->whereNotNull('s.title')
                ->where('s.title', '!=', ''));

        // Der Gegenbeweis zur Vermutung „die Gliederung fehlt": Slots mit `role`, aber ohne
        // Header — die Struktur IST da, sie kommt nur nicht im Kundendokument an.
        $mitRolleOhneHeader = (clone $ohneHeader)
            ->whereExists(fn ($q) => $q->from('foodalchemist_concept_slots AS s')
                ->whereColumn('s.concept_id', 'foodalchemist_concepts.id')
                ->whereNull('s.deleted_at')
                ->whereNotNull('s.role')
                ->where('s.role', '!=', ''))
            ->count();

        $beispiele = (clone $ohneHeader)->orderByDesc('id')->limit($limit)->get(['id', 'name'])
            ->map(fn ($c) => ['concept_id' => (int) $c->id, 'name' => (string) $c->name])->all();

        return [
            'concepts' => $gesamt,
            'ohne_gerenderten_header' => $ohneHeader->count(),
            'davon_mit_rolle_statt_header' => $mitRolleOhneHeader,
            'beispiele' => $beispiele,
        ];
    }

    // ── E · KI-Kosten ───────────────────────────────────────────────────────────────────

    private function blockKiKosten(Team $team): array
    {
        $zeilen = DB::table('foodalchemist_ai_call_log')
            ->where('team_id', $team->id)
            ->selectRaw('feature, COUNT(*) AS calls, COALESCE(SUM(tokens_in),0) AS tok_in, '
                .'COALESCE(SUM(tokens_out),0) AS tok_out, '
                .'SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS angenommen, '
                .'SUM(CASE WHEN rejected_at IS NOT NULL THEN 1 ELSE 0 END) AS verworfen')
            ->groupBy('feature')
            ->orderByDesc('calls')
            ->get();

        $jeFeature = $zeilen->map(fn ($z) => [
            'feature' => (string) $z->feature,
            'calls' => (int) $z->calls,
            'tokens' => (int) $z->tok_in + (int) $z->tok_out,
            'angenommen' => (int) $z->angenommen,
            'verworfen' => (int) $z->verworfen,
            // Offen = weder angenommen noch verworfen. Bei Feldern ohne Freigabe-Schritt ist
            // das der Normalfall, nicht ein Rückstand — die Quote ist nur dort aussagekräftig,
            // wo ein Accept-Pfad existiert.
            'offen' => (int) $z->calls - (int) $z->angenommen - (int) $z->verworfen,
        ])->all();

        return [
            'calls_gesamt' => array_sum(array_column($jeFeature, 'calls')),
            'tokens_gesamt' => array_sum(array_column($jeFeature, 'tokens')),
            'je_feature' => $jeFeature,
        ];
    }

    // ── Helfer ──────────────────────────────────────────────────────────────────────────

    private function gerichte(Team $team): Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true);
    }

    private function basisrezepte(Team $team): Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false);
    }
}
