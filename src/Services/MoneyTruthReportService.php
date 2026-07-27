<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 22 · H2a — die MESSUNG vor dem Umbau der Geld-Wahrheiten.
 *
 * Der Etappen-Text von 22·H2 verlangt die Reihenfolge ausdrücklich: „Messung zuerst,
 * Umbau danach … die Messzahl entscheidet, ob das ein stiller Dauerfehler oder ein
 * Randfall ist." Dieser Service ist genau diese Messung und **schreibt nichts** —
 * keine Signale, keine Zeitreihe, keine Lauf-Zeile. Er beantwortet drei Fragen:
 *
 *  A · `sales_unit_count` (V-041): wie viele Gerichte tragen > 1, und wie weit liegen
 *      die zwei Lesarten der Spalte auseinander? Gemessen wird die **Wareneinsatz-Quote**
 *      auf beiden Wegen: die heutige Divisions-Lesart aus {@see KalkulationService::recipeHk}
 *      (`ek_total / sales_unit_count` gegen VK) und die mengenkonsistente Lesart der
 *      Darreichung (`ek_portion` gegen `sales_net` derselben Zeile). Die Differenz ist
 *      der Betrag, um den `wareneinsatz_ueber_ziel` heute zu selten meldet.
 *
 *  B · Preis-Wahrheit (V-046-Nachmessung): wie oft weichen Darreichungs- und Rezept-Preis
 *      voneinander ab, wie oft fällt die Leiter auf die Legacy-Spalte, und wie oft trägt
 *      ein Gericht Darreichungen **ohne** `is_standard` (die in V-059 benannte Rest-Divergenz
 *      zwischen geladenem und ungeladenem Weg).
 *
 *  C · Lead-LA-Preiszeilen (V-053): wie viele GPs gelten der Datenqualitäts-Ampel als
 *      versorgt, weil ihr Prädikat laxer ist als das des Money-Paths? Drei Fassungen
 *      derselben Frage, absichtlich getrennt gezählt: die **laxe** der Ampel
 *      (`price > 0`, nicht gesperrt, nicht gelöscht), die **strenge** des Money-Paths
 *      ({@see PriceService::scopeAktiv} — zusätzlich `status IN ('0','2')`) und die
 *      **Gültigkeits**-Fassung (zusätzlich unbefristet oder `valid_to` in der Zukunft).
 *      Die zwei Deltas sind die Antwort auf die offene `valid_to`-Frage der Etappe.
 *
 * **Warum die Beispiele mitkommen:** eine nackte Zahl beantwortet „Randfall oder
 * Dauerfehler" nur halb — erst eine benennbare Rezept-/GP-ID macht sie nachprüfbar.
 * Deshalb liefert jeder Block bis zu `$limit` konkrete Zeilen mit den Zahlen, an denen
 * die Divergenz hängt.
 */
class MoneyTruthReportService
{
    /** Ab dieser Differenz gilt eine Quote als abweichend (Rundungs-Rauschen unter 0,1 pp). */
    private const QUOTE_EPSILON_PP = 0.1;

    /** Ab dieser Differenz gelten zwei Geldbeträge als ungleich (halber Cent). */
    private const GELD_EPSILON = 0.005;

    public function __construct(private PriceService $prices) {}

