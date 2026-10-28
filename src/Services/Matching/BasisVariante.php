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

    /**
     * Dominique 27.10. (a): Naturform der Ware — beim einzigen Kandidaten kein Grund für den Review (Agar → Pulver, Meersalz → grob).
     * Wert → Bedingung am GP: null = immer; 'pulverware' = Hauptzutat ist Pulver („Chilipulver“) oder Gewürz/Bindemittel ohne frische Form im
     * Bestand (Messung 27.10.: „Ingwer“ → gemahlen, „Getrocknete Tomaten“ → Pulver, „Kaffee“ → gemahlen waren falsch); 'salz'/'passata' = Hauptzutat.
     */
    private const NATURFORM = ['pulver' => 'pulverware', 'pulverfoermig' => 'pulverware', 'fluessig' => 'kein_ei', 'knolle' => null, 'wurzel' => null, 'ganz' => null,
        'gemahlen' => 'pulverware', 'grob' => 'salz', 'fein' => 'salz', 'pueriert' => 'passata'];

    /** @var array<string, bool> Slug → gibt es eine frische Form (Request-Cache) */
    private array $frischform = [];

    private ?Team $team = null;

    /** Zutatentext der aktuellen Prüfung (für genannte Prozent-/Type-Werte). */
    private string $zutat = '';

    /** Kurator 28.10. (2): ganze Gewürzform — bei Einheit Stück genannt. */
    private const GANZFORM = ['ganz', 'ganze', 'stange', 'stangen', 'schote', 'schoten', 'blatt', 'blaetter', 'stern', 'sterne', 'korn', 'koerner'];

    /** Gemahlene/zerkleinerte Gewürzform. */
    private const ZERKLEINERT = ['gemahlen', 'pulver', 'gerebelt', 'geschrotet', 'flocken', 'granulat'];

    /** Dominique 27.10. (c): Fleisch-Teilstücke (WG 04, Bestand + Kurator-Liste) sind genannte Varianten, keine Küchenarbeit nach §2. */
    private const TEILSTUECKE = ['mittelstueck', 'kopfstueck', 'schwanzstueck', 'kopf', 'ruecken', 'keule', 'brust', 'huefte', 'oberschale',
        'unterschale', 'nuss', 'schulter', 'nacken', 'bauch', 'seite', 'lende', 'haxe'];

    /** Zubereitungswörter der Zeile (MatchHeuristics::ZUBEREITUNG_MARKER-Verben + gegart/gekocht/geschält) — Küchenarbeit, nie genanntes Merkmal. */
    private const ZUBEREITUNG = ['gegart', 'gekocht', 'geschaelt', 'ausgedaempft', 'sautiert', 'gebraten', 'gebacken', 'geschmort', 'gegrillt',
        'frittiert', 'pochiert', 'glasiert', 'mariniert', 'paniert', 'gratiniert', 'blanchiert', 'gedaempft', 'geduenstet', 'karamellisiert', 'confiert'];

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
    public function pruefe(Team $team, string $zutat, array $treffer, string $pref = 'fresh_first', string $bio = 'conventional', ?string $einheit = null): array
    {
        $band = $treffer['status'] ?? null;
        $band = is_string($band) ? MatchBand::tryFrom($band) : $band;
        if (($treffer['target'] ?? null) !== 'gp' || ! in_array($band, [MatchBand::Exact, MatchBand::FuzzyHigh], true) || empty($treffer['gp_id'])) {
            return $treffer;
        }
        $this->team = $team;
        $this->zutat = $zutat;
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
        // Fleisch-Teilstück (Dominique 27.10. c): ein genanntes Teilstück ist eine genannte Variante — Geschwister ohne dieses Teilstück
        // scheiden aus („Rinderfilet: frisch, Mittelstück“ ist nie „Rinderfilet: frisch“). Volle Tokens: ingredientTokens kann Zuschnitt-Wörter verlieren.
        $teil = (string) $gewaehlt->commodity_group_code === '04' ? array_values(array_intersect($this->engine->tokenize($zutat), self::TEILSTUECKE)) : [];
        // Ebenso jedes andere genannte Merkmal der Geschwister (Messung 27.10.: „Olivenöl extra vergine“ → Olivenöl, „Kartoffeln mehlig“ → ohne
        // Kochtyp, „Weizenmehl Type 550“ → Type 405): was der Text aus Geschmack/Verarbeitung/Pflichtangabe nennt, muss der Treffer tragen
        $teil = array_values(array_unique([...$teil, ...$this->benannteMerkmale($zutat, $geschwister)]));
        if ($teil !== []) {
            $geschwister = $geschwister->filter(fn ($g) => array_diff($teil, $this->engine->tokenize((string) $g->name)) === [])->values();
            if (! $geschwister->contains('id', $gewaehlt->id)) {
                $sauber = $geschwister->filter(fn ($g) => $this->hart($g, $query, $bioGewollt) === [])->values();
                if ($sauber->count() === 1) {
                    $b = $sauber->first();

                    return ['gp_id' => (int) $b->id, 'gp_name' => (string) $b->name] + $treffer;
                }
                $vorschlag = $geschwister->first();

                return ['status' => MatchBand::FuzzyLow, 'variante_offen' => $vorschlag === null ? ['Teilstück: ' . implode(' ', $teil) . ' (kein GP)']
                    : $this->ungenannt($vorschlag, $query, $bioGewollt)] + ($vorschlag === null ? [] : ['gp_id' => (int) $vorschlag->id, 'gp_name' => (string) $vorschlag->name]) + $treffer;
            }
        }
        // Kurator 28.10. (2): Gewürz mit Einheit Stück (Zimt, Sternanis, Vanille, Lorbeer) meint die ganze Form — nie gemahlen; ohne ganze Form Review
        if ((string) $gewaehlt->commodity_group_code === '10' && ZutatFreitext::stueckEinheit($zutat, $einheit)) {
            $ganz = $geschwister->reject(fn ($g) => $this->istZerkleinert($g))->values();
            $sauber = $ganz->filter(fn ($g) => array_filter($this->hart($g, $query, $bioGewollt),
                fn ($e) => array_diff($this->engine->tokenize(explode(': ', $e, 2)[1] ?? $e), self::GANZFORM) !== []) === [])->values();
            if ($sauber->count() === 1) {
                $b = $sauber->first();

                return ['gp_id' => (int) $b->id, 'gp_name' => (string) $b->name] + $treffer;
            }
            $vorschlag = $ganz->first();

            return ['status' => MatchBand::FuzzyLow, 'variante_offen' => [$vorschlag === null ? 'ganze Form fehlt' : 'ganze Form: mehrere Kandidaten']]
                + ($vorschlag === null ? [] : ['gp_id' => (int) $vorschlag->id, 'gp_name' => (string) $vorschlag->name]) + $treffer;
        }
        // Nennt die Zeile die Variante des Treffers ausdrücklich (Zuschnitt mit Zukauf-Signal, Marke, Bio, Fettstufe …), bleibt er
        if ($this->ungenannt($gewaehlt, $query, $bioGewollt) === [] && $this->traegtVariante($gewaehlt) && ! ($bioGewollt && ! $this->istBio($gewaehlt))) {
            return $this->haltbarPruefen($treffer, $gewaehlt, $geschwister, $query, $qZustand, $bioGewollt, $wunsch);
        }
        $waehle = function (callable $offen) use ($geschwister, $qZustand, $wunsch, $bioGewollt) {
            $basis = $geschwister->filter(fn ($g) => $offen($g) === []);
            if ($qZustand !== null) {
                $basis = $basis->filter(fn ($g) => mb_strtolower((string) $g->condition) === mb_strtolower($qZustand) || trim((string) $g->condition) === '');
            }
            $bevorzugt = $basis->filter(fn ($g) => in_array(mb_strtolower((string) $g->condition), $wunsch, true));
            if ($qZustand === null && $bevorzugt->isNotEmpty()) {
                $basis = $bevorzugt;
            }
            $bioBasis = $basis->filter(fn ($g) => $this->istBio($g));

            return $bioGewollt && $bioBasis->isNotEmpty() ? $bioBasis : $basis;
        };
        $basis = $waehle(fn ($g) => $this->ungenannt($g, $query, $bioGewollt));
        // (a) keine Basis ohne jedes Merkmal: Naturform (Pulver, gemahlen, grob …) und §8-Pflichtangabe (Mehl-Type, Fettstufe, Kakao-%)
        // zählen beim EINZIGEN Kandidaten nicht als Variante; mehrere solche Kandidaten → Review (der §5-Default lief vorher)
        if ($basis->isEmpty()) {
            $basis = $waehle(fn ($g) => $this->hart($g, $query, $bioGewollt));
            if ($basis->count() > 1) {
                return ['status' => MatchBand::FuzzyLow, 'variante_offen' => $this->ungenannt($gewaehlt, $query, $bioGewollt)] + $treffer;
            }
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
            || ! in_array((string) $gp->commodity_group_code, self::FRISCHWARE_WG, true)
            // zugekauftes Püree/Mus/Mark (§8.17, Basisrezepte §3) ist ein eigenes Produkt, keine haltbare Variante frischer Ware
            || preg_match('/(?:pueree|puree|mus|mark)(?:\s|$)/', $this->hauptzutat($gp)) === 1) {
            return $treffer;
        }
        $frischeBasis = $geschwister->first(fn ($g) => mb_strtolower((string) $g->condition) === 'frisch' && $this->ungenannt($g, $query, $bioGewollt) === []);

        return $frischeBasis !== null ? $treffer : ['status' => MatchBand::FuzzyLow, 'variante_offen' => ['Zustand: ' . $gp->condition . ' (kein frisches GP)']] + $treffer;
    }

    /**
     * Ungenannte Merkmale ohne Naturform und §8-Pflichtangabe (Dominique 27.10. a) — was bleibt, ist Bio, Marke, Sorte, Herkunft, Zuschnitt …
     *
     * @return list<string>
     */
    private function hart(FoodAlchemistGp $gp, array $query, bool $bioGewollt): array
    {
        return array_values(array_filter($this->ungenannt($gp, $query, $bioGewollt), function (string $eintrag) use ($gp) {
            [$art, $wert] = array_pad(explode(': ', $eintrag, 2), 2, '');

            // die Naturform steht je nach Namen in Geschmack, Verarbeitung, Zuschnitt oder Pflichtangabe („Ingwer: Knolle, frisch“ → Geschmack)
            return ! match ($art) {
                'Bio', 'Marke', 'Portion' => false,
                'Pflichtangabe' => $this->pflichtangabeWeich($gp, $wert) || $this->naturform($gp, $wert),
                default => $this->naturform($gp, $wert),
            };
        }));
    }

    /** §8.11 Mehl-Type, §8.1 Fettstufe, §8.10 Kakaoanteil, §8.2 Eiergröße; Zucker-Angabe nur bei Milchprodukten (Kondensmilch „extra süß“). */
    private function pflichtangabeWeich(FoodAlchemistGp $gp, string $wert): bool
    {
        $w = mb_strtolower(trim($wert));
        // nennt der Text selbst einen anderen Prozent-/Type-Wert („Milch (1,5 %)“), ist die Pflichtangabe des GP nicht weich (Nachmessung 28.10.)
        if (array_diff(ZutatFreitext::wertangaben($this->zutat), ZutatFreitext::wertangaben($w)) !== []) {
            return false;
        }

        // §8.2 Eiergröße wie Mehl-Type/Fettstufe; die Haltungsform (Bodenhaltung, Freiland) bleibt ein hartes Merkmal
        return (bool) preg_match('/^(type|tipo)\s*\w+$|^\d+([,.]\d+)?\s*%\s*(fett|kakao)$|^größe\s*(s|m|l|xl)$/u', $w)
            || ((string) $gp->commodity_group_code === '06' && in_array($w, ['extra süß', 'gezuckert', 'gesüßt'], true));
    }

    /** Jedes Wort des Werts ist eine Naturform dieser Ware. */
    private function naturform(FoodAlchemistGp $gp, string $wert): bool
    {
        $tokens = $this->engine->tokenize($wert);
        $haupt = $this->hauptzutat($gp);
        foreach ($tokens as $t) {
            if (! array_key_exists($t, self::NATURFORM)) {
                return false;
            }
            $ok = match (self::NATURFORM[$t]) {
                null => true,
                'pulverware' => str_contains($haupt, 'pulver')
                    // Gewürze (WG 10) und Back-/Bindemittel (WG 12 ohne Nüsse 12.3 — Nüsse sind ganz die Basis) ohne frische Form
                    || (((string) $gp->commodity_group_code === '10' || ((string) $gp->commodity_group_code === '12' && ! $this->istSub($gp, '12.3')))
                        && ! $this->hatFrischform($gp)),
                'salz' => str_contains($haupt, 'salz'),
                // Kurator 28.10. (1): bei Eiern ist „flüssig“ das Flüssigei, keine Naturform
                'kein_ei' => preg_match('/(?:^|\s)(?:voll)?ei(?:er|gelb|weiss|klar)?(?:\s|$)/', $haupt) !== 1,
                'passata' => str_contains($haupt, 'passata') || str_contains($haupt, 'passiert'),
            };
            if (! $ok) {
                return false;
            }
        }

        return $tokens !== [];
    }

    /**
     * Merkmal-Wörter, die der Text nennt und die bei mindestens einem Geschwister in Geschmack, Verarbeitung oder Pflichtangabe stehen —
     * ohne §2-Küchenarbeit und Naturform. Zahlen nur als Mehl-Type („Type 550“) oder Prozent („30 %“), nie die Menge der Zeile.
     *
     * @return list<string>
     */
    private function benannteMerkmale(string $zutat, $geschwister): array
    {
        $merkmal = [];
        foreach ($geschwister as $g) {
            $f = $this->naming->felderAusName((string) $g->name, true);
            foreach (['flavor', 'processing', 'pflichtangabe'] as $feld) {
                foreach ($this->engine->tokenize((string) ($f[$feld] ?? '')) as $t) {
                    $merkmal[$t] = true;
                }
            }
        }
        $text = $this->engine->tokenize($zutat);
        $zahlen = preg_match_all('/\b(?:type|tipo)\s*(\d+)|(\d+(?:[.,]\d+)?)\s*%/iu', $zutat, $m) > 0 ? array_filter([...$m[1], ...$m[2]]) : [];
        $out = [];
        foreach ($text as $t) {
            // Zubereitung in der Zeile („im Meersalz gegart“, „geschält, gekocht“) ist Küchenarbeit, kein Einkaufsmerkmal (§2)
            if (! isset($merkmal[$t]) || in_array($t, [...self::PARA2_SUFFIXE, ...self::ZUBEREITUNG], true) || array_key_exists($t, self::NATURFORM)
                || (mb_strlen($t) < 3 && ! is_numeric($t))) {
                continue;
            }
            if (is_numeric($t) && ! in_array($t, array_map(fn ($z) => implode('', $this->engine->tokenize((string) $z)), $zahlen), true)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    private function istZerkleinert(FoodAlchemistGp $gp): bool
    {
        return array_intersect($this->engine->tokenize((string) $gp->name), self::ZERKLEINERT) !== [] || str_contains($this->hauptzutat($gp), 'pulver');
    }

    /** Gibt es die Ware auch frisch (Ingwer-Knolle, frischer Rosmarin, Tomate)? Dann ist Pulver/gemahlen keine Naturform. */
    private function hatFrischform(FoodAlchemistGp $gp): bool
    {
        $slug = (string) $gp->main_ingredient_slug;
        if ($slug === '' || $this->team === null) {
            return false;
        }

        return $this->frischform[$this->team->id . '|' . $slug] ??= FoodAlchemistGp::visibleToTeam($this->team)->where('main_ingredient_slug', $slug)
            ->whereIn('status', ['approved', 'tentative'])->whereRaw('LOWER(`condition`) = ?', ['frisch'])->exists();
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
