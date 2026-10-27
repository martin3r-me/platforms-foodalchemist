<?php

namespace Platform\FoodAlchemist\Services\Matching;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\ZutatFreitext;

/**
 * Basisvariante (Kurator/Dominique 27.10., Regel zum Bestands-Abgleich): Nennt die Zutat keine Variante, darf ein automatischer Treffer
 * (Exact/FuzzyHigh) keine tragen — kein Bio, keine Marke/Klammer, kein Geschmack, keine Verarbeitung, kein Zuschnitt (außer „ganz“), keine
 * Portion, keine Pflichtangabe/Fettstufe; Zustand frisch vor TK/trocken/konserviert, sofern die Ware ihn hat. Sonst: die eine Basisvariante
 * derselben Hauptzutat nehmen; gibt es keine eindeutige, wird der Treffer FuzzyLow (Review mit Shortlist) — lieber offen als falsch
 * (Bio-Claim, Preis, Rendite). Der Default-GP-Alias (§5) läuft vorher und ist selbst die Basis.
 */
final class BasisVariante
{
    /** Regelwerk Basisrezepte §2 Verarbeitungs-Suffixe (wörtlich, TokenEngine-normalisiert): Küchenarbeit → Roh-Form. */
    private const PARA2_SUFFIXE = ['brunoise', 'wuerfel', 'gewuerfelt', 'gehackt', 'geschnitten', 'gerieben', 'geroestet', 'blanchiert', 'gestiftelt',
        'gemahlen', 'pueriert', 'scheiben', 'streifen'];

    /** Dominique 27.10.: „gemahlen“ ist bei Gewürzen (WG 10), Nüssen/Saaten (12.3) und Kaffee (15.8) Zukauf, sonst Küchenarbeit nach §2. */
    private const GEMAHLEN_ZUKAUF_WG = ['10'];

    private const GEMAHLEN_ZUKAUF_SUB = ['12.3', '15.8'];

    /** Dominique 27.10.: Gemüse (inkl. Pilze), Obst, Kräuter — ohne Zustand im Text und ohne Frisch-Basis keine haltbare Einzelvariante automatisch. */
    private const FRISCHWARE_WG = ['01', '02', '03'];

    /** §2 F2.1: Convenience-Zukauf erkennbar — dann ist die Schnittware gemeint („Zwiebelwürfel TK“, „vorgeschnitten“, Dose). */
    private const PARA2_ZUKAUF = ['vorgeschnitten', 'vorgegart', 'vorgekocht', 'schnittware', 'fertig', 'convenience'];

    /** Zukauf-Signal der aktuellen Zeile (§2 F2.1), je pruefe() gesetzt. */
    private bool $zukauf = false;

    /** „gemahlen“ ist beim gerade geprüften GP Zukauf (je ungenannt() gesetzt). */
    private bool $gemahlenZukauf = false;

    public function __construct(private TokenEngine $engine, private GpNamingService $naming)
    {
    }

