<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §12 B · Durchstich Schritt 2: Arbeitsstand und veröffentlichte Fassung sind getrennt.
 *
 * Der Master bearbeitet wie bisher die Arbeitszeile (Spec 65). „Veröffentlichen" friert den ganzen benötigten Baum
 * (Rezept + Unter-Rezepte des Masters) als schreibgeschützte Fassungs-Zeilen ein (ist_fassung = 1). Abonnenten
 * sehen nur Fassungen (AboService-Hülle), nie die Arbeitszeile.
 *
 * - Ein Unter-Rezept, dessen Inhalt zur letzten Fassung passt, wird wiederverwendet (kein Zeilen-Wildwuchs).
 * - Master-Rezepte nutzen nur globale Stamm-GPs (Entscheidung 1 = C) — sonst fehlten Mandanten Bestandteile.
 * - Leck-Sperre (Bauplan §3b): Fassungen tragen keine ek_*, keine Zusatzkosten, keinen VK des Masters; die
 *   Neuberechnung schreibt für Fassungen keine Kosten (RecipeRecomputeService::kosten).
 * - VK-Darreichungen, Kalkulationsprofile und Präsentationen des Masters werden NICHT mitkopiert.
 */
class FassungService
{
    /** Spalten, die eine Fassung nie vom Arbeitsstand erbt. */
    private const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'recipe_key', 'kopie_von_id', 'kopie_stand_at', 'created_at', 'updated_at', 'deleted_at',
        'ek_total_eur', 'ek_per_kg_eur', 'ek_price_basis', 'ek_n_ingredients_priced', 'ek_n_ingredients_total',
        'additional_costs_eur', 'sales_net', 'sales_gross',
        // Preisklasse/Aufschlag gehören dem Master — beim Mandanten greift NUR sein Kalkulationsfaktor (Dominique 13.10.)
        'markup_class_id', 'markup_class_legacy_id',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    /** Abgeleitete Spalten (Recompute) zählen nicht zum Inhalt. */
    private const ABGELEITET_PRAEFIXE = ['allergen', 'additive', 'nutri_', 'spec_', 'ek_', 'yield_kg'];

    /** Eingaben einer Zutatenzeile, die den Inhalt bestimmen (wie RecipeService::duplicate). */
    private const ZUTAT_FELDER = ['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
        'quantity', 'quantity_max', 'unit_vocab_id', 'trimming_loss_pct', 'cooking_loss_pct',
        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
        'role', 'role_source', 'is_value_relevant', 'calc_mode'];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private RecipeRecomputeService $recompute,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * @return array{fassung_id:int, neu:list<int>, wiederverwendet:list<int>, allergen_geaendert:list<int>}
     */
    public function veroeffentlichen(Team $master, int $recipeId, string $notiz, ?User $actor): array
    {
        // Spec 84 B3 C: Lesen im Master; das Recht prüft der Rumpf am übergebenen Team + Nutzer, nicht am Kontext
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($master, fn () => $this->veroeffentlichenImTeam($master, $recipeId, $notiz, $actor));
    }

