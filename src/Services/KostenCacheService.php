<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 82 §12 C / Stufe 3b · Durchstich Schritt 3: Kosten je (Rezept-Zeile × Team).
 *
 * Lesen ({@see fuer}):
 *  - Besitzer-Team einer ARBEITSZEILE → die Spalten am Rezept (heutiger Wert, bitgleich; dort rechnet der Recompute
 *    mit genau diesen Preisen und Pins).
 *  - jedes andere Team (Unter-Team auf geerbtem Rezept, Mandant auf Abo-Fassung) → Cache-Zeile; fehlt sie oder ist sie
 *    veraltet, wird sie jetzt gerechnet (bei Bedarf, nicht für alle Rezepte × Teams vorgehalten, §3).
 *  - Eine Fassung hat NIE einen Besitzer-Wert (Leck-Sperre): auch der Master liest sie aus dem Cache.
 *
 * Auslöser-Matrix (§12 C2) — markieren nur „veraltet", rechnen nicht sofort:
 *  - Teampreis eines Teams T → Cache-Zeilen von T und seinen Unter-Teams für Rezepte, die den Artikel (über seinen GP)
 *    nutzen, samt Eltern-Rezepten.
 *  - Listenpreis (Besitzer bzw. global) → dieselben Rezepte für ALLE Teams.
 *  - Pin/Lock eines Teams T am GP → wie Teampreis, nur dieser GP.
 *  - Neue Fassung → nichts: neue Zeile = neuer Schlüssel; eingefrorene Ausgaben behalten ihre Zeile.
 *
 * Ergebnis-Form siehe {@see leer}. `vollstaendig = false` heißt: ek_summe ist eine TEILSUMME („ab x €") und darf nie als
 * vollständiger Preis weiterverwendet werden (Auflage 4).
 */
class KostenCacheService
{
    public function __construct(private RecipeRecomputeService $recompute) {}

    /** @return array{quelle:string, ek_summe:?float, ek_per_kg:?float, n_priced:int, n_total:int, anteil_fehlend_anzahl:?float, anteil_fehlend_masse:?float, vollstaendig:bool, grund:?string, fehlende:list<array<string,mixed>>, berechnet_at:?string, basis:?string} */
    public function fuer(FoodAlchemistRecipe $recipe, Team $team): array
    {
        // Aufrufer laden oft nur Teil-Spalten (z. B. Angebot: id, sales_net, ek_total_eur) — ohne team_id/ist_fassung
        // wäre die Besitzer-Entscheidung falsch. Dann einmal vollständig nachladen.
        foreach (['team_id', 'ist_fassung', 'ek_total_eur', 'ek_per_kg_eur', 'ek_n_ingredients_total', 'ek_n_ingredients_priced', 'ek_price_basis'] as $spalte) {
            if (! array_key_exists($spalte, $recipe->getAttributes())) {
                $recipe = FoodAlchemistRecipe::mitFassungen()->findOrFail($recipe->id);
                break;
            }
        }
        if (! $recipe->ist_fassung && (int) $recipe->team_id === (int) $team->id) {
            $n = (int) $recipe->ek_n_ingredients_total;
            $p = (int) $recipe->ek_n_ingredients_priced;
            // Ohne Zähler (Altstand, nie neu gerechnet) gilt ein vorhandener EK als vollständig; mit Zählern zählt priced == total
            $voll = $recipe->ek_n_ingredients_total === null ? $recipe->ek_total_eur !== null : ($n > 0 && $p === $n);

            return [
                'quelle' => 'rezept', 'ek_summe' => $recipe->ek_total_eur !== null ? (float) $recipe->ek_total_eur : null,
                'ek_per_kg' => $recipe->ek_per_kg_eur !== null ? (float) $recipe->ek_per_kg_eur : null,
                'n_priced' => $p, 'n_total' => $n,
                'anteil_fehlend_anzahl' => $n > 0 ? round(($n - $p) / $n, 4) : null, 'anteil_fehlend_masse' => null,
                'vollstaendig' => $voll, 'grund' => $voll ? null : 'zutaten_ohne_preis',
                'fehlende' => [], 'berechnet_at' => null, 'basis' => $recipe->ek_price_basis?->value,
            ];
        }

        $zeile = DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $recipe->id)->where('team_id', $team->id)->first();
        if ($zeile === null || $zeile->veraltet) {
            $this->rechne((int) $recipe->id, $team);
            $zeile = DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $recipe->id)->where('team_id', $team->id)->first();
        }

        return $this->ausZeile($zeile);
    }

    /** Rechnet Rezept + Unter-Rezepte für das Team und speichert jede Zeile (geteilte Basisrezepte einmal). */
    public function rechne(int $recipeId, Team $team): void
    {
        $memo = [];
        $this->recompute->kostenFuerTeam($recipeId, $team, $memo);
        $jetzt = now();
        foreach ($memo as $id => $k) {
            DB::table('foodalchemist_recipe_team_kosten')->updateOrInsert(
                ['recipe_id' => $id, 'team_id' => $team->id],
                [
                    'ek_summe_eur' => $k['ek_summe'], 'ek_per_kg_eur' => $k['ek_per_kg'],
                    'n_priced' => $k['n_priced'], 'n_total' => $k['n_total'],
                    'masse_g_total' => $k['masse_total'], 'masse_g_fehlend' => $k['masse_fehlend'],
                    'price_basis' => $k['basis']?->value, 'vollstaendig' => $k['vollstaendig'], 'unvollstaendig_grund' => $k['grund'],
                    'fehlende_zutaten' => json_encode($k['fehlende'], JSON_UNESCAPED_UNICODE),
                    'preisstichtag' => $jetzt, 'berechnet_at' => $jetzt, 'veraltet' => false,
                    'created_at' => $jetzt, 'updated_at' => $jetzt,
                ],
            );
        }
    }

    // ── Auslöser ──

    /**
     * Preisänderung an einem Artikel durch ein Team. Besitzer eines team-eigenen Artikels = Listenpreis → alle Teams;
     * sonst (auch jeder Preis an einem globalen Artikel) Teampreis → das Team und seine Unter-Teams.
     */
    public function nachPreisAenderung(FoodAlchemistSupplierItem $item, Team $team): int
    {
        $gpIds = DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $item->id)->whereNull('deleted_at')
            ->pluck('gp_id')->map(fn ($v) => (int) $v)->all();
        // Listenpreis gibt es nur am team-eigenen Artikel durch seinen Besitzer; an einem globalen Stamm-Artikel ist jeder
        // Preis ein Teampreis (der Stamm trägt keine Preise, Entscheidung 13.10.)
        $listenpreis = $item->team_id !== null && (int) $item->team_id === (int) $team->id;
        app(DarreichungService::class)->recomputeFuerZubehoerGps($gpIds);   // Zubehör (Non-Food) an Darreichungen: gezielt neu rechnen

        return $this->markiereVeraltet($this->rezepteMitGps($gpIds), $listenpreis ? null : RecomputeTeamRecipesJob::teamUndNachfahren((int) $team->id));
    }

    /** Pin/Lock eines Teams an einem GP (Lead-Overlay) → nur dieses Team und seine Unter-Teams. */
    public function nachPinAenderung(int $gpId, Team $team): int
    {
        app(DarreichungService::class)->recomputeFuerZubehoerGps([$gpId]);
        return $this->markiereVeraltet($this->rezepteMitGps([$gpId]), RecomputeTeamRecipesJob::teamUndNachfahren((int) $team->id));
    }

    /**
     * @param  list<int>  $recipeIds
     * @param  list<int>|null  $teamIds  null = alle Teams
     */
    public function markiereVeraltet(array $recipeIds, ?array $teamIds): int
    {
        if ($recipeIds === []) {
            return 0;
        }
        $q = DB::table('foodalchemist_recipe_team_kosten')->whereIn('recipe_id', $recipeIds);
        if ($teamIds !== null) {
            $q->whereIn('team_id', $teamIds);
        }

        return $q->update(['veraltet' => true, 'updated_at' => now()]);
    }

    /**
     * Rezepte (Arbeits- UND Fassungs-Zeilen), die einen der GPs direkt nutzen, plus alle Eltern über Unter-Rezept-Verweise.
     * Roh über die Zutaten-Tabelle — bewusst ohne Model-Scope, Fassungen gehören dazu.
     *
     * @param  list<int>  $gpIds
     * @return list<int>
     */
    public function rezepteMitGps(array $gpIds): array
    {
        if ($gpIds === []) {
            return [];
        }
        $offen = DB::table('foodalchemist_recipe_ingredients')->whereIn('gp_id', $gpIds)->whereNull('deleted_at')
            ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
        $alle = [];
        while ($offen !== []) {
            $offen = array_values(array_diff(array_unique($offen), $alle));
            $alle = array_merge($alle, $offen);
            $offen = $offen === [] ? [] : DB::table('foodalchemist_recipe_ingredients')->whereIn('referenced_recipe_id', $offen)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
        }
        sort($alle);

        return $alle;
    }

    /** @return array{quelle:string, ek_summe:?float, ek_per_kg:?float, n_priced:int, n_total:int, anteil_fehlend_anzahl:?float, anteil_fehlend_masse:?float, vollstaendig:bool, grund:?string, fehlende:list<array<string,mixed>>, berechnet_at:?string, basis:?string} */
    private function ausZeile(object $z): array
    {
        $n = (int) $z->n_total;
        $masse = $z->masse_g_total !== null ? (float) $z->masse_g_total : 0.0;

        return [
            'quelle' => 'team_cache',
            'ek_summe' => $z->ek_summe_eur !== null ? (float) $z->ek_summe_eur : null,
            'ek_per_kg' => $z->ek_per_kg_eur !== null ? (float) $z->ek_per_kg_eur : null,
            'n_priced' => (int) $z->n_priced, 'n_total' => $n,
            'anteil_fehlend_anzahl' => $n > 0 ? round(($n - (int) $z->n_priced) / $n, 4) : null,
            'anteil_fehlend_masse' => $masse > 0 ? round(((float) $z->masse_g_fehlend) / $masse, 4) : null,
            'vollstaendig' => (bool) $z->vollstaendig, 'grund' => $z->unvollstaendig_grund,
            'fehlende' => json_decode((string) ($z->fehlende_zutaten ?? '[]'), true) ?: [],
            'berechnet_at' => $z->berechnet_at, 'basis' => $z->price_basis,
        ];
    }
}
