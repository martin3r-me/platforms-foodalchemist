<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * 12·S2a-1 (R2.4) — der Kandidaten-Pool als GETEILTE Naht.
 *
 * Bis hierher lagen Pool-Aufbau, Slot-Filter und Filter-Beschreibung privat im
 * `ConceptGeneratorService`. Der Marge-Solver (R2.4) soll laut Spec 12 §2 genau
 * diesen Pool reutzen — nicht eine zweite Auswahl-Wahrheit danebenstellen. Also
 * ist die Logik hierher gewandert; der Generator delegiert (Verhalten unverändert).
 *
 * Kern-Invariante bleibt: der Pool enthält AUSSCHLIESSLICH echte VK-Gerichte des
 * Teams (kein draft, keine Slot-Variante). Wer daraus wählt — Generator, Weg-B-
 * Vorschlag oder Solver — kann nichts erfinden, weil im Pool nichts Erfundenes steht.
 *
 * Drei opt-in Achsen, damit der billige Pfad billig bleibt (jede kostet eigene Joins):
 *   · `$mitBegriffe`      Namens-/Zutaten-Korpus für No-Go-Zutat-Regeln (implizit aus dem Frame)
 *   · `$mitConvenience`   GP-Tags bis ins Basisrezept für die Convenience-Leitplanke
 *   · `$mitWirtschaft`    Standard-Darreichung → DB je Portion (die R2.4-Zielfunktion)
 */
class MenuCandidatePoolService
{
    public function __construct(
        private PairingService $pairing,
        private DarreichungResolver $darreichungen,
    ) {}