    private function veroeffentlichenImTeam(Team $master, int $recipeId, string $notiz, ?User $actor): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Veröffentlichen gibt es nur im Master-Team (Spec 82 §12 B).');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Rezept veröffentlichen');
        $notiz = trim($notiz);
        if ($notiz === '') {
            throw new \RuntimeException('Beim Veröffentlichen ist eine Änderungsnotiz Pflicht (Änderungsprotokoll der Abonnenten).');
        }
        $wurzel = FoodAlchemistRecipe::ohneTeamSicht('abo-pflege')->where('team_id', $master->id)->where('ist_fassung', false)->findOrFail($recipeId);

        $baum = $this->baumKinderZuerst($wurzel, (int) $master->id);
        $this->pruefeNurStammGps($baum);

        $ergebnis = DB::transaction(function () use ($baum, $notiz, $actor) {
            $map = [];
            $neu = [];
            $wieder = [];
            $allergen = [];
            foreach ($baum as $recipe) {
                $hash = $this->inhaltHash($recipe, $map);
                $vorher = FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('abo-pflege')->where('fassung_von_id', $recipe->id)->where('ist_fassung', true)
                    ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
                if ($vorher !== null && $vorher->inhalt_hash === $hash) {
                    $map[$recipe->id] = (int) $vorher->id;
                    $wieder[] = (int) $vorher->id;

                    continue;
                }
                // Nummer über ALLE Fassungen der Familie, auch soft-gelöschte: UNIQUE(team_id, recipe_key) zählt sie mit
                $nr = (int) FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('eindeutigkeit')->withTrashed()->where('fassung_von_id', $recipe->id)->where('ist_fassung', true)->max('fassung_nr') + 1;
                $fassung = $this->kopiere($recipe, $map, $nr, $hash, $notiz, $actor);
                $this->recompute->recomputePipeline($fassung->id, false);
                $fassung->refresh();
                if ($vorher !== null && $this->allergenBild($vorher) !== $this->allergenBild($fassung)) {
                    $fassung->forceFill(['fassung_allergen_geaendert' => true])->save();
                    $allergen[] = (int) $fassung->id;
                }
                $map[$recipe->id] = (int) $fassung->id;
                $neu[] = (int) $fassung->id;
            }

            return ['fassung_id' => $map[$baum[array_key_last($baum)]->id], 'neu' => $neu, 'wiederverwendet' => $wieder, 'allergen_geaendert' => $allergen];
        });

        $this->abos->neuBerechnenAlle();   // Bibliothek der Abonnenten zieht auf die neueste Fassung nach
        if ($ergebnis['neu'] !== []) {
            $this->protokoll->schreibe('fassung_veroeffentlicht', ['recipe_id' => $wurzel->id, 'fassung_id' => $ergebnis['fassung_id'],
                'neu' => $ergebnis['neu'], 'allergen_geaendert' => $ergebnis['allergen_geaendert'], 'notiz' => $notiz],
                $actor, FoodAlchemistRecipe::mitFassungen()->find($ergebnis['fassung_id']));
        }

        if ($ergebnis['neu'] !== []) {   // Spec 82 §12 B: Signale „Abo-Rezept aktualisiert“ / „Neuere Fassung verfügbar“ — nie ein Grund, die Veröffentlichung scheitern zu lassen
            try {
                app(FassungSignalService::class)->nachVeroeffentlichung($ergebnis['neu']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: '.$e->getMessage(), ['exception' => $e, 'recipe_id' => $wurzel->id]);
            }
        }

        return $ergebnis;
    }

    /**
     * Rezept + alle Unter-Rezepte des Masters, Kinder vor Eltern (für die Umverdrahtung auf Unter-Fassungen).
     *
     * @return list<FoodAlchemistRecipe>
     */
    private function baumKinderZuerst(FoodAlchemistRecipe $wurzel, int $masterId): array
    {
        $reihenfolge = [];
        $besucht = [];
        $besuche = function (FoodAlchemistRecipe $r, array $pfad) use (&$besuche, &$reihenfolge, &$besucht, $masterId) {
            if (isset($besucht[$r->id])) {
                return;
            }
            if (isset($pfad[$r->id])) {
                throw new \RuntimeException("Zyklus in den Unter-Rezepten bei „{$r->name}“ — so lässt sich nichts veröffentlichen.");
            }
            $pfad[$r->id] = true;
            $kinder = FoodAlchemistRecipe::ohneTeamSicht('abo-pflege')->whereIn('id', $r->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id'))
                ->where('team_id', $masterId)->where('ist_fassung', false)->orderBy('id')->get();
            foreach ($kinder as $kind) {
                $besuche($kind, $pfad);
            }
            $besucht[$r->id] = true;
            $reihenfolge[] = $r;
        };
        $besuche($wurzel, []);

        return $reihenfolge;
    }

    /** @param list<FoodAlchemistRecipe> $baum */
    private function pruefeNurStammGps(array $baum): void
    {
        $eigene = $this->stammVerstoesse($baum);
        if ($eigene !== []) {
            throw new \RuntimeException('Noch nicht veröffentlichbar: Diese GPs gehören dem Master-Team, nicht dem globalen Stamm: '
                . implode(', ', array_slice($eigene, 0, 10)) . (count($eigene) > 10 ? ' …' : '')
                . '. Kein Fehler im Rezept — Veröffentlichen ist nach dem Stamm-Umzug möglich (Spec 82, Entscheidung 1 = C).');
        }
    }

    /**
     * GP-Namen im Baum, die NICHT global (Stamm) sind — Master-Rezepte dürfen nur Stamm-GPs nutzen (E1).
     *
     * @param  list<FoodAlchemistRecipe>  $baum
     * @return list<string>
     */
    private function stammVerstoesse(array $baum): array
    {
        return DB::table('foodalchemist_recipe_ingredients AS z')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 'z.gp_id')
            ->whereIn('z.recipe_id', array_map(fn ($r) => (int) $r->id, $baum))->whereNull('z.deleted_at')->whereNotNull('g.team_id')
            ->distinct()->orderBy('g.name')->pluck('g.name')->all();
    }

    /** @param array<int,int> $map Arbeitszeile → Fassung (Unter-Rezepte) */
    private function inhaltHash(FoodAlchemistRecipe $recipe, array $map): string
    {
        $kopf = collect($recipe->getAttributes())
            ->except(array_merge(['id', 'team_id', 'status', 'version'], self::NIE_KOPIEREN))
            ->reject(fn ($v, $k) => collect(self::ABGELEITET_PRAEFIXE)->contains(fn ($p) => str_starts_with($k, $p)) && $k !== 'yield_kg_manual')
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))
            ->sortKeys()->all();
        $zutaten = $recipe->ingredients()->get()->map(function ($z) use ($map) {
            $felder = $z->only(self::ZUTAT_FELDER);
            if ($felder['referenced_recipe_id'] !== null) {
                $felder['referenced_recipe_id'] = $map[(int) $felder['referenced_recipe_id']] ?? (int) $felder['referenced_recipe_id'];
            }

            return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $felder);
        })->all();
        $schritte = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->orderBy('ebene')->orderBy('position')
            ->get(['ebene', 'position', 'phase', 'text'])->toArray();

        $standard = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $recipe->id)
            ->orderByDesc('is_standard')->orderBy('id')->first();
        $darreichung = $standard === null ? null : [
            $standard->only(['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count', 'container_warm_vocab_id',
                'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'vessel_gp_id']),
            $standard->deltas()->orderBy('recipe_ingredient_id')->get(['recipe_ingredient_id', 'quantity_override_g', 'omitted'])->toArray(),
            // Zubehör & Verpackung wirkt auf den EK der Fassung: eine Änderung ist ein neuer Inhalt
            $standard->zubehoer()->reorder()->orderBy('gp_id')->get(['gp_id', 'quantity'])->toArray(),
        ];

        return hash('sha256', json_encode([$kopf, $zutaten, $schritte, $darreichung], JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int,int> $map */
    private function kopiere(FoodAlchemistRecipe $recipe, array $map, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistRecipe
    {
        $fassung = $recipe->replicate(self::NIE_KOPIEREN);
        $fassung->forceFill([
            'recipe_key' => $recipe->recipe_key . '@f' . $nr,
            'ist_fassung' => true, 'fassung_von_id' => $recipe->id, 'fassung_nr' => $nr, 'inhalt_hash' => $hash,
            'veroeffentlicht_at' => now(), 'veroeffentlicht_von' => $actor?->id, 'aenderungsnotiz' => $notiz,
        ])->save();

        $zutatMap = [];
        foreach ($recipe->ingredients()->get() as $z) {
            $felder = $z->only(self::ZUTAT_FELDER);
            if ($felder['referenced_recipe_id'] !== null && isset($map[(int) $felder['referenced_recipe_id']])) {
                $felder['referenced_recipe_id'] = $map[(int) $felder['referenced_recipe_id']];
            }
            $zutatMap[(int) $z->id] = (int) $fassung->ingredients()->create([...$felder, 'team_id' => $recipe->team_id])->id;
        }
        $this->kopiereStandardDarreichung($recipe, $fassung, $zutatMap);
        foreach (FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->get() as $s) {
            $s->replicate(['uuid'])->forceFill(['recipe_id' => $fassung->id])->save();
        }
        foreach (DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $recipe->id)->get() as $e) {
            $zeile = (array) $e;
            unset($zeile['id']);
            DB::table('foodalchemist_recipe_equipment')->insert([...$zeile, 'recipe_id' => $fassung->id]);
        }

        return $fassung;
    }

    /**
     * Lese-Status für die Oberfläche „Veröffentlichen…" (Spec 82 §13 B6) — rechnet NICHTS um und schreibt nichts.
     * Gleiche Hash-Logik wie veroeffentlichen(): `weicht_ab` ist genau dann wahr, wenn Veröffentlichen eine neue Fassung
     * erzeugen würde (am Rezept oder an einem Unter-Rezept).
     *
     * @return array{letzte: ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string}, weicht_ab: bool,
     *               neu_im_baum: list<string>, allergen_aenderung: bool, allergen_felder: list<string>, stamm_verstoesse: list<string>}
     */
    public function status(FoodAlchemistRecipe $arbeitszeile): array
    {
        $baum = $this->baumKinderZuerst($arbeitszeile, (int) $arbeitszeile->team_id);
        $map = [];
        $neu = [];
        foreach ($baum as $r) {
            $vorher = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $r->id)->where('ist_fassung', true)
                ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
            if ($vorher !== null && $vorher->inhalt_hash === $this->inhaltHash($r, $map)) {
                $map[$r->id] = (int) $vorher->id;
            } else {
                $map[$r->id] = -1 * (int) $r->id;   // Platzhalter „wird neu" — ändert den Hash der Eltern wie eine neue Fassung
                $neu[] = (string) $r->name;
            }
        }
        $letzte = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $arbeitszeile->id)->where('ist_fassung', true)
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        $felder = [];
        if ($letzte !== null) {
            $a = $this->allergenBild($arbeitszeile);
            $b = $this->allergenBild($letzte);
            foreach ($a as $k => $v) {
                if (($b[$k] ?? null) !== $v) {
                    $felder[] = $k;
                }
            }
        }

        return [
            'letzte' => $letzte === null ? null : ['id' => (int) $letzte->id, 'nr' => (int) $letzte->fassung_nr,
                'veroeffentlicht_at' => $letzte->veroeffentlicht_at ? \Illuminate\Support\Carbon::parse($letzte->veroeffentlicht_at)->format('d.m.Y H:i') : null, 'notiz' => $letzte->aenderungsnotiz],
            'weicht_ab' => $neu !== [],
            'neu_im_baum' => $neu,
            'allergen_aenderung' => $felder !== [],
            'allergen_felder' => $felder,
            'stamm_verstoesse' => $this->stammVerstoesse($baum),
        ];
    }

    /** Darreichungs-Felder, die nie mitgehen: Preise, Preisklasse, Kalkulations-Stand des Masters. */
    private const DARREICHUNG_NIE = ['uuid', 'legacy_id', 'recipe_id', 'ek_portion', 'markup_class_id', 'sales_net', 'calculated_sales_net',
        'sales_gross', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at', 'price_override_reason',
        'price_override_user_id', 'price_override_at', 'price_override_expires_at', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Spec 82 §8 / §12 C (Dominique 13.10.): Die Fassung trägt die STANDARD-Darreichung mit — Form, Grammatur, Anzahl,
     * Behälter, Deltas — aber ohne Master-Preise und ohne Master-Preisklasse (price_mode auto). Der VK des Mandanten wird
     * daraus berechnet (TeamVkService: sein EK × sein Kalkulationsfaktor), nicht gepflegt. Weitere Darreichungen gehen
     * nur mit, wenn andere abonnierte Inhalte (Paket, Konzept, Menü) sie referenzieren — im Durchstich werden nur
     * Rezepte abonniert, also nur die Standard-Darreichung.
     *
     * @param  array<int,int>  $zutatMap  Zutat Arbeitszeile → Zutat Fassung (Deltas hängen an der Zutat)
     */
    private function kopiereStandardDarreichung(FoodAlchemistRecipe $recipe, FoodAlchemistRecipe $fassung, array $zutatMap): void
    {
        $standard = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $recipe->id)
            ->orderByDesc('is_standard')->orderBy('id')->first();
        if ($standard === null) {
            return;
        }
        $kopie = $standard->replicate(self::DARREICHUNG_NIE);
        $kopie->forceFill(['recipe_id' => $fassung->id, 'is_standard' => true, 'price_mode' => 'auto'])->save();
        foreach ($standard->deltas()->get() as $d) {
            if (! isset($zutatMap[(int) $d->recipe_ingredient_id])) {
                continue;
            }
            $d->replicate(['uuid', 'legacy_id'])->forceFill(['presentation_id' => $kopie->id,
                'recipe_ingredient_id' => $zutatMap[(int) $d->recipe_ingredient_id]])->save();
        }
        foreach ($standard->zubehoer()->get() as $z) {
            $z->replicate(['uuid'])->forceFill(['presentation_id' => $kopie->id])->save();   // Non-Food-GP bleibt dasselbe, es gibt nichts umzumappen
        }
    }

    /**
     * Allergen- und Zusatzstoff-Bild einer Rezept-Zeile (Vergleich zweier Fassungen). Öffentlich für FassungsVergleich (Schritt 4).
     *
     * @return array<string, mixed>
     */
    public function allergenBild(FoodAlchemistRecipe $r): array
    {
        return collect($r->getAttributes())
            ->filter(fn ($v, $k) => (str_starts_with($k, 'allergen_') || str_starts_with($k, 'additive_'))
                && ! str_ends_with($k, '_at') && ! str_contains($k, 'confidence'))
            ->sortKeys()->all();
    }
}
