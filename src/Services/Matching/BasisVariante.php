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

    /** §2 F2.1: Convenience-Zukauf erkennbar — dann ist die Schnittware gemeint („Zwiebelwürfel TK“, „vorgeschnitten“, Dose). */
    private const PARA2_ZUKAUF = ['vorgeschnitten', 'vorgegart', 'vorgekocht', 'schnittware', 'fertig', 'convenience'];

    /** Zukauf-Signal der aktuellen Zeile (§2 F2.1), je pruefe() gesetzt. */
    private bool $zukauf = false;

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
        $spalten = ['id', 'name', 'zusatz', 'bio', 'condition', 'main_ingredient_slug'];
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
                ->where('main_ingredient_slug', $gewaehlt->main_ingredient_slug)->orderBy('id')->get($spalten);
        // Leitplanke Bio (Session/Import): Bio ist dann keine ungenannte Variante, sondern gewollt — und hat Vorrang
        $bioGewollt = $bio === 'bio';
        // Nennt die Zeile die Variante des Treffers ausdrücklich (Zuschnitt mit Zukauf-Signal, Marke, Bio, Fettstufe …), bleibt er
        if ($this->ungenannt($gewaehlt, $query, $bioGewollt) === [] && $this->traegtVariante($gewaehlt) && ! ($bioGewollt && ! $this->istBio($gewaehlt))) {
            return $treffer;
        }
        $basis = $geschwister->filter(fn ($g) => $this->ungenannt($g, $query, $bioGewollt) === []);
        if ($qZustand !== null) {
            $basis = $basis->filter(fn ($g) => mb_strtolower((string) $g->condition) === mb_strtolower($qZustand) || trim((string) $g->condition) === '');
        }
        // Zustand nach Leitplanke (frisch | TK | konserviert/behandelt), ohne Leitplanke frisch — sofern die Ware ihn hat
        $wunsch = match ($pref) {
            'frozen_first' => ['tk'],
            'preserved_first' => ['konserviert', ...array_map('mb_strtolower', GpNamingService::BEHANDLUNG)],
            default => ['frisch'],
        };
        $bevorzugt = $basis->filter(fn ($g) => in_array(mb_strtolower((string) $g->condition), $wunsch, true));
        if ($qZustand === null && $bevorzugt->isNotEmpty()) {
            $basis = $bevorzugt;
        }
        $bioBasis = $basis->filter(fn ($g) => $this->istBio($g));
        if ($bioGewollt && $bioBasis->isNotEmpty()) {
            $basis = $bioBasis;
        }

        if ($basis->contains('id', $gewaehlt->id)) {
            return $treffer;   // der Treffer ist selbst eine Basisvariante (bei Bio-Leitplanke: die Bio-Basis, wenn es sie gibt)
        }
        if ($basis->count() === 1) {
            $b = $basis->first();

            return ['gp_id' => (int) $b->id, 'gp_name' => (string) $b->name] + $treffer;
        }

        return ['status' => MatchBand::FuzzyLow, 'variante_offen' => $this->ungenannt($gewaehlt, $query, $bioGewollt)] + $treffer;
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
        if (! $this->zukauf && array_intersect($tokens, self::PARA2_SUFFIXE) !== []) {
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