    /**
     * @return array{team_id: int, a_sales_unit_count: array, b_preis_wahrheit: array, c_lead_la_preis: array}
     */
    public function messe(Team $team, int $limit = 5): array
    {
        return [
            'team_id' => (int) $team->id,
            'a_sales_unit_count' => $this->blockSalesUnitCount($team, $limit),
            'b_preis_wahrheit' => $this->blockPreisWahrheit($team, $limit),
            'c_lead_la_preis' => $this->blockLeadLaPreis($team, $limit),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A · V-041 — die zwei Lesarten von sales_unit_count
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Beide Wareneinsatz-Quoten je VK-Gericht, getrennt nach `sales_unit_count`-Lage.
     *
     * Die Divisions-Lesart wird **absichtlich nicht** über `KalkulationService::recipeHk`
     * geholt: die Messung will die rohe Divergenz der zwei Zahlen zeigen, nicht sie durch
     * den HK2-Wasserfall (Zuschlag, Lohn, Nebenkosten) hindurchschicken — sonst mischen
     * sich Team-Settings in eine Aussage über eine Spalte. Beide Quoten sind hier reine
     * Wareneinsatz-Quoten (HK1 gegen VK), also dieselbe Größe wie
     * `recipeHk()['wareneinsatz_pct']`, nur ohne die Wasserfall-Anteile.
     */
    private function blockSalesUnitCount(Team $team, int $limit): array
    {
        $gesamt = $this->vkGerichte($team)->count();

        $verteilung = [
            'unit_count_null' => (clone $this->vkGerichte($team))->whereNull('sales_unit_count')->count(),
            'unit_count_le_1' => (clone $this->vkGerichte($team))->where('sales_unit_count', '<=', 1)->count(),
            'unit_count_gt_1' => (clone $this->vkGerichte($team))->where('sales_unit_count', '>', 1)->count(),
        ];

        // Vergleichbar sind nur Gerichte, bei denen BEIDE Rechnungen eine Zahl ergeben:
        // Charge-EK + Standard-Darreichung mit eigenem EK und eigenem VK.
        $kandidaten = $this->vkGerichte($team)
            ->with('standardPresentation')
            ->whereNotNull('ek_total_eur')
            ->where('ek_total_eur', '>', 0)
            ->get();

        $vergleichbar = 0;
        $abweichend = ['unit_count_gt_1' => 0, 'unit_count_le_1' => 0];
        $faktoren = [];
        $beispiele = [];

        foreach ($kandidaten as $recipe) {
            $standard = $recipe->standardPresentation;
            $vk = $standard?->sales_net !== null ? (float) $standard->sales_net : null;
            $ekPortion = $standard?->ek_portion !== null ? (float) $standard->ek_portion : null;

            if ($vk === null || $vk <= 0 || $ekPortion === null) {
                continue;
            }

            $anzahl = max(1.0, (float) ($recipe->sales_unit_count ?? 1));
            $wDivision = (float) $recipe->ek_total_eur / $anzahl / $vk * 100;
            $wDarreichung = $ekPortion / $vk * 100;

            $vergleichbar++;
            $delta = abs($wDivision - $wDarreichung);
            if ($delta < self::QUOTE_EPSILON_PP) {
                continue;
            }

            $lage = ((float) ($recipe->sales_unit_count ?? 1)) > 1 ? 'unit_count_gt_1' : 'unit_count_le_1';
            $abweichend[$lage]++;
            $faktor = $wDivision > 0 ? round($wDarreichung / $wDivision, 2) : null;
            if ($faktor !== null) {
                $faktoren[] = $faktor;
            }

            if (count($beispiele) < $limit) {
                $beispiele[] = [
                    'recipe_id' => (int) $recipe->id,
                    'name' => (string) $recipe->name,
                    'sales_unit_count' => $recipe->sales_unit_count !== null ? (float) $recipe->sales_unit_count : null,
                    'ek_total_eur' => round((float) $recipe->ek_total_eur, 4),
                    'ek_portion_eur' => round($ekPortion, 4),
                    'vk_netto_eur' => round($vk, 2),
                    'w_pct_division' => round($wDivision, 1),
                    'w_pct_darreichung' => round($wDarreichung, 1),
                    'delta_pp' => round($delta, 1),
                    'faktor' => $faktor,
                ];
            }
        }

        sort($faktoren);

        return [
            'vk_gerichte' => $gesamt,
            'verteilung' => $verteilung,
            'vergleichbar' => $vergleichbar,
            'abweichend' => $abweichend,
            'abweichend_gesamt' => $abweichend['unit_count_gt_1'] + $abweichend['unit_count_le_1'],
            'faktor_min' => $faktoren === [] ? null : $faktoren[0],
            'faktor_median' => $this->median($faktoren),
            'faktor_max' => $faktoren === [] ? null : $faktoren[count($faktoren) - 1],
            'beispiele' => $beispiele,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B · V-046/V-059 — welche Zahl die Preis-Leiter wirklich liefert
    // ─────────────────────────────────────────────────────────────────────────

    private function blockPreisWahrheit(Team $team, int $limit): array
    {
        $gerichte = $this->vkGerichte($team)->with('standardPresentation')->get();

        $mitStandard = 0;
        $ohneDarreichung = 0;
        $darreichungenOhneStandard = 0;
        $preisDivergenz = 0;
        $nurLegacy = 0;
        $keinPreis = 0;
        $beispiele = [];

        // Eine Query für alle: hat das Gericht überhaupt Darreichungs-Zeilen?
        $mitZeilen = DB::table('foodalchemist_recipe_presentations')
            ->whereNull('deleted_at')
            ->whereIn('recipe_id', $gerichte->pluck('id')->all())
            ->distinct()
            ->pluck('recipe_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $mitZeilen = array_fill_keys($mitZeilen, true);

        foreach ($gerichte as $recipe) {
            $standard = $recipe->standardPresentation;
            $hatZeilen = isset($mitZeilen[(int) $recipe->id]);

            if ($standard !== null) {
                $mitStandard++;
            } elseif (! $hatZeilen) {
                $ohneDarreichung++;
            } else {
                // V-059: Zeilen ja, `is_standard` nein → geladener und ungeladener Weg
                // der Preis-Leiter antworten unterschiedlich.
                $darreichungenOhneStandard++;
            }

            $vkStandard = $standard?->sales_net !== null ? (float) $standard->sales_net : null;
            $vkLegacy = $recipe->sales_net !== null ? (float) $recipe->sales_net : null;

            if ($vkStandard === null && $vkLegacy === null) {
                $keinPreis++;

                continue;
            }

            if ($vkStandard === null) {
                $nurLegacy++;

                continue;
            }

            if ($vkLegacy !== null && abs($vkStandard - $vkLegacy) > self::GELD_EPSILON) {
                $preisDivergenz++;
                if (count($beispiele) < $limit) {
                    $beispiele[] = [
                        'recipe_id' => (int) $recipe->id,
                        'name' => (string) $recipe->name,
                        'vk_darreichung_eur' => round($vkStandard, 2),
                        'vk_legacy_eur' => round($vkLegacy, 2),
                        'delta_eur' => round($vkStandard - $vkLegacy, 2),
                    ];
                }
            }
        }

        return [
            'vk_gerichte' => $gerichte->count(),
            'mit_standard_darreichung' => $mitStandard,
            'ohne_darreichung' => $ohneDarreichung,
            'darreichungen_ohne_standard' => $darreichungenOhneStandard,
            'preis_divergenz' => $preisDivergenz,
            'nur_legacy_preis' => $nurLegacy,
            'kein_preis' => $keinPreis,
            'beispiele' => $beispiele,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C · V-053 — drei Fassungen von „aktiver Preis" am Lead-LA
    // ─────────────────────────────────────────────────────────────────────────

    private function blockLeadLaPreis(Team $team, int $limit): array
    {
        $basis = fn () => FoodAlchemistGp::visibleToTeam($team)->whereNotNull('lead_la_supplier_item_id');

        $mitLead = $basis()->count();
        $lax = $basis()->whereExists($this->laxeFassung())->count();
        $streng = $basis()->whereExists($this->strengeFassung())->count();
        $gueltig = $basis()->whereExists($this->gueltigkeitsFassung())->count();

        // Die zwei Lagen, um die es der Etappe geht: was die Ampel heute grün meldet,
        // obwohl der Money-Path nichts findet.
        $nurStatusfremd = $basis()
            ->whereExists($this->laxeFassung())
            ->whereNotExists($this->strengeFassung())
            ->get(['id', 'name', 'lead_la_supplier_item_id'])
            ->take($limit);

        $nurAbgelaufen = $basis()
            ->whereExists($this->strengeFassung())
            ->whereNotExists($this->gueltigkeitsFassung())
            ->get(['id', 'name', 'lead_la_supplier_item_id'])
            ->take($limit);

        return [
            'gps_mit_lead' => $mitLead,
            'lax_erfuellt' => $lax,
            'streng_erfuellt' => $streng,
            'gueltig_erfuellt' => $gueltig,
            'delta_lax_streng' => $lax - $streng,
            'delta_streng_gueltig' => $streng - $gueltig,
            'beispiele_nur_statusfremd' => $this->gpBeispiele($nurStatusfremd),
            'beispiele_nur_abgelaufen' => $this->gpBeispiele($nurAbgelaufen),
        ];
    }

    /**
     * Die Fassung, die `DataQualityService::aktivPreisFuerLead()` heute benutzt —
     * bewusst als Kopie ihres Prädikats, denn sie ist der Vergleichs-Gegenstand.
     * (`deleted_at IS NULL` kommt hier aus dem SoftDeletes-Scope des Models statt
     * wie dort per Hand — dieselbe Bedingung, ein Weg weniger.)
     */
    private function laxeFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->korreliert(FoodAlchemistPrice::query())
            ->where('price', '>', 0)
            ->where('is_blocked', false);
    }

    /**
     * Die Fassung des Money-Paths — `PriceService::scopeAktiv` selbst, nicht nachgebaut.
     * Genau darum geht es in V-053: das Prädikat darf nicht ein drittes Mal geschrieben werden.
     */
    private function strengeFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->prices->scopeAktiv($this->korreliert(FoodAlchemistPrice::query()));
    }

    /** Strenge Fassung + die offene `valid_to`-Frage: unbefristet oder noch gültig. */
    private function gueltigkeitsFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->strengeFassung()->where(function ($w) {
            $w->whereNull('foodalchemist_prices.valid_to')
                ->orWhere('foodalchemist_prices.valid_to', '>=', now());
        });
    }

    /** Die EXISTS-Korrelation Preis-Zeile ↔ Lead-LA des GP — an einer Stelle. */
    private function korreliert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->select(DB::raw(1))
            ->whereColumn('foodalchemist_prices.supplier_item_id', 'foodalchemist_gps.lead_la_supplier_item_id');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function vkGerichte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true);
    }

    /** @param \Illuminate\Support\Collection<int, FoodAlchemistGp> $gps */
    private function gpBeispiele($gps): array
    {
        return $gps->map(fn ($gp) => [
            'gp_id' => (int) $gp->id,
            'name' => (string) $gp->name,
            'lead_la_supplier_item_id' => (int) $gp->lead_la_supplier_item_id,
        ])->values()->all();
    }

    /** @param list<float> $werte aufsteigend sortiert */
    private function median(array $werte): ?float
    {
        $n = count($werte);
        if ($n === 0) {
            return null;
        }

        return $n % 2 === 1
            ? round($werte[intdiv($n, 2)], 2)
            : round(($werte[$n / 2 - 1] + $werte[$n / 2]) / 2, 2);
    }
}