    /**
     * @param  array  $treffer  Ergebnis von matchIngredient (target/status/gp_id/gp_name/score …)
     * @return array  derselbe Treffer, ggf. auf die Basisvariante umgehängt oder auf FuzzyLow herabgestuft (+ 'variante_offen')
     */
    public function pruefe(Team $team, string $zutat, array $treffer, string $pref = 'fresh_first', string $bio = 'conventional'): array
    {
        $band = $treffer['status'] ?? null;
        $band = is_string($band) ? MatchBand::tryFrom($band) : $band;
        if (($treffer['target'] ?? null) !== 'gp' || ! in_array($band, [MatchBand::Exact, MatchBand::FuzzyHigh], true) || empty($treffer['gp_id'])) {
            return $treffer;
        }
        $spalten = ['id', 'name', 'zusatz', 'bio', 'condition', 'main_ingredient_slug', 'commodity_group_code', 'sub_category'];
        $gewaehlt = FoodAlchemistGp::visibleToTeam($team)->find((int) $treffer['gp_id'], $spalten);
        if ($gewaehlt === null) {
            return $treffer;
        }
        $query = $this->engine->ingredientTokens($zutat);
        $qZustand = $this->engine->produktForm($zutat)['zustand'];
        // §2 F2.1: ohne Zukauf-Signal ist ein Zuschnitt in der Zeile Küchenarbeit — er wählt keine Schnittware (Kurator 27.10.)
        $this->zukauf = in_array($qZustand, ['TK', 'konserviert'], true) || array_intersect($query, self::PARA2_ZUKAUF) !== [];
        $geschwister = (string) $gewaehlt->main_ingredient_slug === '' ? collect([$gewaehlt])
            : FoodAlchemistGp::visibleToTeam($team)->lebensmittel()->whereIn('status', ['approved', 'tentative'])->where('is_platzhalter', false)
                ->where('main_ingredient_slug', $gewaehlt->main_ingredient_slug)->orderBy('id')->get($spalten)
                // gleicher Slug reicht nicht: „Zitronengraspulver“ ist ein anderes Produkt als „Zitronengras“ — nur dieselbe Hauptzutat ist Geschwister
                ->filter(fn ($g) => $this->hauptzutat($g) === $this->hauptzutat($gewaehlt))->values();
        // Leitplanke Bio (Session/Import): Bio ist dann keine ungenannte Variante, sondern gewollt — und hat Vorrang
        $bioGewollt = $bio === 'bio';
        // Zustand nach Leitplanke (frisch | TK | konserviert/behandelt), ohne Leitplanke frisch — sofern die Ware ihn hat
        $wunsch = match ($pref) {
            'frozen_first' => ['tk'],
            'preserved_first' => ['konserviert', ...array_map('mb_strtolower', GpNamingService::BEHANDLUNG)],
            default => ['frisch'],
        };
        // Nüsse/Saaten „geröstet“ (Dominique 27.10.): roh UND geröstet im Bestand → Review; nur eine Form → die nehmen
        if (in_array('geroestet', $query, true) && $this->istSub($gewaehlt, '12.3') && $this->hatGeroestet($gewaehlt)) {
            $roh = $geschwister->first(fn ($g) => $g->id !== $gewaehlt->id && ! $this->hatGeroestet($g) && $this->ungenannt($g, $query, $bioGewollt) === []);

            return $roh === null ? $treffer : ['status' => MatchBand::FuzzyLow, 'variante_offen' => ['geröstet oder roh']] + $treffer;
        }
        // Nennt die Zeile die Variante des Treffers ausdrücklich (Zuschnitt mit Zukauf-Signal, Marke, Bio, Fettstufe …), bleibt er
        if ($this->ungenannt($gewaehlt, $query, $bioGewollt) === [] && $this->traegtVariante($gewaehlt) && ! ($bioGewollt && ! $this->istBio($gewaehlt))) {
            return $this->haltbarPruefen($treffer, $gewaehlt, $geschwister, $query, $qZustand, $bioGewollt, $wunsch);
        }
        $basis = $geschwister->filter(fn ($g) => $this->ungenannt($g, $query, $bioGewollt) === []);
        if ($qZustand !== null) {
            $basis = $basis->filter(fn ($g) => mb_strtolower((string) $g->condition) === mb_strtolower($qZustand) || trim((string) $g->condition) === '');
        }
        $bevorzugt = $basis->filter(fn ($g) => in_array(mb_strtolower((string) $g->condition), $wunsch, true));
        if ($qZustand === null && $bevorzugt->isNotEmpty()) {
            $basis = $bevorzugt;
        }
        $bioBasis = $basis->filter(fn ($g) => $this->istBio($g));
        if ($bioGewollt && $bioBasis->isNotEmpty()) {
            $basis = $bioBasis;
        }

        if ($basis->contains('id', $gewaehlt->id)) {
            // der Treffer ist selbst eine Basisvariante (bei Bio-Leitplanke: die Bio-Basis, wenn es sie gibt)
            return $this->haltbarPruefen($treffer, $gewaehlt, $geschwister, $query, $qZustand, $bioGewollt, $wunsch);
        }
        if ($basis->count() === 1) {
            $b = $basis->first();

            return $this->haltbarPruefen(['gp_id' => (int) $b->id, 'gp_name' => (string) $b->name] + $treffer, $b, $geschwister, $query, $qZustand, $bioGewollt, $wunsch);
        }

        return ['status' => MatchBand::FuzzyLow, 'variante_offen' => $this->ungenannt($gewaehlt, $query, $bioGewollt)] + $treffer;
    }

    /**
     * Haltbare Einzelvariante (Dominique 27.10.): Gemüse/Pilze/Obst/Kräuter, Text ohne Zustand, Treffer nicht frisch und keine frische
     * Basisvariante im Bestand → Review („Steinpilze“ → nicht automatisch „Steinpilz: trocken“; „Steinpilze getrocknet“ bleibt exact).
     */
    private function haltbarPruefen(array $treffer, FoodAlchemistGp $gp, $geschwister, array $query, ?string $qZustand, bool $bioGewollt, array $wunsch): array
    {
        $zustand = mb_strtolower(trim((string) $gp->condition));
        // Text nennt den Zustand, Leitplanke wünscht genau diesen (frische=tk → TK-Gemüse), frisch, ohne Zustand oder keine Frischware → bleibt
        if ($qZustand !== null || $zustand === '' || $zustand === 'frisch' || in_array($zustand, $wunsch, true)
            || ! in_array((string) $gp->commodity_group_code, self::FRISCHWARE_WG, true)) {
            return $treffer;
        }
        $frischeBasis = $geschwister->first(fn ($g) => mb_strtolower((string) $g->condition) === 'frisch' && $this->ungenannt($g, $query, $bioGewollt) === []);

        return $frischeBasis !== null ? $treffer : ['status' => MatchBand::FuzzyLow, 'variante_offen' => ['Zustand: ' . $gp->condition . ' (kein frisches GP)']] + $treffer;
    }

