<?php

namespace Platform\FoodAlchemist\Services\Matching;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Nachwahl aus der Kandidatenliste für {@see \Platform\FoodAlchemist\Services\IngredientMatchService} (Spec 95c „nie schlechter als
 * Kandidat 1“, Neuabgleich 09.11.: gleich guter Kandidat ohne ungenannte Variante, Marke als Produktname). Ausgelagert wegen der
 * Dateigröße (Spec 85 §1) — nutzt die Pool-/Schutzregeln des Services.
 */
trait KandidatenNachwahl
{
    /**
     * Neuabgleich 09.11. („Mondamin“ → no_match, vorher richtig): nennt die Zeile NUR die Marke eines GP („Mondamin“, „Maggi“), ist die Marke der
     * Produktname. Die Marken-Klammer zählt im Score sonst nicht (Kurator 26.10.), und die Hauptzutat steht nicht in der Zeile.
     */
    private function nurMarke(FoodAlchemistGp $gp, array $queryTokens): bool
    {
        return $this->markeGenannt($gp, $queryTokens) && array_diff($queryTokens, $this->engine->tokenize((string) $gp->zusatz)) === [];
    }

    private function markeGenannt(FoodAlchemistGp $gp, array $queryTokens): bool
    {
        $marke = trim((string) $gp->zusatz) === '' ? [] : $this->engine->tokenize((string) $gp->zusatz);

        return $marke !== [] && $queryTokens !== [] && array_diff($marke, $queryTokens) === [];
    }

    /**
     * Score eines GP, dessen Marke die Zeile allein nennt: 0,90 (Kopf-Boden), wenn alle GPs dieser Marke im Pool dieselbe Hauptzutat haben —
     * sonst 0,60 (Review: „Mondamin“ kann Speisestärke oder Soßenbinder sein).
     */
    private function markenScore($pool, array $queryTokens): float
    {
        $haupt = $pool->filter(fn ($g) => $this->nurMarke($g, $queryTokens))->map(fn ($g) => mb_strtolower($this->hauptzutatText($g)))->unique();

        return $haupt->count() > 1 ? 0.6 : MatchHeuristics::NAME_CONTAINMENT_FLOOR;
    }

    /**
     * Neuabgleich 09.11. (Bio-Paprikapulver bei Gleichstand 1,001, „Schwarze Oliven“ → gefüllt mit Frischkäse): bleibt der Treffer wegen einer
     * Variante offen, die die Zeile nicht nennt, und steht in der Kandidatenliste ein gleich gutes GP (gleiche Schutzregeln wie Kandidat 1), das
     * ohne oder mit weniger ungenannten Varianten auskommt, gilt dieses. Gleich gut = Kandidaten-Score höchstens {@see GLEICH_GUT} unter dem
     * des Treffers — ein schlechter passendes GP gewinnt nie, nur weil es schlichter heißt.
     */
    private function ohneUngenannteVariante(Team $team, string $ingredientName, ?string $querySlug, array $final, array $geprueft, string $pref, string $bio, string $convenience): ?array
    {
        $name = $this->currentIngredientName;
        $liste = collect($this->candidatesFor($team, $ingredientName, $querySlug, 5))
            ->filter(fn ($c) => ($c['kind'] ?? null) === 'gp' && in_array($c['origin'] ?? 'lexical', ['lexical', 'both'], true))->values();
        $this->currentIngredientName = $name;
        $this->tiebreakQuery = $this->engine->ingredientTokens($name);
        $this->aliasTokens = null;
        $eigen = (float) ($liste->first(fn ($c) => (int) $c['id'] === (int) $final['gp_id'])['score'] ?? $liste->max('score') ?? 0.0);
        $gewaehlt = FoodAlchemistGp::query()->find((int) $geprueft['gp_id'], ['id', 'name', 'zusatz', 'condition']);
        if ($gewaehlt !== null && $this->markeGenannt($gewaehlt, $this->tiebreakQuery)) {
            return null;   // die Zeile nennt die Marke dieses GP — es ist gemeint
        }
        $offen = self::variantenGewicht($geprueft['variante_offen']);
        if ($offen === 0) {
            return null;   // nur eine ungenannte Marke: Marken-Varianten entscheidet der Gleichstand (Kurator 26.10.), nicht die schlichtere Klammer
        }
        $besser = null;
        foreach ($liste as $c) {
            if ((int) $c['id'] === (int) $final['gp_id'] || (int) $c['id'] === (int) $geprueft['gp_id'] || (float) $c['score'] < $eigen - self::GLEICH_GUT) {
                continue;
            }
            $gp = $this->kandidatZulaessig($team, $ingredientName, (int) $c['id']);
            // Golden „Drei-Zustand-Pool“ (rot seit #789): die Nachwahl tauscht eine ungenannte Variante, nie den Zustand — „Tomaten“ unter
            // „konserviert bevorzugt“ blieb „konserviert, geschält“ (Review), die Nachwahl machte TK daraus. Den Zustand entscheidet die Leitplanke.
            if ($gp === null || ($gewaehlt !== null && ! $this->gleicherZustand($gewaehlt, $gp))) {
                continue;
            }
            $t = app(BasisVariante::class)->pruefe($team, $ingredientName, ['gp_id' => (int) $gp->id, 'gp_name' => (string) $gp->name] + $final,
                $pref, $bio, $this->einheit, $this->menge, $convenience);
            $tOffen = self::variantenGewicht($t['variante_offen'] ?? []);
            if ($tOffen === 0 && self::istAutomatischVerdrahtbar($t)) {
                return $t;
            }
            if ($tOffen < $offen) {
                [$besser, $offen] = [$t, $tOffen];
            }
        }

        return $besser;
    }

