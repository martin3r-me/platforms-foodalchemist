<?php

namespace Platform\FoodAlchemist\Services\Fassung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;

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
class FassungService extends FassungBasis
{
    /** Spalten, die eine Fassung nie vom Arbeitsstand erbt. */
    protected const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'recipe_key', 'kopie_von_id', 'kopie_stand_at', 'created_at', 'updated_at', 'deleted_at',
        'ek_total_eur', 'ek_per_kg_eur', 'ek_price_basis', 'ek_n_ingredients_priced', 'ek_n_ingredients_total',
        'additional_costs_eur',
        // VK und Preisklasse stehen an der Darreichung (Spec 85 D-1); für die Darreichung gilt DARREICHUNG_NIE.
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    /** Abgeleitete Spalten (Recompute) zählen nicht zum Inhalt. */
    private const ABGELEITET_PRAEFIXE = ['allergen', 'additive', 'nutri_', 'spec_', 'ek_', 'yield_kg'];

    /** Eingaben einer Zutatenzeile, die den Inhalt bestimmen (wie RecipeService::duplicate). */
    private const ZUTAT_FELDER = ['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
        'quantity', 'quantity_max', 'unit_vocab_id', 'trimming_loss_pct', 'cooking_loss_pct', 'zeilenart',
        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
        'role', 'role_source', 'is_value_relevant', 'calc_mode'];

    public function __construct(
        \Platform\FoodAlchemist\Services\FaRechte $rechte,
        \Platform\FoodAlchemist\Services\Plattform\AboService $abos,
        private \Platform\FoodAlchemist\Services\Basis\NeuberechnungRezepte $recompute,   // 98 · 3.3 S7 P4: nur die Neuberechnung, nicht die Klasse
        \Platform\FoodAlchemist\Services\Plattform\PlattformProtokoll $protokoll,
    ) {
        parent::__construct($rechte, $abos, $protokoll);
    }

    protected function modell(): string
    {
        return FoodAlchemistRecipe::class;
    }

    protected function rechtText(): string
    {
        return 'Rezept veröffentlichen';
    }

    /** @return array{fassung_id:int, neu:list<int>, wiederverwendet:list<int>, allergen_geaendert:list<int>} */
    protected function veroeffentlichenRumpf(Team $master, int $recipeId, string $notiz, ?User $actor): array
    {
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
                $vorher = $this->letzteFassung((int) $recipe->id, sichtGrund: 'abo-pflege');
                if ($vorher !== null && $vorher->inhalt_hash === $hash) {
                    $map[$recipe->id] = (int) $vorher->id;
                    $wieder[] = (int) $vorher->id;

                    continue;
                }
                // Nummer über ALLE Fassungen der Familie, auch soft-gelöschte: UNIQUE(team_id, recipe_key) zählt sie mit
                $nr = $this->naechsteNr((int) $recipe->id, 'eindeutigkeit');
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

        // Bibliothek der Abonnenten zieht immer nach; Protokoll und Signale („Abo-Rezept aktualisiert“ / „Neuere Fassung verfügbar“) nur bei neuen Fassungen
        $neu = $ergebnis['neu'] !== [];
        $this->nachVeroeffentlichung($neu ? ['fassung_veroeffentlicht', ['recipe_id' => $wurzel->id, 'fassung_id' => $ergebnis['fassung_id'],
            'neu' => $ergebnis['neu'], 'allergen_geaendert' => $ergebnis['allergen_geaendert'], 'notiz' => $notiz]] : null,
            $actor, $neu ? FoodAlchemistRecipe::mitFassungen()->find($ergebnis['fassung_id']) : null,
            fn () => $neu ? app(FassungSignalService::class)->nachVeroeffentlichung($ergebnis['neu']) : null,
            'Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: ', ['recipe_id' => $wurzel->id]);

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
        $this->abbruchMitListe('Noch nicht veröffentlichbar: Diese GPs gehören dem Master-Team, nicht dem globalen Stamm: ', $this->stammVerstoesse($baum),
            '. Kein Fehler im Rezept — Veröffentlichen ist nach dem Stamm-Umzug möglich (Spec 82, Entscheidung 1 = C).');
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
        $kopf = $this->hashKopf($recipe, ['id', 'team_id', 'status', 'version'],
            fn ($v, $k) => collect(self::ABGELEITET_PRAEFIXE)->contains(fn ($p) => str_starts_with($k, $p)) && $k !== 'yield_kg_manual');
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
                'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'vessel_gp_id'])
                // Kostenart des Gefäßes (17.11.2026) nur, wenn gesetzt — sonst änderte sich der Hash jeder bestehenden Fassung
                + ($standard->vessel_cost_type !== null ? ['vessel_cost_type' => $standard->vessel_cost_type] : []) + $standard->inhaltErgaenzung()
                // Zubehör am Gefäß-GP (20.11.2026, Deckel am Glas): geht in EK, Logistik und Handbuch — nur wenn vorhanden, sonst bleibt jeder alte Hash
                + (($zubGef = $this->gefaessZubehoerFuerHash($standard)) !== [] ? ['gefaess_zubehoer' => $zubGef] : []),
            $standard->deltas()->orderBy('recipe_ingredient_id')->get(['recipe_ingredient_id', 'quantity_override_g', 'omitted'])->toArray(),
            // Zubehör & Verpackung wirkt auf den EK der Fassung: eine Änderung ist ein neuer Inhalt
            $standard->zubehoer()->reorder()->orderBy('gp_id')->get(['gp_id', 'quantity'])->toArray(),
        ];