    /**
     * Pool echter VK-Gerichte (keine Drafts, keine Slot-Varianten) mit allem, was
     * Filter + Ranking brauchen: diet_form, Preis, Allergen-Werte, Begriffs-Korpus
     * (nur wenn No-Go-Zutat-Regeln existieren), Anker-IDs (persistiertes Mapping +
     * dynamische Auflösung über die Zutaten), optional die Wirtschaftlichkeit.
     *
     * @return Collection<int, array<string,mixed>> keyBy recipe id
     */
    public function fuerFrame(Team $team, FoodAlchemistPlanningFrame $frame, bool $mitConvenience = false, bool $mitWirtschaft = false): Collection
    {
        $brauchtBegriffe = $frame->rules->where('rule_type', 'nogo_ingredient')->isNotEmpty();

        $query = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->where('status', '!=', 'draft')
            // Modell A: HG hängt direkt am Recipe (dish_main_group_id); dishClass.mainGroup = Alt-Pfad-Fallback
            // levelSuitabilities = Niveau-Eignungen (haute_cuisine|gehoben|klassisch) fürs Segment-Ranking (Phase 5)
            ->with(['dishClass:id,diet_form,dish_main_group_id', 'dishClass.mainGroup:id,code,label', 'speisenHauptgruppe:id,code,label', 'levelSuitabilities']);
        if ($brauchtBegriffe || $mitConvenience) {
            // Convenience-Ranking (Leitplanke) braucht das GP-Tag tag_is_convenience je Zutat.
            $gpSel = 'id,name' . ($mitConvenience ? ',tag_is_convenience' : '');
            $query->with(["ingredients.gp:{$gpSel}"]);
            if ($mitConvenience) {
                // Bis ins Basisrezept: GPs der referenzierten Sub-Rezepte (2 Ebenen tief = 3 Rezept-
                // Ebenen mit dem Gericht) für die rekursive Convenience-Quote. referencedRecipe
                // unrestricted → name bleibt für die Begriffe-Suche verfügbar.
                $query->with([
                    "ingredients.referencedRecipe.ingredients.gp:{$gpSel}",
                    "ingredients.referencedRecipe.ingredients.referencedRecipe.ingredients.gp:{$gpSel}",
                ]);
            } elseif ($brauchtBegriffe) {
                $query->with(['ingredients.referencedRecipe:id,name']);
            }
        }
        // V-046: die Standard-Darreichung ist IMMER eager — nicht nur im Wirtschafts-Modus.
        // Sie ist die Preis-Wahrheit (M2), und der Preis wird im billigen Pfad als HARTER
        // Slot-Filter benutzt; ohne sie filterte der Pool auf der Legacy-Spalte und der
        // Solver optimierte auf der Darreichungs-Zahl. EINE Relation für den ganzen Pool
        // statt `standardFuer()` je Gericht — die Perf-Vorlage aus MargeImpactService.
        $query->with(['standardPresentation:id,recipe_id,sales_net,ek_portion,is_standard']);

        $kandidaten = $query->get();

        // V-045 (zweiter Halbschritt): die Anker-Auflösung EINMAL für den ganzen Pool.
        // Je Gericht gerufen kostete sie die Mapping-Lookups je Zutat (bei 1.000 Gerichten
        // × ~12 Zutaten rund 12.000 Einzel-Queries) — die dominante Ebene, die der erste
        // Halbschritt nicht erwischt hat. Die Auswahl-Logik selbst bleibt im PairingService
        // (eine Auflösungs-Wahrheit), abgeflacht wird über dessen `flacheAnker`.
        $ankerJeGericht = $this->pairing->resolveRecipeAnchorsMany($kandidaten);

        return $kandidaten->map(function (FoodAlchemistRecipe $r) use ($brauchtBegriffe, $mitConvenience, $mitWirtschaft, $ankerJeGericht) {
            $allergene = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $key) {
                $allergene[$key] = $r->{'allergen_' . $key} ?? null;
            }
            $diet = $r->dishClass?->diet_form;
            if ($diet === null || $diet === 'neutral') {
                if ($r->spec_is_vegan === true) {
                    $diet = 'vegan';
                } elseif ($r->spec_is_vegetarian === true) {
                    $diet = $diet ?? 'vegi';
                }
            }

            // V-046: EINE Preis-Zahl je Kandidat, aus der geteilten Leiter im Resolver
            // (Standard-Darreichung → Legacy-Spalte). Sie speist BEIDES: den harten
            // Slot-Preisfilter/das Preis-Anker-Ranking und die Wirtschafts-Achse.
            $preis = $this->darreichungen->vkNettoMitQuelle($r);

            return [
                'id' => $r->id,
                'name' => $r->name,
                'diet_form' => $diet,
                'hg_label' => mb_strtolower(trim((string) ($r->speisenHauptgruppe?->label ?? $r->dishClass?->mainGroup?->label ?? ''))),
                // auf Cent gerundet wie die Wirtschafts-Achse — sonst wären „dieselbe Zahl"
                // und „dieselbe Zahl bis auf Rundung" zwei verschiedene Zusicherungen
                'sales_net' => $preis['vk'] !== null ? round($preis['vk'], 2) : null,
                'preis_quelle' => $preis['quelle'],
                'allergene' => $allergene,
                'begriffe' => $brauchtBegriffe
                    ? mb_strtolower($r->name . ' ' . $r->ingredients->map(fn ($z) => ($z->gp?->name ?? '') . ' ' . ($z->referencedRecipe?->name ?? ''))->implode(' '))
                    : mb_strtolower($r->name),
                'niveaus' => $r->levelSuitabilities->pluck('level_slug')->filter()->values()->all(),
                // Convenience-Anteil = Quote convenience-getaggter GPs unter den Zutaten (null = nicht geladen / keine GP-Zutat)
                'convenience_ratio' => $this->convenienceRatio($r, $mitConvenience),
                'anker' => $this->pairing->flacheAnker($ankerJeGericht[$r->id] ?? []),
                'wirtschaft' => $mitWirtschaft ? $this->wirtschaft($r, $preis['vk']) : null,
            ];
        })->keyBy('id');
    }

    /**
     * Die R2.4-Zielfunktion je Kandidat: Deckungsbeitrag der EINEN verkauften Portion.
     *
     * **Bewusst die Wareneinsatz-Achse, nicht die Vollkosten-Achse.** `KalkulationService::recipeHk`
     * rechnet `db_eur` gegen HK2 (Wareneinsatz + Lohn-Zuschlag) und braucht dafür je Gericht die
     * Team-Settings + einen Resolver-Treffer. Der Solver nimmt stattdessen dasselbe Zahlenpaar wie
     * das L8-Wirtschaftlichkeits-Glied und die W%-Ampel: `ek_portion` gegen `sales_net`, beide an
     * der Standard-Darreichung, dieselbe Menge auf beiden Seiten. Eine Zahl, mehrere Anzeigen —
     * ein Solver, der ein anderes DB rechnet als die Ampel daneben anzeigt, ist nicht erklärbar.
     * Der pauschale HK2-Zuschlag ist ohnehin team-weit und verschiebt die Rangfolge nur dort, wo
     * die Wareneinsatz-Quoten schon weit auseinanderliegen.
     *
     * Preis-Wahrheit ist die Standard-Darreichung (M2), Legacy-Spalten sind Fallback — dieselbe
     * Leiter wie in `recipeHk`. **Seit V-046 wird der VK nicht mehr hier gerechnet, sondern
     * hereingereicht:** es ist dieselbe Zahl, die auch als `sales_net` im Kandidaten steht und
     * die der harte Slot-Filter liest. Zwei Preise im selben Array wären genau der Zustand, den
     * V-046 beschreibt. Fehlt eine der beiden Zahlen, ist der Kandidat `vollstaendig=false`:
     * er fliegt NICHT raus (das wäre eine stille Portfolio-Verengung), aber er trägt kein DB bei
     * und der Solver muss ihn als benannte Lücke ausweisen.
     *
     * @return array{sales_net: ?float, ek_portion: ?float, db_eur: ?float, db_pct: ?float,
     *               wareneinsatz_pct: ?float, quelle: string, vollstaendig: bool}
     */
    private function wirtschaft(FoodAlchemistRecipe $r, ?float $vk): array
    {
        $standard = $r->relationLoaded('standardPresentation') ? $r->standardPresentation : null;

        $anzahl = max(1, (int) ($r->sales_unit_count ?? 1));
        $ek = $standard?->ek_portion !== null ? (float) $standard->ek_portion
            : ($r->ek_total_eur !== null ? (float) $r->ek_total_eur / $anzahl : null);

        $quelle = match (true) {
            $standard?->sales_net !== null && $standard?->ek_portion !== null => 'darreichung',
            $standard?->sales_net !== null || $standard?->ek_portion !== null => 'gemischt',
            default => 'legacy',
        };

        if ($vk === null || $vk <= 0 || $ek === null) {
            return [
                'sales_net' => $vk, 'ek_portion' => $ek, 'db_eur' => null, 'db_pct' => null,
                'wareneinsatz_pct' => null, 'quelle' => $quelle, 'vollstaendig' => false,
            ];
        }

        return [
            'sales_net' => round($vk, 2),
            'ek_portion' => round($ek, 4),
            'db_eur' => round($vk - $ek, 2),
            'db_pct' => round(($vk - $ek) / $vk * 100, 1),
            'wareneinsatz_pct' => round($ek / $vk * 100, 1),
            'quelle' => $quelle,
            'vollstaendig' => true,
        ];
    }

    /**
     * Convenience-Anteil eines Gerichts: Quote der GPs mit tag_is_convenience (0..1) über die
     * GANZE Komposition — direkte GP-Zutaten UND die GPs in den referenzierten Basisrezepten
     * (rekursiv bis Tiefe 3, Regelwerk-Basisrezepte §4). So wirkt die Convenience-Leitplanke bis
     * ins Basisrezept: ein Gericht, das über ein Convenience-Basisrezept convenience ist, wird
     * erkannt. null = GP-Tags nicht geladen ($mitConvenience=false) oder keine GP im Baum → neutral.
     */
    private function convenienceRatio(FoodAlchemistRecipe $r, bool $mitConvenience): ?float
    {
        if (! $mitConvenience || ! $r->relationLoaded('ingredients')) {
            return null;
        }
        $gps = $this->alleGpsImBaum($r);
        if ($gps->isEmpty()) {
            return null;
        }

        return round($gps->filter(fn ($g) => (bool) $g->tag_is_convenience)->count() / $gps->count(), 3);
    }

    /**
     * Alle GPs der Kompositions-Tiefe sammeln: direkte GP-Zutaten + GPs referenzierter
     * Basisrezepte (rekursiv). Nur GELADENE Relationen (kein Lazy-Load → kein N+1); Tiefe
     * durch das Eager-Loading in fuerFrame begrenzt (max. 3 Rezept-Ebenen).
     *
     * @return \Illuminate\Support\Collection<int, \Platform\FoodAlchemist\Models\FoodAlchemistGp>
     */
    private function alleGpsImBaum(FoodAlchemistRecipe $r, int $tiefe = 0): Collection
    {
        if ($tiefe > 3 || ! $r->relationLoaded('ingredients')) {
            return collect();
        }
        $gps = $r->ingredients->map(fn ($z) => $z->gp)->filter();
        foreach ($r->ingredients as $z) {
            if ($z->relationLoaded('referencedRecipe') && $z->referencedRecipe !== null) {
                $gps = $gps->merge($this->alleGpsImBaum($z->referencedRecipe, $tiefe + 1));
            }
        }

        return $gps;
    }

    /** Harte Filter eines Slots: No-Gos (frame + slot, hart), Allergen-No-Gos, Preisrahmen. */
    public function filterFuerSlot(Collection $pool, FoodAlchemistPlanningFrame $frame, $frameSlot): Collection
    {
        $regeln = $frame->rules->whereNull('slot_id')->merge($frameSlot->rules);
        $nogoTerms = $regeln->where('rule_type', 'nogo_ingredient')
            ->map(fn ($r) => mb_strtolower(trim((string) $r->value_text)))->filter()->values();
        $nogoAllergene = $regeln->where('rule_type', 'nogo_allergen')->pluck('ref_key')->filter()->values();

        return $pool->filter(function ($k) use ($nogoTerms, $nogoAllergene, $frameSlot) {
            foreach ($nogoTerms as $term) {
                if (str_contains($k['begriffe'], $term)) {
                    return false; // No-Gos wirken HART im Generator — nie vorschlagen
                }
            }
            foreach ($nogoAllergene as $key) {
                if (in_array($k['allergene'][$key] ?? null, ['enthalten', 'spuren'], true)) {
                    return false;
                }
            }
            if ($frameSlot->price_min !== null && ($k['sales_net'] === null || $k['sales_net'] < (float) $frameSlot->price_min)) {
                return false;
            }
            if ($frameSlot->price_max !== null && ($k['sales_net'] === null || $k['sales_net'] > (float) $frameSlot->price_max)) {
                return false;
            }

            return true;
        });
    }

    /** Menschlich lesbare Filter-Zusammenfassung für Leer-Begründungen. */
    public function filterBeschreibung(FoodAlchemistPlanningFrame $frame, $frameSlot): string
    {
        $teile = [];
        $regeln = $frame->rules->whereNull('slot_id')->merge($frameSlot->rules);
        $nogos = $regeln->where('rule_type', 'nogo_ingredient')->pluck('value_text')->filter()->all();
        if ($nogos !== []) {
            $teile[] = 'No-Go: ' . implode(', ', $nogos);
        }
        $allergene = $regeln->where('rule_type', 'nogo_allergen')->pluck('ref_key')->filter()->all();
        if ($allergene !== []) {
            $teile[] = 'ohne Allergen: ' . implode(', ', $allergene);
        }
        $quoten = $regeln->where('rule_type', 'diet_quota')->map(fn ($r) => $r->operator . ' ' . $r->value_num . ' ' . ($r->unit === 'percent' ? '%' : '×') . ' ' . $r->ref_key)->all();
        if ($quoten !== []) {
            $teile[] = 'Diät: ' . implode('; ', $quoten);
        }
        if ($frameSlot->price_min !== null || $frameSlot->price_max !== null) {
            $teile[] = 'Preisrahmen ' . ($frameSlot->price_min ?? '—') . '–' . ($frameSlot->price_max ?? '—') . ' €';
        }

        return $teile !== [] ? implode(' · ', $teile) : 'keine Regeln, aber kein Gericht im Bestand';
    }
}