    /** Hauptzutat (Teil vor dem Doppelpunkt, ohne Marken-/Flag-Klammer), normalisiert. */
    private function hauptzutat(FoodAlchemistGp $gp): string
    {
        return implode(' ', $this->engine->tokenize((string) $this->naming->felderAusName($this->naming->nameOhneMarke((string) $gp->name, $gp->zusatz))['hauptzutat']));
    }

    private function istSub(FoodAlchemistGp $gp, string $sub): bool
    {
        return str_starts_with(trim((string) $gp->sub_category), $sub);
    }

    private function hatGeroestet(FoodAlchemistGp $gp): bool
    {
        return in_array('geroestet', $this->engine->tokenize((string) $gp->name), true);
    }

    /** „gemahlen“ ist bei dieser Ware Zukauf (Gewürze, Nüsse/Saaten, Kaffee)? */
    private function gemahlenIstZukauf(FoodAlchemistGp $gp): bool
    {
        return in_array((string) $gp->commodity_group_code, self::GEMAHLEN_ZUKAUF_WG, true)
            || array_filter(self::GEMAHLEN_ZUKAUF_SUB, fn ($s) => $this->istSub($gp, $s)) !== [];
    }

    /** Trägt das GP überhaupt eine Variante (außer Zustand und „ganz“)? */
    private function traegtVariante(FoodAlchemistGp $gp): bool
    {
        $f = $this->naming->felderAusName((string) $gp->name, true);
        foreach (['flavor', 'processing', 'form', 'portion', 'pflichtangabe', 'zusatz'] as $feld) {
            $w = mb_strtolower(trim((string) ($f[$feld] ?? '')));
            if ($w !== '' && $w !== 'ganz') {
                return true;
            }
        }

        return trim((string) $gp->zusatz) !== '' || $this->istBio($gp);
    }

    private function istBio(FoodAlchemistGp $gp): bool
    {
        return mb_strtolower((string) $gp->bio) === 'bio' || $this->naming->felderAusName((string) $gp->name, true)['bio'];
    }

    /**
     * Varianten-Merkmale des GP, die die Zutat nicht nennt.
     *
     * @return list<string>
     */
    public function ungenannt(FoodAlchemistGp $gp, array $query, bool $bioGewollt = false): array
    {
        $this->gemahlenZukauf = $this->gemahlenIstZukauf($gp);
        $f = $this->naming->felderAusName((string) $gp->name, true);
        $offen = [];
        if (! $bioGewollt && (mb_strtolower((string) $gp->bio) === 'bio' || $f['bio']) && ! in_array('bio', $query, true)) {
            $offen[] = 'Bio';
        }
        $zusatz = trim((string) ($gp->zusatz ?: ($f['zusatz'] ?? '')));
        foreach (['Marke' => $zusatz, 'Geschmack' => $f['flavor'] ?? '', 'Verarbeitung' => $f['processing'], 'Zuschnitt' => $f['form'],
            'Portion' => $f['portion'], 'Pflichtangabe' => $f['pflichtangabe']] as $art => $wert) {
            $wert = trim((string) $wert);
            if ($wert === '' || ($art === 'Zuschnitt' && mb_strtolower($wert) === 'ganz') || $this->genannt($wert, $query)) {
                continue;
            }
            $offen[] = $art . ': ' . $wert;
        }

        return $offen;
    }

    /**
     * Nennt die Zutat den Wert? Jedes tragende Wort (Zahlen exakt) muss vorkommen — gleich, Lemma oder Wortanfang. Ein §2-Zuschnitt
     * (Brunoise, Würfel, gehackt …) gilt ohne Zukauf-Signal nie als genannt: „Schalotten, in Brunoise“ meint die rohe Schalotte.
     */
    private function genannt(string $wert, array $query): bool
    {
        $tokens = $this->engine->tokenize($wert);
        $para2 = $this->gemahlenZukauf ? array_diff(self::PARA2_SUFFIXE, ['gemahlen']) : self::PARA2_SUFFIXE;
        if (! $this->zukauf && array_intersect($tokens, $para2) !== []) {
            return false;
        }
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2 && ! is_numeric($t)) {
                continue;
            }
            $treffer = false;
            foreach ($query as $q) {
                if ($q === $t || (! is_numeric($t) && (ZutatFreitext::lemma($q) === ZutatFreitext::lemma($t)
                    || (min(mb_strlen($q), mb_strlen($t)) >= 4 && (str_starts_with($q, $t) || str_starts_with($t, $q)))))) {
                    $treffer = true;
                    break;
                }
            }
            if (! $treffer) {
                return false;
            }
        }

        return true;
    }
}
