<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

/**
 * Sensorik-Auswertung für ein Concept — App-Port der Vault-Logik 232 (balance/textur).
 * Aggregiert über die GPs der Concept-Gerichte die Geschmacks-Vektoren + Texturen
 * (foodalchemist_gp_geschmack_vektor / _textur, via Sync 246 aus dem Vault gespiegelt).
 *
 * Logik wie 232: MAX je Geschmacks-Dimension („vorhanden?") → Dominanz (≥0.6) / Lücke (<0.3);
 * Textur-Monotonie (überwiegend weich, kein Crunch) → Kontrast-Vorschläge aus dem GP-Bestand.
 * Transparent über die Coverage (nicht jeder GP hat schon einen Vektor).
 */
class SensorikService
{
    public const DIMS = ['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'];

    public const DIM_LABEL = [
        'suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter',
        'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf',
    ];

    /** Texturen, die „weich/cremig" zählen (Monotonie-Erkennung). */
    private const WEICH = ['cremig', 'weich', 'mousse', 'pastoes', 'fluessig', 'gel', 'pueree', 'schaumig', 'saftig'];

    private const KNUSPRIG = ['knusprig', 'koernig', 'schnittfest'];

    /** GP-IDs aus Rezepten (deren Zutaten-GPs + 1 Ebene Sub-Rezepte). */
    private function gpIdsFromRecipes(array $recipeIds): array
    {
        $recipeIds = array_values(array_filter(array_map('intval', $recipeIds)));
        if ($recipeIds === []) {
            return [];
        }
        $ing = DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')
            ->whereIn('recipe_id', $recipeIds)->get(['gp_id', 'referenced_recipe_id']);
        $gpIds = $ing->pluck('gp_id')->filter()->all();
        $subRecipeIds = $ing->pluck('referenced_recipe_id')->filter()->unique()->all();
        if ($subRecipeIds !== []) {
            $sub = DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')
                ->whereIn('recipe_id', $subRecipeIds)->pluck('gp_id')->filter()->all();
            $gpIds = array_merge($gpIds, $sub);
        }

        return array_values(array_unique(array_map('intval', $gpIds)));
    }

    /** Sensorik eines einzelnen Rezepts/Gerichts (über dessen Zutaten-GPs). */
    public function fuerRezept(int $recipeId): array
    {
        return $this->auswertung($this->gpIdsFromRecipes([$recipeId]));
    }

    /** Sensorik eines einzelnen Grundprodukts (eigener Vektor + Textur). */
    public function fuerGp(int $gpId): array
    {
        return $this->auswertung([(int) $gpId]);
    }

    /**
     * @return array{leer: bool, abdeckung?: array{mit:int, gesamt:int}, geschmack?: array, dominant?: list<string>,
     *               luecken?: list<string>, textur?: list<array{slug:string, label:string}>, monotonie?: ?string,
     *               vorschlaege?: list<array{dim:string, gps:list<string>}>}
     */
    public function fuerConcept(FoodAlchemistConcept $concept): array
    {
        $recipeIds = $concept->slots->pluck('vk_recipe_id')->filter()->unique()->values()->all();

        return $this->auswertung($this->gpIdsFromRecipes($recipeIds));
    }

    /** Aggregierte Sensorik-Auswertung über eine GP-Menge (MAX je Dimension, wie 232). */
    private function auswertung(array $gpIds): array
    {
        if ($gpIds === []) {
            return ['leer' => true];
        }

        // ── Geschmack: MAX je Dimension ──
        $sel = implode(', ', array_map(fn ($d) => "MAX($d) AS $d", self::DIMS));
        $row = DB::table('foodalchemist_gp_geschmack_vektor')->whereIn('gp_id', $gpIds)
            ->selectRaw($sel . ', COUNT(*) AS n')->first();
        $mitVektor = (int) ($row->n ?? 0);

        $geschmack = [];
        foreach (self::DIMS as $d) {
            $geschmack[$d] = round((float) ($row->{$d} ?? 0), 2);
        }
        $dominant = array_keys(array_filter($geschmack, fn ($v) => $v >= 0.6));
        $luecken = array_keys(array_filter($geschmack, fn ($v) => $v < 0.3));

        // ── Textur: vorhandene Slugs ──
        $texRows = DB::table('foodalchemist_gp_textur AS t')
            ->join('foodalchemist_vocab_textur AS v', 'v.id', '=', 't.textur_vocab_id')
            ->whereIn('t.gp_id', $gpIds)
            ->selectRaw('v.slug, v.display_de, MAX(t.intensitaet) AS intensitaet')
            ->groupBy('v.slug', 'v.display_de')->get();
        $textur = $texRows->sortByDesc('intensitaet')
            ->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->display_de])->values()->all();
        $slugs = array_column($textur, 'slug');

        $weichN = count(array_intersect($slugs, self::WEICH));
        $hatCrunch = count(array_intersect($slugs, self::KNUSPRIG)) > 0;
        $monotonie = ($weichN >= 2 && ! $hatCrunch)
            ? 'Überwiegend weich/cremig, kein knuspriger Kontrast — ein Crunch-Element würde den Teller heben.'
            : null;

        // ── Kontrast-Vorschläge für die größte Lücke (GPs stark in der Lücke, nicht im Teller) ──
        $vorschlaege = [];
        $groessteLuecke = $luecken !== [] ? $luecken[array_search(min(array_intersect_key($geschmack, array_flip($luecken))), $geschmack, true)] ?? $luecken[0] : null;
        if ($groessteLuecke !== null) {
            $kand = DB::table('foodalchemist_gp_geschmack_vektor AS gv')
                ->join('foodalchemist_gps AS g', 'g.id', '=', 'gv.gp_id')
                ->where("gv.$groessteLuecke", '>=', 0.6)
                ->whereNotIn('gv.gp_id', $gpIds)
                ->orderByDesc("gv.$groessteLuecke")
                ->limit(40)->pluck('g.name');
            $gesehen = [];
            $gps = [];
            foreach ($kand as $name) {
                $token = mb_strtolower(preg_split('/[\s:,]/', trim((string) $name))[0] ?? '');
                if ($token === '' || isset($gesehen[$token])) {
                    continue;
                }
                $gesehen[$token] = true;
                $gps[] = (string) $name;
                if (count($gps) >= 4) {
                    break;
                }
            }
            if ($gps !== []) {
                $vorschlaege[] = ['dim' => $groessteLuecke, 'gps' => $gps];
            }
        }

        return [
            'leer' => false,
            'abdeckung' => ['mit' => $mitVektor, 'gesamt' => count($gpIds)],
            'geschmack' => $geschmack,
            'dominant' => $dominant,
            'luecken' => $luecken,
            'textur' => $textur,
            'monotonie' => $monotonie,
            'vorschlaege' => $vorschlaege,
        ];
    }
}