        return hash('sha256', json_encode([$kopf, $zutaten, $schritte, $darreichung], JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{0:int, 1:float}> Zubehör am Gefäß der Darreichung (wirksam fürs Team des Gerichts), sortiert — für den Inhalts-Hash */
    private function gefaessZubehoerFuerHash(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung $d): array
    {
        if ($d->vessel_gp_id === null) {
            return [];
        }
        $zeilen = array_map(fn ($z) => [$z['gp_id'], $z['menge']],
            app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->liste((int) $d->vessel_gp_id, \Platform\Core\Models\Team::find($d->team_id))['zeilen']);
        sort($zeilen);

        return $zeilen;
    }

    /** @param array<int,int> $map */
    private function kopiere(FoodAlchemistRecipe $recipe, array $map, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistRecipe
    {
        $fassung = $this->fassungsKopf($recipe, $nr, $hash, $notiz, $actor, ['recipe_key' => $recipe->recipe_key . '@f' . $nr]);

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
            $vorher = $this->letzteFassung((int) $r->id, false);
            if ($vorher !== null && $vorher->inhalt_hash === $this->inhaltHash($r, $map)) {
                $map[$r->id] = (int) $vorher->id;
            } else {
                $map[$r->id] = -1 * (int) $r->id;   // Platzhalter „wird neu" — ändert den Hash der Eltern wie eine neue Fassung
                $neu[] = (string) $r->name;
            }
        }
        $letzte = $this->letzteFassung((int) $arbeitszeile->id, false);
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
            'letzte' => $this->statusLetzte($letzte, fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y H:i') : null),
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
    /**
     * 98 · 3.5 · 6c: Inhalts-Hash einer Arbeitszeile wie beim Veröffentlichen — Unter-Rezepte zeigen auf ihre jüngste Fassung.
     * Für den Rehash nach einem Spalten-Drop: passt dieser Hash zur jüngsten Fassung, war die Arbeitszeile unverändert.
     */
    public function inhaltHashFuer(FoodAlchemistRecipe $recipe): string
    {
        $map = [];
        foreach ($recipe->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id') as $kind) {
            $f = $this->letzteFassung((int) $kind, true, 'abo-pflege');
            if ($f !== null) {
                $map[(int) $kind] = (int) $f->id;
            }
        }

        return $this->inhaltHash($recipe, $map);
    }

    public function allergenBild(FoodAlchemistRecipe $r): array
    {
        return collect($r->getAttributes())
            ->filter(fn ($v, $k) => (str_starts_with($k, 'allergen_') || str_starts_with($k, 'additive_'))
                && ! str_ends_with($k, '_at') && ! str_contains($k, 'confidence'))
            ->sortKeys()->all();
    }
}