    /** Zustand aus der Spalte, sonst aus dem Namen; unbekannt auf einer Seite = kein Widerspruch. */
    private function gleicherZustand(FoodAlchemistGp $a, FoodAlchemistGp $b): bool
    {
        $z = fn (FoodAlchemistGp $g) => mb_strtolower(trim((string) ($g->condition ?: ($this->engine->produktForm((string) $g->name)['zustand'] ?? ''))));
        [$za, $zb] = [$z($a), $z($b)];

        return $za === '' || $zb === '' || $za === $zb;
    }

    /**
     * Gewicht ungenannter Varianten: Marke 0 (Kurator 26.10.), eine fremde Zutat (Geschmack, Füllung „gefüllt mit …“, „mit …“) 2, sonst 1
     * (Bio, Farbe, Zuschnitt, Verarbeitung, Pflichtangabe).
     */
    private static function variantenGewicht(array $offen): int
    {
        return array_sum(array_map(fn (string $e) => match (true) {
            str_starts_with($e, 'Marke:') => 0,
            str_starts_with($e, 'Geschmack:'), preg_match('/(?<![\p{L}])(?:mit|gef(?:ü|ue)llt)(?![\p{L}])/iu', $e) === 1 => 2,
            default => 1,
        }, $offen));
    }

    /**
     * Schutzregeln eines Kandidaten aus der Kandidatenliste (wie jeder Pool-Treffer): Freigabe, Produktform, Verwechslungs-Falle, ganze
     * Hauptzutat, Derivat-Form. Gibt das GP zurück oder null.
     */
    private function kandidatZulaessig(Team $team, string $ingredientName, int $id): ?FoodAlchemistGp
    {
        $name = $this->currentIngredientName;
        $gp = FoodAlchemistGp::visibleToTeam($team)->lebensmittel()->whereIn('status', ['approved', 'tentative'])->where('is_platzhalter', false)->find($id);
        if ($gp === null || ! $this->acceptsProductForm($ingredientName, $gp->name, $gp->condition, $gp)
            || $this->terminology->isAntiMarker($ingredientName, $gp->name) || ! $this->hauptzutatPasst($gp, [], true)) {
            return null;
        }
        // Derivat-Form wie im Pool-Scan (Lauf 91/92): „Petersilie Stiele“ nie das ganze Kraut, „Grapefruitsaft“ nie die Zeste
        $form = $this->heuristik->nebenproduktDerivat($name)['marker'] ?? null;
        $gpForm = $gp->is_derivat ? ($this->heuristik->nebenproduktDerivat((string) $gp->name)['marker'] ?? null) : null;
        if (($form !== null && ! $this->heuristik->nenntDerivatForm((string) $gp->name, $form)) || ($gpForm !== null && ! $this->heuristik->nenntDerivatForm($name, $gpForm))) {
            return null;
        }

        return $gp;
    }

    /**
     * Spec 95c: Kandidat 1 der Kandidatenliste ({@see candidatesFor}, lexikalisch oder lexikalisch+semantisch — nie rein semantisch, das ist
     * der Draw), wenn er besser liegt als der Pool-Treffer und dessen Schutzregeln besteht: Produktform, Verwechslungs-Falle, Hauptzutat.
     */
    private function kandidatEins(Team $team, string $ingredientName, ?string $querySlug, float $score): ?array
    {
        $name = $this->currentIngredientName;
        $liste = collect($this->candidatesFor($team, $ingredientName, $querySlug, 5))->filter(fn ($c) => ($c['kind'] ?? null) === 'gp')->values();
        $this->currentIngredientName = $name;
        // Neuabgleich 09.11. („Essig“: Kandidat 1 „Essig-Essenz“ scheitert zu Recht am Hauptzutat-Schutz, „Branntweinessig“ 1,0 dahinter blieb
        // ungenutzt): der erste Kandidat, der die Schutzregeln besteht — nicht nur der erste überhaupt
        $gp = null;
        $c = null;
        $zulaessig = [];
        foreach ($liste as $k) {
            if (! in_array($k['origin'] ?? 'lexical', ['lexical', 'both'], true) || (float) $k['score'] <= $score + MatchHeuristics::SCORE_EPS) {
                continue;
            }
            if (($g = $this->kandidatZulaessig($team, $ingredientName, (int) $k['id'])) !== null) {
                $zulaessig[] = [$k, $g];
            }
        }
        if ($zulaessig === []) {
            return null;
        }
        [$c, $gp] = $zulaessig[0];
        $s = min(1.0, (float) $c['score']);
        // Gleich gute Kandidaten mit anderer Hauptzutat („Essig“ → Branntwein-, Tafel-, Weißweinessig): Vorschlag, keine Entscheidung
        $mehrdeutig = collect($zulaessig)->slice(1)->contains(fn ($z) => (float) $z[0]['score'] >= (float) $c['score'] - self::GLEICH_GUT
            && mb_strtolower($this->hauptzutatText($z[1])) !== mb_strtolower($this->hauptzutatText($gp)));

        return ['target' => 'gp', 'status' => $mehrdeutig ? MatchBand::FuzzyLow : MatchBand::fuerScore($s), 'gp_id' => (int) $gp->id, 'gp_name' => (string) $gp->name,
            'recipe_id' => null, 'recipe_name' => null, 'score' => $s];
    }
}
