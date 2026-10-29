<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M4-09: GL-04 Voll-Port — Zutat-String → GP | Sub-Rezept | none.
 * Pipeline (match_ingredient_pref_bio, rs:1027): §4-Sub-Alias → §5-GP-Alias →
 * Pool-Priorität (Halbfabrikat-Gate 4.4k + Exact-Sub-Override 4.4l) →
 * Pool-Scans mit Containment-Floor (4.4o) + Varianten-/Bio-Tiebreaker (4.4m/r/u)
 * + Sub-Typ-Boost (4.4b) → Schwellen (§4.1).
 *
 * Determinismus (Inv. 7/W-4): Pool-Scans iterieren ORDER BY id ASC.
 * Modus: gp_first (Default) | sub_recipe_first (from_scratch UND teil_convenience — A-1).
 */
class IngredientMatchService
{
    public function __construct(
        private TokenEngine $engine,
        private MatchHeuristics $heuristik,
        // E2 (#507): nullable + container-resolved. Ohne Provider/Flag inert →
        // candidatesFor bleibt byte-identisch zum Legacy-Verhalten (84 Goldens grün).
        private ?SemanticRetrievalService $semantic = null,
        // #507 Weg-2: deterministische Alias-/Anti-Marker-Schicht (S1/S2).
        private ?TerminologyService $terminology = null,
    ) {
        $this->semantic ??= app(SemanticRetrievalService::class);
        $this->terminology ??= app(TerminologyService::class);
    }

    /** Roh-Query der laufenden matchIngredient-Entscheidung (für S2-Suppression). */
    private string $currentIngredientName = '';

    /**
     * Marken-Varianten (Kurator 26.10.): Tiebreak-Daten je Aufruf — Favoriten-Rang (FavoritenOverlay), Stamm-Lieferanten je WG, Lead-Lieferant je GP.
     * Zu Beginn jedes öffentlichen Aufrufs geleert (Favoriten/Stamm können sich zwischen Aufrufen ändern).
     *
     * @var array{favoriten?: array<int,int>, stamm?: array<string, array<int,bool>>, lead?: array<int, ?int>, gp?: array<int, ?FoodAlchemistGp>}
     */
    private array $tiebreakMemo = [];

    /** Query-Tokens des laufenden Aufrufs — für den Tiebreak „weniger fremde Eigenschaften“. */
    private array $tiebreakQuery = [];

    /** Hauptzutat-Schutz: Terminologie-Alias-Tokens der laufenden Zeile (je matchIngredient einmal, null = noch nicht geladen). */
    private ?array $aliasTokens = null;

    /**
     * @return array{target: string, status: MatchBand, gp_id: ?int, gp_name: ?string,
     *               recipe_id: ?int, recipe_name: ?string, score: float}
     */
    /**
     * Darf ein automatischer Pfad (Generator, Heilung/Revise über syncIngredients) diesen Treffer ohne
     * Menschen verdrahten? Nur Exact/FuzzyHigh — FuzzyLow („bitte prüfen") bleibt offen (Rahmeis-Fall 06.08.,
     * Lauf 86 09.10.). Der Matcher selbst und die Vorschlagsliste für Menschen bleiben unberührt.
     *
     * @param  array{status?: MatchBand|string}  $treffer
     */
    public static function istAutomatischVerdrahtbar(array $treffer): bool
    {
        $band = $treffer['status'] ?? null;
        $band = is_string($band) ? MatchBand::tryFrom($band) : $band;

        return $band === MatchBand::Exact || $band === MatchBand::FuzzyHigh;
    }

    public function matchIngredient(
        Team $team,
        string $ingredientName,
        ?string $hauptzutatSlug = null,
        string $mode = 'gp_first',
        string $pref = 'neutral',
        bool $preferRaw = false,
        string $bio = 'neutral',
        bool $basisVariante = true,   // 27.10.: Basisvarianten-Regel (Bindung); reine Erkennung (Zukauf/Teilfertig) schaltet sie ab
        string $convenience = 'standard',   // 27.10. (d): Convenience-Leitplanke — teil/voll erlaubt das Zukauf-GP für Basiskomponenten
        ?string $einheit = null,            // 28.10.: Einheit der Zeile (Stück → ganze Gewürzform)
        ?string $zubereitung = null,        // 29.10.: eigen | zukauf | ohne aus den Leitplanken (MatchLeitplanken::zubereitung)
    ): array {
        // Kurator 28.10. (1): „Vollei“/„Volleier“ ohne flüssig/pasteurisiert meint das Schalenei — Flüssigei nur, wenn der Text es sagt
        if (preg_match('/(?<![\p{L}])voll-?ei(?:er)?(?![\p{L}])/iu', $ingredientName) === 1 && preg_match('/fl(?:ü|ue)ssig|pasteuris/iu', $ingredientName) !== 1) {
            $ingredientName = (string) preg_replace('/(?<![\p{L}])voll-?ei(?:er)?(?![\p{L}])/iu', 'Eier', $ingredientName);
        }
        $this->einheit = $einheit;
        $queryTokens = $this->engine->ingredientTokens($ingredientName);
        $querySlug = $hauptzutatSlug !== null && trim($hauptzutatSlug) !== '' ? $hauptzutatSlug : null;

        if ($queryTokens === [] && $querySlug === null) {
            return $this->noMatch(0.0);
        }

        // Dominique 27.10. (d): Basiskomponente (Fond, Jus, Sauce, Dressing, Teig …) ohne Zukauf-Signal = Unterrezept — zuerst den
        // Basisrezept-Bestand (sub_recipe_first, auch im gp_first-Lauf), nie automatisch ein Zukauf-GP (WG 13). VOR dem Zustand unten:
        // die Zukauf-Prüfung ruft den Matcher für andere Texte („Petersilienmatte“) und setzt dabei denselben Zustand.
        $weg = $basisVariante ? $this->zubereitungsWeg($team, $ingredientName, $convenience, $zubereitung) : null;
        // S2 (#507 Weg-2): die Pool-Scans dieser Entscheidung dürfen nie auf einer
        // bekannten Verwechslungs-Falle landen (Brie↛Bries etc.). Synchron gesetzt,
        // in bestGpMatch/bestSubrecipeMatch gelesen (kein Threading durch poolLauf).
        $this->currentIngredientName = $ingredientName;
        $this->tiebreakMemo = [];
        $this->tiebreakQuery = $this->engine->ingredientTokens($ingredientName);
        $this->aliasTokens = null;
        if ($weg === 'zukauf' && $this->zukaufTreffer !== null) {   // 29.10.: Leitplanken wählen das Zukauf-GP, das die Prüfung gefunden hat (auch „Fond: Geflügel“ → „Geflügelfond“)
            return app(Matching\BasisVariante::class)->pruefe($team, $ingredientName, $this->zukaufTreffer, $pref === 'neutral' ? 'fresh_first' : $pref, $bio, $einheit);
        }
        $this->basisKomponente = $weg === 'basis';
        $zukaufHinweis = $this->basisKomponente ? $this->zukaufHinweis : null;
        if ($this->basisKomponente) {
            $mode = 'sub_recipe_first';
        }

        // Kurator 28.10. (5): die Aliasse sehen die Zutat ohne Menge/Einheit („200 g Sahne“ trifft den §5-Default wie „Sahne“)
        $aliasQuery = $this->engine->ingredientTokens(\Platform\FoodAlchemist\Support\ZutatFreitext::ohneMenge($ingredientName)) ?: $queryTokens;

        // 4.4n — §4-Default-Sub-Alias hat VORRANG (deterministisch, Existenz-Guard)
        if (($aliasName = $this->heuristik->defaultSubAlias($aliasQuery)) !== null) {
            $ziel = $this->resolveSubByName($team, $aliasName);
            if ($ziel !== null) {
                return [
                    'target' => 'sub_recipe', 'status' => MatchBand::fuerScore(MatchHeuristics::SUB_ALIAS_SCORE),
                    'gp_id' => null, 'gp_name' => null,
                    'recipe_id' => $ziel['id'], 'recipe_name' => $ziel['name'],
                    'score' => MatchHeuristics::SUB_ALIAS_SCORE,
                ];
            }
        }

        // 4.4s — §5-Default-GP-Alias (sichere Degradation)
        if (($aliasName = $this->heuristik->defaultGpAlias($aliasQuery, $preferRaw)) !== null) {
            $ziel = $this->resolveGpByName($team, $aliasName);
            // 27.10. (Pfeffer weiss: ganz → gemahlen): nennt die Zeile ausdrücklich eine Form, die das Default-GP nicht trägt, entscheidet der Pool
            $queryForm = $this->engine->produktForm($ingredientName)['form'];
            // 28.10. (Nachmessung „100 ml Milch (1,5 %)“ → 3,5 %): ein genannter Prozent-/Type-Wert, den das Default-GP nicht trägt, entscheidet der Pool
            if ($ziel !== null && $this->acceptsProductForm($ingredientName, $ziel['name'], $ziel['condition'])
                && ($queryForm === null || in_array($queryForm, $this->engine->tokenize($ziel['name']), true))
                && ! \Platform\FoodAlchemist\Support\ZutatFreitext::fremdeWertangaben($ingredientName, (string) $ziel['name'])) {
                return [
                    'target' => 'gp', 'status' => MatchBand::fuerScore(MatchHeuristics::DEFAULT_GP_ALIAS_SCORE),
                    'gp_id' => $ziel['id'], 'gp_name' => $ziel['name'],
                    'recipe_id' => null, 'recipe_name' => null,
                    'score' => MatchHeuristics::DEFAULT_GP_ALIAS_SCORE,
                ];
            }
        }

        $final = $this->poolLauf($team, $queryTokens, $querySlug, $mode, $pref, $preferRaw, $bio);

        // E7-a (#507 Weg-2): S1-Alias-Phrasen der Terminologie-Schicht auch in die
        // ENTSCHEIDUNG ziehen (bisher nur in candidatesFor/Shortlist → „Paradeiser" stand
        // in der Kandidatenliste, gewann aber im Urteil nicht). Jede Alias-Phrase ist eine
        // eigenständige Query, wir nehmen das Maximum. Rein additiv, feuert NUR unter der
        // Schwelle → die 84 GL-04-Goldens bleiben byte-identisch. Anti-Marker (S2) bleibt
        // auf dem ORIGINAL-Namen (Verwechslungs-Schutz der Absicht). S3-Decompound hat die
        // Entscheidung bereits über den flag-gegateten decompoundTokens-Fallback unten.
        if ($final['score'] < MatchHeuristics::MIN_MATCH_SCORE) {
            foreach ($this->terminology->aliasPhrasesFor($ingredientName) as $phrase) {
                $ptok = $this->engine->tokenize($phrase);
                if ($ptok === []) {
                    continue;
                }
                $kandidat = $this->poolLauf($team, $ptok, $querySlug, $mode, $pref, $preferRaw, $bio);
                if ($kandidat['score'] > $final['score']) {
                    $final = $kandidat;
                }
            }
        }

        // M6-07 / V-05 (Audit-Hebel 4): Decompounding-FALLBACK — läuft NUR, wenn
        // der v1-Lauf unter der Schwelle bleibt (additiv; GL-04-Goldens unberührt).
        // »Kürbispüree« → kuerbis+pueree, validiert gegen das Pool-Token-Vokabular.
        if ($final['score'] < MatchHeuristics::MIN_MATCH_SCORE
            && config('foodalchemist.matching.decompound', true)) {
            $erweitert = $this->decompoundTokens($team, $queryTokens);
            if ($erweitert !== null) {
                $zweiter = $this->poolLauf($team, $erweitert, $querySlug, $mode, $pref, $preferRaw, $bio);
                if ($zweiter['score'] > $final['score']) {
                    $final = $zweiter;
                }
            }
        }

        // §2 VERARBEITUNGS-REDUKTION (Regelwerk Basisrezepte): eine Schnittform ist keine
        // Identität. Das Regelwerk verlangt die Reduktion auf die Rohform — der Code tat es
        // nicht, und die Schnittform machte den Treffer sogar KAPUTT. Gemessen auf demo
        // (2026-09-03):
        //
        //   »Karotten, Brunoise« → 1. «Gemuesemix: TK, Brunoises» 0,742 · 4. «Karotten: TK,
        //                            Baby» 0,686 → Entscheid NONE
        //   »Karotten«           → 1. «Moehren / Karotten: …»     1,001 → Entscheid GP
        //
        // `brunoise` wurde wie ein Identitäts-Token gewertet und zog einen GEMÜSEMIX über
        // die echte Karotte. `isQualifierToken('brunoise')` war längst true — die Bewertung
        // nutzte es nur nicht. Dieselbe Lage bei »Zwiebeln gehackt«.
        //
        // Rein additiv wie die beiden Fallbacks darüber: feuert NUR unter der Schwelle, die
        // Goldens bleiben unberührt. Bewusst NUR Schnittformen (nicht alle Qualifier) —
        // Zustand und Verarbeitungsgrad sind eigene Achsen und dürfen nicht verschwinden.
        if ($final['score'] < MatchHeuristics::MIN_MATCH_SCORE) {
            $ohneZuschnitt = array_values(array_filter(
                $queryTokens,
                fn (string $t): bool => ! $this->engine->isCutFormToken($t),
            ));
            // Nur wenn wirklich ein Zuschnitt wegfiel UND eine Identität übrig bleibt.
            if ($ohneZuschnitt !== [] && count($ohneZuschnitt) < count($queryTokens)) {
                $reduziert = $this->poolLauf($team, $ohneZuschnitt, $querySlug, $mode, $pref, $preferRaw, $bio);
                if ($reduziert['score'] > $final['score']) {
                    $final = $reduziert;
                }
            }
        }

        // 27.10. (Dominique: Freitext aus Internetrezept, Kundenzettel, Foto): Menge/Einheit vorn, Füllwörter („gutes“, „zum Braten“), Klammern
        // und Plural (Lorbeerblaetter) — wie die Fallbacks darüber nur unter der Schwelle, bestehende Treffer bleiben unberührt.
        if ($final['score'] < MatchHeuristics::MIN_MATCH_SCORE) {
            foreach ([...\Platform\FoodAlchemist\Support\ZutatFreitext::varianten($ingredientName), $ingredientName] as $variante) {
                $vt = $this->engine->ingredientTokens($variante);
                foreach (array_unique([implode(' ', $vt), implode(' ', array_map(\Platform\FoodAlchemist\Support\ZutatFreitext::lemma(...), $vt))]) as $folge) {
                    if ($folge === '' || $folge === implode(' ', $queryTokens)) {
                        continue;
                    }
                    $kandidat = $this->poolLauf($team, explode(' ', $folge), $querySlug, $mode, $pref, $preferRaw, $bio);
                    if ($kandidat['score'] > $final['score']) {
                        $final = $kandidat;
                    }
                }
            }
        }

        if ($this->basisKomponente) {
            return $this->alsBasiskomponente($team, $ingredientName, $final, $pref, $bio) + ($zukaufHinweis !== null ? ['hinweis' => $zukaufHinweis] : []);
        }

        // 27.10. (Kurator): ohne genannte Variante nie automatisch Bio/Marke/Zuschnitt/Fettstufe — Basisvariante oder Review
        return $final['score'] < MatchHeuristics::MIN_MATCH_SCORE
            ? $this->noMatch($final['score'])
            : ($basisVariante ? app(Matching\BasisVariante::class)->pruefe($team, $ingredientName, $final, $pref === 'neutral' ? 'fresh_first' : $pref, $bio, $this->einheit) : $final);
    }

    /** Einheit der aktuellen Zeile (28.10.: Stück → ganze Gewürzform). */
    private ?string $einheit = null;

    /** @var array<int, list<list<string>>> Produktnamen je Team: Hauptzutat-Tokens trockener Fertigprodukte (WG 12/13) */
    private array $produktnamenCache = [];

    /** @var array<string, bool> Teil|Wort → Frucht im Bestand (WG 02) */
    private array $fruchtCache = [];

    /** Basiskomponenten-Lauf (27.10. d) der aktuellen Entscheidung — steuert poolLauf und den Abschluss. */
    private bool $basisKomponente = false;

    /** Zukauf-Signale einer Basiskomponenten-Zeile (Kurator 27.10. d): Einkaufsform, Konzentrat, Glas, „Zukauf“/„fertig“. */
    private const ZUKAUF_SIGNAL = ['pulver', 'paste', 'konzentrat', 'glas', 'zukauf', 'fertig', 'fertigprodukt', 'instant', 'granulat', 'tk', 'convenience'];

    /** @var array<int, list<string>> bekannte Marken (GP-Klammer-Zusatz) je Team, normalisiert */
    private array $markenCache = [];

    /**
     * Basiskomponente ohne Zukauf-Signal und ohne Convenience-Leitplanke (Dominique 27.10. d) → Unterrezept bzw. Stub, kein Zukauf-GP.
     * Zukauf-Signal: Pulver/Paste/Konzentrat/Glas/„Zukauf“/„fertig“, eine Einkaufsform (TK, trocken, konserviert) oder eine bekannte Marke.
     */
    public function basiskomponenteOhneZukauf(Team $team, string $text, string $convenience = 'standard', ?string $zubereitung = null): bool
    {
        return $this->zubereitungsWeg($team, $text, $convenience, $zubereitung) === 'basis';
    }

    /** „Öl: neutral“ & Co. ist nie ein Unterrezept, sondern das §5-Standard-GP (Rapsöl). */
    private const OEL_NEUTRAL = ['neutral', 'pflanzen', 'pflanzenoel', 'raps', 'rapsoel', 'speise', 'speiseoel', 'brat', 'bratoel', 'frittier', 'frittieroel', 'sonnenblumen', 'sonnenblumenoel'];

    /** Hinweis „gibt es auch als Zukauf: …“ der letzten {@see zubereitungsWeg}-Entscheidung (nur ohne Leitplanke mit GP im Bestand). */
    private ?string $zukaufHinweis = null;

    /** Zukauf-GP-Treffer der letzten {@see zubereitungsWeg}-Entscheidung (Weg 'zukauf'). */
    private ?array $zukaufTreffer = null;

    /**
     * Weg einer Zeile: null = keine Basiskomponente bzw. Zukauf-Signal, 'basis' = Unterrezept/Stub, 'zukauf' = Zukauf-GP im Bestand.
     * Dominique 29.10.: „im Convenience-Bereich kannst du alles fertig kaufen“ — für JEDE Basiskomponente (Erkennung wie bisher, Ausnahmen
     * bleiben) entscheiden die Leitplanken ({@see Matching\MatchLeitplanken::zubereitung}); die Grenze zieht allein der Bestand: Zukauf nur,
     * wenn ein passendes GP da ist, sonst Unterrezept. Ohne Leitplanke {@see Matching\MatchLeitplanken::OHNE_VORGABE} + Hinweis aufs Zukauf-GP.
     */
    public function zubereitungsWeg(Team $team, string $text, string $convenience = 'standard', ?string $zubereitung = null): ?string
    {
        $this->zukaufHinweis = $this->zukaufTreffer = null;
        $pos = mb_strpos($text, ':');
        if ($pos !== false && $pos > 0 && $this->engine->tokenize(mb_substr($text, 0, $pos)) === ['oel']
            && array_intersect($this->engine->tokenize(mb_substr($text, $pos + 1)), self::OEL_NEUTRAL) !== []) {
            return null;
        }
        if (! $this->basisOhneZukaufSignal($team, $text)) {
            return null;
        }
        $weg = $zubereitung ?? Matching\MatchLeitplanken::zubereitung(['convenience' => $convenience]);
        $gp = $weg !== 'eigen' ? $this->zukaufGp($team, $text) : null;
        if ($weg === 'ohne') {
            $weg = Matching\MatchLeitplanken::OHNE_VORGABE;
            $this->zukaufHinweis = $gp !== null && $weg !== 'zukauf' ? 'Gibt es auch als Zukauf: ' . ($gp['gp_name'] ?? 'GP #' . $gp['gp_id']) : null;
        }

        $this->zukaufTreffer = $weg === 'zukauf' ? $gp : null;

        return $weg === 'zukauf' && $gp !== null ? 'zukauf' : 'basis';
    }

    /**
     * Passendes Zukauf-GP im Bestand (ab MIN_MATCH_SCORE), reine Erkennung ohne Basisvarianten-Regel — keine Rekursion. Rezeptnamen-Syntax
     * „Fond: Geflügel“ heißt als Ware meist „Geflügelfond“: Kompositum mit Fugenlaut mitprüfen (wie „Öl (Raps)“ → „Rapsöl“, ZutatFreitext).
     */
    private function zukaufGp(Team $team, string $text): ?array
    {
        $kandidaten = [$text];
        if (preg_match('/^\s*(\p{L}+)\s*:\s*(\p{L}+)\s*$/u', $text, $m) === 1) {
            foreach (['', 'n', 's', 'en'] as $fuge) {
                $kandidaten[] = $m[2] . $fuge . mb_strtolower($m[1]);
            }
        }
        $einheit = $this->einheit;
        $treffer = null;
        foreach ($kandidaten as $k) {
            $t = $this->matchIngredient($team, $k, null, 'gp_first', basisVariante: false);
            if (($t['target'] ?? null) === 'gp' && (float) ($t['score'] ?? 0) >= MatchHeuristics::MIN_MATCH_SCORE) {
                $treffer = $t;
                break;
            }
        }
        $this->einheit = $einheit;

        return $treffer;
    }

    private function basisOhneZukaufSignal(Team $team, string $text): bool
    {
        if (! $this->heuristik->istBasiskomponente($text)) {
            return false;
        }
        $tokens = $this->engine->tokenize($text);
        if (array_filter($tokens, fn ($t) => in_array($t, self::ZUKAUF_SIGNAL, true) || str_ends_with($t, 'pulver') || str_ends_with($t, 'konzentrat')) !== []
            || in_array($this->engine->produktForm($text)['zustand'], ['TK', 'trocken', 'konserviert'], true)) {
            return false;
        }
        // Regelwerk Basisrezepte §3 (Kurator 28.10.): Frucht-Püree ist immer Zukauf (Boiron-TK) — jede Frucht des Bestands (WG 02), nicht nur die Liste
        if ($this->istFruchtPueree($team, $tokens)) {
            return false;
        }
        // Produktname aus dem Bestand („Pro Espuma“): Hauptzutat eines trockenen Fertigprodukts (WG 12/13), mindestens zwei Wörter
        if ($this->nenntProduktnamen($team, $tokens)) {
            return false;
        }
        // Markenname in Klammern („Geflügelfond (Knorr)“): bekannt aus dem Klammer-Zusatz der GPs
        if (preg_match_all('/\(([^)]+)\)/u', $text, $m) > 0) {
            $marken = $this->markenCache[$team->id] ??= FoodAlchemistGp::visibleToTeam($team)->whereNotNull('zusatz')->where('zusatz', '!=', '')
                ->distinct()->pluck('zusatz')->map(fn ($z) => implode(' ', $this->engine->tokenize((string) $z)))->filter()->values()->all();
            foreach ($m[1] as $klammer) {
                if (in_array(implode(' ', $this->engine->tokenize($klammer)), $marken, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Püree aus einer Frucht des Bestands („Johannisbeerpüree“, „Püree: Mango“)? Gemüse-Püree bleibt Unterrezept (§3). */
    private function istFruchtPueree(Team $team, array $tokens): bool
    {
        $pueree = array_values(array_filter($tokens, fn ($t) => str_ends_with($t, 'pueree') || str_ends_with($t, 'puree')));
        if ($pueree === []) {
            return false;
        }
        $kandidaten = [];
        foreach ($pueree as $t) {
            $kandidaten[] = preg_replace('/p(?:ue|u)ree$/', '', $t);
        }
        $kandidaten = [...$kandidaten, ...array_diff($tokens, $pueree)];   // „Püree: Mango“, „Mango-Püree“
        foreach ($kandidaten as $k) {
            foreach (array_unique([$k, preg_replace('/(?:en|n)$/', '', $k)]) as $wort) {
                if (mb_strlen($wort) < 4) {
                    continue;
                }
                if ($this->fruchtCache[$team->id . '|' . $wort] ??= FoodAlchemistGp::visibleToTeam($team)->where('commodity_group_code', '02')
                    ->whereIn('status', ['approved', 'tentative'])->where('main_ingredient_slug', 'like', $wort . '%')->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Nennt die Zeile ein trockenes Fertigprodukt des Bestands (WG 12/13, Hauptzutat ab zwei Wörtern) — „Pro Espuma“? */
    private function nenntProduktnamen(Team $team, array $tokens): bool
    {
        $produkte = $this->produktnamenCache[$team->id] ??= FoodAlchemistGp::visibleToTeam($team)->whereIn('commodity_group_code', ['12', '13'])
            ->whereIn('status', ['approved', 'tentative'])->whereRaw('LOWER(`condition`) = ?', ['trocken'])->get(['name', 'zusatz'])
            ->map(fn ($g) => $this->engine->tokenize((string) $this->naming()->felderAusName($this->naming()->nameOhneMarke((string) $g->name, $g->zusatz))['hauptzutat']))
            ->filter(fn ($h) => count($h) >= 2)->values()->all();
        foreach ($produkte as $h) {
            if (array_diff($h, $tokens) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abschluss einer Basiskomponenten-Zeile: Basisrezept-Treffer bleibt (`basiskomponente` = rezept); ein GP aus der Rohware/Würzware
     * (nicht WG 13 Convenience & Komponenten, nicht WG 09 Teige/Backwaren) war ein Fehlalarm des Typ-Worts (Sojasauce, Crème fraîche, Spargel) und läuft normal weiter;
     * sonst kein Zukauf-GP — FuzzyLow (Review mit Vorschlag) bzw. offen, `basiskomponente` = stub (Regelwerk Basisrezepte §4 F4.1).
     */
    private function alsBasiskomponente(Team $team, string $ingredientName, array $final, string $pref, string $bio): array
    {
        $ok = $final['score'] >= MatchHeuristics::MIN_MATCH_SCORE;
        if ($ok && ($final['target'] ?? null) === 'sub_recipe') {
            return $final + ['basiskomponente' => 'rezept'];
        }
        if ($ok && ($final['target'] ?? null) === 'gp'
            && ! in_array((string) FoodAlchemistGp::query()->whereKey((int) $final['gp_id'])->value('commodity_group_code'), ['13', '09'], true)) {
            return app(Matching\BasisVariante::class)->pruefe($team, $ingredientName, $final, $pref === 'neutral' ? 'fresh_first' : $pref, $bio, $this->einheit);
        }

        return ($ok && ($final['target'] ?? null) === 'gp' ? ['status' => MatchBand::FuzzyLow] + $final : $this->noMatch($final['score'])) + ['basiskomponente' => 'stub'];
    }

    /** 4.4k/l — Pool-Priorität (Halbfabrikat-Gate × Modus, §4.2) — v1-Kernlauf. */
    private function poolLauf(Team $team, array $queryTokens, ?string $querySlug, string $mode, string $pref, bool $preferRaw, string $bio): array
    {
        // 27.10. (d): Basiskomponente — ein Basisrezept ab der Sub-Schwelle gewinnt immer, auch ohne Halbfabrikat-Marker (Marinade, Teig, Creme)
        if ($this->basisKomponente) {
            $subBest = $this->bestSubrecipeMatch($team, $queryTokens, $querySlug);
            if (($subBest['score'] ?? 0.0) >= MatchHeuristics::SUB_PRIORITY_THRESHOLD) {
                return $subBest;
            }

            return $this->bestGpMatch($team, $queryTokens, $querySlug, $pref, $preferRaw, $bio) ?? $subBest ?? $this->noMatch(0.0);
        }
        if ($this->heuristik->queryIstHalbfabrikat($queryTokens)) {
            $subBest = $this->bestSubrecipeMatch($team, $queryTokens, $querySlug);
            $gpBest = $this->bestGpMatch($team, $queryTokens, $querySlug, $pref, $preferRaw, $bio);
            $subScore = $subBest['score'] ?? 0.0;
            $preferSub = $mode === 'sub_recipe_first'
                ? $subScore >= MatchHeuristics::SUB_PRIORITY_THRESHOLD
                : $subScore >= MatchHeuristics::SUB_EXACT_OVERRIDE;

            return $preferSub
                ? ($subBest ?? $gpBest ?? $this->noMatch(0.0))
                : $this->besserer($gpBest, $subBest);
        }
        $gpBest = $this->bestGpMatch($team, $queryTokens, $querySlug, $pref, $preferRaw, $bio);
        $subBest = ($gpBest['score'] ?? 0.0) < MatchHeuristics::GP_PRIORITY_THRESHOLD
            ? $this->bestSubrecipeMatch($team, $queryTokens, $querySlug)
            : null;

        return $this->besserer($gpBest, $subBest);
    }

    /**
     * V-05: 2-Split je Kompositum-Token (≥8 Z.) gegen das Pool-Token-Vokabular
     * (Basisrezept- + GP-Namen, je Team gecacht); Fugen-s/-n toleriert.
     * null = nichts zerlegbar (kein zweiter Lauf).
     *
     * @param  list<string>  $queryTokens
     * @return ?list<string>
     */
    private function decompoundTokens(Team $team, array $queryTokens): ?array
    {
        $vokabular = $this->poolVokabular($team);
        $erweitert = [];
        $gefunden = false;
        foreach ($queryTokens as $tok) {
            $erweitert[] = $tok;
            if (mb_strlen($tok) < 8) {
                continue;
            }
            for ($i = 4; $i <= mb_strlen($tok) - 4; $i++) {
                $kopf = mb_substr($tok, 0, $i);
                $rest = mb_substr($tok, $i);
                // Fugen-Element am Kopf-Ende tolerieren (rinds|braten → rind)
                $kopfBasis = isset($vokabular[$kopf]) ? $kopf
                    : (in_array(mb_substr($kopf, -1), ['s', 'n'], true) && isset($vokabular[mb_substr($kopf, 0, -1)])
                        ? mb_substr($kopf, 0, -1) : null);
                if ($kopfBasis !== null && isset($vokabular[$rest])) {
                    $erweitert[] = $kopfBasis;
                    $erweitert[] = $rest;
                    $gefunden = true;
                    break;
                }
            }
        }

        return $gefunden ? array_values(array_unique($erweitert)) : null;
    }

    /** @var array<int, array<string, true>> Pool-Token-Vokabular je Team (Request-Cache) */
    private array $vokabularCache = [];

    private function poolVokabular(Team $team): array
    {
        if (isset($this->vokabularCache[$team->id])) {
            return $this->vokabularCache[$team->id];
        }
        $vokabular = [];
        foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->pluck('name') as $name) {
            foreach ($this->engine->tokenize($name) as $t) {
                if (mb_strlen($t) >= 4) {
                    $vokabular[$t] = true;
                }
            }
        }
        foreach (FoodAlchemistGp::visibleToTeam($team)->where('is_platzhalter', false)->pluck('name') as $name) {
            foreach ($this->engine->tokenize($name) as $t) {
                if (mb_strlen($t) >= 4) {
                    $vokabular[$t] = true;
                }
            }
        }

        return $this->vokabularCache[$team->id] = $vokabular;
    }

    /**
     * 4.4p — Top-K-Shortlist aus BEIDEN Pools für die grounded LLM-Disambiguierung
     * (Rang = max(strikt, Substring-Overlap); Referenz-Token gp:<id> / sub:<id>).
     *
     * @return array<int, array{kind: string, id: int, name: string, score: float, reference: string}>
     */
    public function candidatesFor(Team $team, string $ingredientName, ?string $hauptzutatSlug = null, int $k = 5): array
    {
        $this->tiebreakMemo = [];
        $this->tiebreakQuery = $this->engine->ingredientTokens($ingredientName);
        $queryTokens = $this->engine->ingredientTokens($ingredientName);
        $querySlug = $hauptzutatSlug !== null && trim($hauptzutatSlug) !== '' ? $hauptzutatSlug : null;
        if ($queryTokens === [] && $querySlug === null) {
            return [];
        }

        // S1 (#507 Weg-2): Alias-Phrasen (Dialekt/Übersetzung, „Paradeiser"→„tomate").
        // Union der Tokens erweitert NUR den Prefilter (fetcht den Alias-Kandidaten);
        // der SCORE kommt aus max(Original, jede Alias-Variante einzeln) — so bekommt
        // der Alias-Treffer VOLLEN Score statt im Token-Bag zu verwässern. Feuert nur
        // bei bekanntem Alias; Standardnamen bleiben unberührt.
        // S1 Alias-Phrasen + S3 Decompound-Phrasen (Kürbispüree→„kürbis püree")
        // laufen durch dasselbe Best-über-Varianten-Scoring.
        $expandPhrases = array_merge(
            $this->terminology->aliasPhrasesFor($ingredientName),
            $this->terminology->decompoundPhrasesFor($ingredientName),
        );
        $aliasVariants = array_values(array_filter(
            array_map(fn ($p) => $this->engine->tokenize($p), $expandPhrases),
            static fn ($v) => $v !== [],
        ));
        $poolTokens = $queryTokens;
        foreach ($aliasVariants as $v) {
            $poolTokens = array_merge($poolTokens, $v);
        }
        $poolTokens = array_values(array_unique($poolTokens));

        // Lexikalischer Pool → keyed map "kind\0id".
        //
        // ENGE SONDEN ZUSÄTZLICH ZUM BREITEN POOL. `gpPool()` kappt bei 300 und sortiert
        // dabei nach `id` — also willkürlich, nicht nach Relevanz. Sobald Alias-/
        // Decompound-Erweiterung den Treffer-Raum über 300 aufbläht, fallen die HOHEN IDs
        // heraus: neu kuratierte Grundprodukte werden systematisch unsichtbar, je mehr
        // Bestand da ist. Gemessen auf demo (2026-09-03, 28 Zutaten mit Erweiterung):
        // 17 liefen ins Limit, 15 verloren dabei exakte Kandidaten — „Sherryessig" verlor
        // genau das eine richtige GP, „Pflanzenöl" und „Rapsöl" verloren
        // «Rapsoel / Pflanzenoel», „Weissweinessig" 259 Kandidaten.
        //
        // Darum wird der breite Pool mit zwei ENGEN Abfragen vereinigt, die kaum je ins
        // Limit laufen: den Original-Tokens und dem längsten Einzel-Token (bei deutschen
        // Zutaten-Komposita der unterscheidende Teil). Rein additiv — Scoring und Ranking
        // bleiben unberührt, es kann nur MEHR gefunden werden.
        $lex = [];
        foreach ($this->poolMitEngenSonden($team, $poolTokens, $queryTokens, $querySlug) as $gp) {
            if (! $this->acceptsProductForm($ingredientName, $gp->name, $gp->condition, $gp)) {
                continue;
            }
            $scoreName = $this->naming()->nameOhneMarke((string) $gp->name, $gp->zusatz);   // Marken-Klammer zählt nicht (wie bestGpMatch)
            $combined = trim($scoreName . ' ' . ($gp->main_ingredient_display ?? ''));
            $score = $this->bestLexScore($queryTokens, $aliasVariants, $querySlug, $combined, $gp->main_ingredient_slug, $scoreName);
            if ($score > 0.0) {
                $lex["gp\0{$gp->id}"] = ['kind' => 'gp', 'id' => (int) $gp->id, 'name' => $gp->name, 'score' => $score, 'reference' => "gp:{$gp->id}"];
            }
        }
        foreach ($this->subPoolMitEngenSonden($team, $poolTokens, $queryTokens, $querySlug) as $sub) {
            $score = $this->bestLexScore($queryTokens, $aliasVariants, $querySlug, $sub->name, null, $sub->name);
            if ($score > 0.0) {
                $lex["sub\0{$sub->id}"] = ['kind' => 'sub', 'id' => (int) $sub->id, 'name' => $sub->name, 'score' => $score, 'reference' => "sub:{$sub->id}"];
            }
        }

        // Legacy-Pfad: Semantik aus ⇒ exakt wie bisher (nur Herkunfts-Marker + S2).
        if ($this->semantic === null || ! $this->semantic->enabled()) {
            $out = array_map(static function ($c) {
                $c['origin'] = 'lexical';

                return $c;
            }, array_values($lex));
            $out = $this->stripAntiMarkers($ingredientName, $out);
            usort($out, $this->kandidatenSortierung($team));

            return array_slice($out, 0, $k);
        }

        return $this->hybridMerge($team, $ingredientName, $lex, $k);
    }

    /**
     * E2 (#507): additiver Hybrid-Re-Rank (Plan §4 / GL-04 §6.1 V-04). Score:
     *  both → max(lexikalisch, cosine) · nur semantisch → cosine ·
     *  nur lexikalisch (kein Cosine für diese Query) → lexikalisch × 0.5.
     * 'origin' trägt die Herkunft (lexical|semantic|both) für Audit/UI. Bleibt
     * eine SHORTLIST — die Match-Entscheidung (matchIngredient) ist unberührt.
     *
     * @param  array<string, array{kind:string,id:int,name:string,score:float,reference:string}>  $lex
     * @return array<int, array{kind: string, id: int, name: string, score: float, reference: string, origin: string}>
     */
    private function hybridMerge(Team $team, string $ingredientName, array $lex, int $k): array
    {
        $cap = max($k * 3, (int) config('foodalchemist.semantic_search.pool_cap', 15));
        $hits = $this->semantic->candidates(
            $team,
            $ingredientName,
            [PoolEmbeddingService::ENTITY_TYPE_GP, PoolEmbeddingService::ENTITY_TYPE_RECIPE],
            $cap,
        );

        $cos = [];              // "kind\0id" => cosine
        $gpIds = $subIds = [];
        foreach ($hits as $h) {
            if ($h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_GP) {
                $cos["gp\0" . (int) $h['entity_id']] = (float) $h['score'];
                $gpIds[] = (int) $h['entity_id'];
            } elseif ($h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_RECIPE) {
                $cos["sub\0" . (int) $h['entity_id']] = (float) $h['score'];
                $subIds[] = (int) $h['entity_id'];
            }
        }

        // Namen + Eligibilität der SEMANTIK-ONLY-Kandidaten (gleiche Filter wie die
        // Lexik-Pools — nichts Unsichtbares/Rejected/VK darf durch die Semantik rein).
        $names = [];
        $gpMissing = array_values(array_filter($gpIds, static fn ($id) => ! isset($lex["gp\0$id"])));
        if ($gpMissing !== []) {
            foreach (FoodAlchemistGp::visibleToTeam($team)->lebensmittel()->whereIn('status', ['approved', 'tentative'])
                ->where('is_platzhalter', false)->whereIn('id', $gpMissing)->get(['id', 'name', 'condition', 'commodity_group_code', 'sub_category', 'main_ingredient_display', 'zusatz']) as $g) {
                if (! $this->acceptsProductForm($ingredientName, $g->name, $g->condition, $g)) {
                    continue;
                }
                $names["gp\0{$g->id}"] = $g->name;
            }
        }
        $subMissing = array_values(array_filter($subIds, static fn ($id) => ! isset($lex["sub\0$id"])));
        if ($subMissing !== []) {
            foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->whereIn('status', ['stub', 'draft', 'review', 'approved'])
                ->whereIn('id', $subMissing)->get(['id', 'name']) as $r) {
                $names["sub\0{$r->id}"] = $r->name;
            }
        }

        $merged = [];
        foreach ($lex as $key => $c) {
            if (isset($cos[$key])) {
                $c['score'] = max((float) $c['score'], $cos[$key]);
                $c['origin'] = 'both';
            } else {
                $c['score'] = (float) $c['score'] * 0.5;
                $c['origin'] = 'lexical';
            }
            $merged[$key] = $c;
        }
        foreach ($cos as $key => $score) {
            if (isset($merged[$key]) || ! isset($names[$key])) {
                continue;
            }
            [$kind, $id] = explode("\0", $key, 2);
            $merged[$key] = [
                'kind' => $kind, 'id' => (int) $id, 'name' => $names[$key],
                'score' => $score, 'reference' => "{$kind}:{$id}", 'origin' => 'semantic',
            ];
        }

        $out = $this->stripAntiMarkers($ingredientName, array_values($merged));
        usort($out, $this->kandidatenSortierung($team));

        return array_slice($out, 0, $k);
    }

    /**
     * S1 (#507 Weg-2): bester Lexik-Score über die Original-Query UND jede Alias-
     * Variante einzeln. So zählt „Karotte" (Alias von „Möhre") mit VOLLEM Score,
     * statt im gemeinsamen Token-Bag verwässert aus den Top-K zu fallen. Alias-
     * Varianten tragen keinen Slug (nur die Original-Query).
     *
     * @param  list<string>  $queryTokens
     * @param  list<list<string>>  $aliasVariants
     */
    private function bestLexScore(array $queryTokens, array $aliasVariants, ?string $querySlug, string $candText, ?string $candSlug, string $candName): float
    {
        $best = max(
            $this->scoreMitFloor($queryTokens, $querySlug, $candText, $candSlug, $candName),
            $this->heuristik->substringOverlap($queryTokens, $candText),
        );
        // Alias-Varianten NUR token-strikt (kein substringOverlap): ein kurzes
        // generisches Alias-Token wie „rind" darf nicht als Substring dutzende
        // GPs („Rinderbrühe", „Rinde") auf 1.0 fluten und den echten Treffer im
        // Gleichstand aus den Top-K drängen. Ganz-Token-Match reicht für Aliase.
        foreach ($aliasVariants as $variant) {
            $s = $this->scoreMitFloor($variant, null, $candText, $candSlug, $candName);
            if ($s > $best) {
                $best = $s;
            }
        }

        // Ganz-Token-Bonus: ein echter Wort-Treffer (Query- ODER Alias-Token ==
        // ein Kandidaten-Token) rankt minimal über einen reinen Teil-Substring-
        // Treffer (Sahne > Rahmspinat für „Rahm", Roastbeef⊃beef verliert gegen
        // Rindfleisch). Bricht nur Gleichstände, verändert die Bänder nicht.
        if ($best > 0.0 && $this->hasWholeTokenMatch($queryTokens, $aliasVariants, $candText)) {
            $best += self::WHOLE_TOKEN_BONUS;
        }

        return $best;
    }

    private const WHOLE_TOKEN_BONUS = 0.001;

    /**
     * Gibt es einen EXAKTEN Wort-Treffer (nicht nur Teil-Substring) zwischen der
     * Query/den Alias-Varianten und dem Kandidatentext?
     *
     * @param  list<string>  $queryTokens
     * @param  list<list<string>>  $aliasVariants
     */
    private function hasWholeTokenMatch(array $queryTokens, array $aliasVariants, string $candText): bool
    {
        $candSet = array_flip($this->engine->tokenize($candText));
        foreach ($queryTokens as $t) {
            if (isset($candSet[$t])) {
                return true;
            }
        }
        foreach ($aliasVariants as $variant) {
            foreach ($variant as $t) {
                if (isset($candSet[$t])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * S2 (#507 Weg-2): entfernt bekannte Verwechslungs-Fallen aus der Shortlist,
     * bevor sie an die LLM-Disambig geht — unabhängig vom (lexikalischen ODER
     * semantischen) Score. Genau das entsperrt das Flag-Scharfstellen: Anti-Marker
     * werden deterministisch (Vault Anti_Marker.md) gefiltert, nicht per Floor
     * gehofft (Brie↛Bries, Sojasauce↛Hollandaise, Paprikapulver↛Paprika frisch).
     *
     * @param  array<int, array{name:string, ...}>  $candidates
     * @return array<int, array{name:string, ...}>
     */
    private function stripAntiMarkers(string $ingredientName, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn ($c) => ! $this->terminology->isAntiMarker($ingredientName, (string) ($c['name'] ?? '')),
        ));
    }

    /**
     * Ein explizit genannter §9-Zustand (GpNamingService::ZUSTAND_VOCAB) darf nicht auf eine
     * andere Zustandsklasse fallen — „TK-Erbsen" nicht auf trocken, „Dosentomaten" nicht auf
     * frisch, „getrocknete Tomaten" nicht auf konserviert (vorher fielen trocken UND
     * konserviert in dieselbe Prüfung, siehe {@see TokenEngine::produktForm()}).
     */
    public function acceptsProductForm(string $query, string $name, ?string $condition, ?FoodAlchemistGp $gp = null): bool
    {
        $zustand = $this->engine->produktForm($query)['zustand'];
        if ($zustand === null) {
            return true;   // kein expliziter §9-Zustand im Query ⇒ nichts zu gaten, Score entscheidet
        }
        // 27.10. (Rapsöl, Apfelessig): Öle, Essige, Sirupe und Getränke tragen kein Zustandswort (GP v3.8) — ein „trocken“/„frisch“/„konserviert“
        // in der Zutat darf sie nicht ausschließen. Vorher fiel „Rapsoel …: trocken, raffiniert“ dadurch auf „Salz: raffiniert, trocken“.
        if ($gp !== null && trim((string) $condition) === '' && $this->ohneZustandGp($gp)) {
            return true;
        }
        if (trim((string) $condition) !== '') {
            if (mb_strtolower(trim($condition)) === mb_strtolower($zustand)) {
                return true;
            }
            // Regelwerk GP v3.6.0: gepökelt/geräuchert/luftgetrocknet waren bis dahin Verarbeitung — Bestands-GPs tragen sie im
            // Namen („Lachs: TK, geraeuchert"), die Spalte noch den alten Zustand. Bis zur Daten-Nachführung zählt dort der Name.
            if (in_array($zustand, \Platform\FoodAlchemist\Services\GpNamingService::BEHANDLUNG, true)) {
                return preg_match('/(?<![\p{L}])(?:' . preg_quote($zustand, '/') . '|' . preg_quote(strtr($zustand, ['ö' => 'oe', 'ä' => 'ae', 'ü' => 'ue']), '/') . ')/iu', $name) === 1;
            }

            return false;
        }
        // condition-Feld leer ⇒ Namens-Token-Fallback: der GP-NAME muss den verlangten
        // Zustand tragen — ein Name ohne eindeutiges §9-Wort lehnt ab (konservativ, wie
        // GpZustandBackfillCommand: Lücke lassen ist besser als raten).
        $gpZustand = $this->engine->produktForm($name)['zustand'];

        return $gpZustand !== null && mb_strtolower($gpZustand) === mb_strtolower($zustand);
    }

    /** GP aus einer Warengruppe ohne Zustandswort (WG 11 Öle/Essig, Sirupe, trinkfertige Getränke — GpNamingService::ohneZustandswort). */
    private function ohneZustandGp(FoodAlchemistGp $gp): bool
    {
        return (string) $gp->commodity_group_code === '11' || $this->naming()->ohneZustandswort(['commodity_group_code' => $gp->commodity_group_code,
            'sub_category' => $gp->sub_category, 'hauptzutat' => $this->hauptzutatText($gp), 'condition' => null]);
    }

    private function hauptzutatText(FoodAlchemistGp $gp): string
    {
        $name = $this->naming()->nameOhneMarke((string) $gp->name, $gp->zusatz);

        return trim((string) ($gp->main_ingredient_display ?: (preg_match('/^(.*?):(?=\s|$)/u', $name, $m) === 1 ? $m[1] : $name)));
    }

    /** 27.10.: Hauptzutat-Schutz — kein Treffer, wenn die Hauptzutat des GP nicht in der Zutat vorkommt (Apfelessig ≠ Apfelmus). */
    private function hauptzutatPasst(FoodAlchemistGp $gp, array $passTokens = []): bool
    {
        $alternativen = array_map(fn ($a) => array_values(array_filter($this->engine->tokenize($a), fn ($t) => ! $this->engine->istReinesMerkmal($t)
            && ! $this->engine->isCutFormToken($t))), preg_split('/\s+\/\s+/u', $this->hauptzutatText($gp)) ?: []);
        // Zutat-Seite: Originalzeile + Tokens dieses Pool-Laufs (Freitext-/Decompound-Fallback) + Terminologie-Aliase (Porree ↔ Lauch, Tamari → Sojasauce)
        $this->aliasTokens ??= array_merge([], ...array_map(fn ($p) => $this->engine->tokenize((string) $p), $this->terminology->aliasPhrasesFor($this->currentIngredientName)));

        return \Platform\FoodAlchemist\Support\ZutatFreitext::hauptzutatPasst(array_values(array_unique([...$this->tiebreakQuery, ...$passTokens, ...$this->aliasTokens])), $alternativen);
    }

    // ── Pool-Scans ───────────────────────────────────────────────────────

    private function bestGpMatch(Team $team, array $queryTokens, ?string $querySlug, string $pref, bool $preferRaw, string $bio): ?array
    {
        $best = null;
        $bestGp = null;
        $bestZustand = null;
        $bestBio = null;
        $bestFreigegeben = false;
        // Lauf 92: „Petersilienstiele" → „Petersilienwurzeln: frisch" — nennt die Zeile eine Derivat-Form (Stiele, Schale,
        // Knochen …), muss das GP sie tragen; sonst ist es ein anderes Produkt (wie der Mint seit #251).
        $queryForm = $this->heuristik->nebenproduktDerivat($this->currentIngredientName)['marker'] ?? null;
        foreach ($this->gpPool($team, $queryTokens, $querySlug) as $gp) {
            if ($queryForm !== null && ! $this->heuristik->nenntDerivatForm((string) $gp->name, $queryForm)) {
                continue;
            }
            if (! $this->acceptsProductForm($this->currentIngredientName, $gp->name, $gp->condition, $gp)
                || $this->terminology->isAntiMarker($this->currentIngredientName, $gp->name)
                || ! $this->hauptzutatPasst($gp, $queryTokens)) {
                continue;   // S2: Anti-Marker nie als Entscheidung; 27.10.: andere Hauptzutat nie als Entscheidung
            }
            // Lauf 91 (demo): „Grapefruitsaft" → Derivat „Grapefruitsaft: frisch, Zeste" (Kopf-Floor 0,9). Ein §11.2-Derivat
            // ist nur gemeint, wenn die Zeile seine Form nennt („Grapefruitzeste") — sonst ist es ein anderes Produkt.
            if ($gp->is_derivat && ($marker = $this->heuristik->nebenproduktDerivat((string) $gp->name)['marker'] ?? null) !== null
                && ! $this->heuristik->nenntDerivatForm($this->currentIngredientName, $marker)) {
                continue;
            }
            // Marken-Varianten: die Marken-Klammer zählt nicht mit — „Sahnesauce … / (Altoni)“ und „/ (Hollyfood)“ starten gleich, ein GP ohne Klammer
            // (marke_offen) hat keinen Vorteil (Kurator 26.10.). Namensflags (Bio …) bleiben und entscheiden wie bisher im Varianten-Rang.
            $scoreName = $this->naming()->nameOhneMarke((string) $gp->name, $gp->zusatz);
            $combined = trim($scoreName . ' ' . ($gp->main_ingredient_display ?? ''));
            // 27.10. (d): nennt die Zeile genau die Marke dieses GP („Geflügelfond Pulver (Knorr)“), fehlt das Marken-Wort im Score nicht
            $marke = trim((string) $gp->zusatz) === '' ? [] : $this->engine->tokenize((string) $gp->zusatz);
            $qTokens = $marke !== [] && array_diff($marke, $queryTokens) === [] && array_diff($queryTokens, $marke) !== [] ? array_values(array_diff($queryTokens, $marke)) : $queryTokens;
            $score = $this->scoreMitFloor($qTokens, $querySlug, $combined, $gp->main_ingredient_slug, $scoreName);

            $take = false;
            if ($best === null) {
                $take = true;
            } elseif ($score > $best['score'] + MatchHeuristics::SCORE_EPS) {
                $take = true;
            } elseif (abs($score - $best['score']) <= MatchHeuristics::SCORE_EPS
                && ($vorrang = $this->vorrangVergleich($team, $gp, $bestGp)) !== 0) {
                // Marken-Varianten (Kurator 26.10.): erst Favorit des Teams, dann Lead-Artikel vom Stamm-Lieferanten — vor der bisherigen Logik
                $take = $vorrang > 0;
            } elseif (abs($score - $best['score']) <= MatchHeuristics::SCORE_EPS) {
                // 4.4l/u — Tiebreaker bei (Float-)Gleichstand, Feld-primär
                $rangNeu = $this->heuristik->variantRankResolved($gp->name, $pref, $preferRaw, $bio, $gp->condition, $gp->bio);
                $rangAlt = $this->heuristik->variantRankResolved($best['gp_name'], $pref, $preferRaw, $bio, $bestZustand, $bestBio);
                // Letzter Tiebreak (Dominique 11.10.): bei gleicher Punktzahl und gleicher Variante gewinnt das freigegebene
                // GP vor dem tentative — sonst entschied die ID-Reihenfolge. Nur Gleichstand, die Punktzahl bleibt unberührt.
                $take = $rangNeu > $rangAlt || ($rangNeu === $rangAlt && self::istFreigegeben($gp) && ! $bestFreigegeben);
            }
            if ($take) {
                $bestGp = $gp;
                $bestZustand = $gp->condition;
                $bestBio = $gp->bio;
                $bestFreigegeben = self::istFreigegeben($gp);
                $best = [
                    'target' => 'gp', 'status' => MatchBand::fuerScore($score),
                    'gp_id' => $gp->id, 'gp_name' => $gp->name,
                    'recipe_id' => null, 'recipe_name' => null, 'score' => $score,
                ];
            }
        }

        return $best;
    }

    // ── Marken-Varianten: Tiebreak Favorit → Stamm-Lieferant → Paar X ⇄ unX (Kurator 26.10., vor der bisherigen Varianten-/Freigabe-/ID-Logik) ──

    private function naming(): GpNamingService
    {
        return app(GpNamingService::class);
    }

    /**
     * Vorrang eines GP bei Score-Gleichstand: [Favoriten-Priorität (0 = kein Favorit, höher = besser), Lead-Artikel vom Stamm-Lieferanten des Teams (1/0)].
     * Danach (in vorrangVergleich) die Paar-Regel X ⇄ unX.
     * Favoriten aus FavoritenOverlay (wirksam, inkl. Übernahme vom Hauptteam; immer, unabhängig vom opt-in-Prompt-Block). Stamm = Stamm-Lieferanten-Matrix
     * der Team-Kette für die WG des GP (StammLieferantService, derselbe Lese-Vertrag wie die Lead-LA-Wahl).
     *
     * @return array{0: int, 1: int}
     */
    private function vorrangSchluessel(Team $team, ?FoodAlchemistGp $gp): array
    {
        if ($gp === null) {
            return [0, 0];
        }
        $this->tiebreakMemo['favoriten'] ??= (function () use ($team) {
            $ids = array_keys(\Platform\FoodAlchemist\Support\FavoritenOverlay::fuer($team));   // schon nach Rang sortiert
            $n = count($ids);

            return array_combine($ids, array_map(fn ($i) => $n - $i, array_keys($ids))) ?: [];
        })();
        $fav = $this->tiebreakMemo['favoriten'][(int) $gp->id] ?? 0;

        $stamm = 0;
        $leadId = $gp->lead_la_supplier_item_id;
        if ($leadId !== null) {
            $lieferant = $this->tiebreakMemo['lead'][(int) $leadId] ??= (int) (\Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)
                ->whereKey((int) $leadId)->value('supplier_id') ?? 0);
            $wg = (string) ($gp->commodity_group_code ?? '');
            $this->tiebreakMemo['stamm'][$wg] ??= array_fill_keys(app(StammLieferantService::class)->stammSupplierIdsFor($team, $wg !== '' ? $wg : null), true);
            $stamm = $lieferant !== 0 && isset($this->tiebreakMemo['stamm'][$wg][$lieferant]) ? 1 : 0;
        }

        return [$fav, $stamm];
    }

    /**
     * Paar-Regel X ⇄ unX (Kurator 26.10., „Erdnüsse, geröstet“ → „ungesalzen“, nicht „gesalzen“): trägt das eine GP „unX“ und das andere „X“, und nennt
     * die Zutat keins von beiden, gewinnt die un-Variante (Grundware ohne Zusatz). 1 = $neu, -1 = $alt, 0 = kein solches Paar.
     */
    private function unPaar(FoodAlchemistGp $neu, FoodAlchemistGp $alt): int
    {
        $tok = fn (FoodAlchemistGp $g) => array_flip($this->engine->tokenize($this->naming()->nameOhneMarke((string) $g->name, $g->zusatz)));
        [$n, $a] = [$tok($neu), $tok($alt)];
        $q = array_flip($this->tiebreakQuery);
        foreach ([[$n, $a, 1], [$a, $n, -1]] as [$mitUn, $ohneUn, $r]) {
            foreach (array_keys($mitUn) as $t) {
                $x = (string) $t;
                if (str_starts_with($x, 'un') && mb_strlen($x) > 4 && isset($ohneUn[mb_substr($x, 2)]) && ! isset($mitUn[mb_substr($x, 2)])
                    && ! isset($q[$x]) && ! isset($q[mb_substr($x, 2)])) {
                    return $r;
                }
            }
        }

        return 0;
    }

    /** 1 = $neu hat Vorrang, -1 = $alt, 0 = gleich (dann bisherige Logik). */
    private function vorrangVergleich(Team $team, FoodAlchemistGp $neu, ?FoodAlchemistGp $alt): int
    {
        $v = $this->vorrangSchluessel($team, $neu) <=> $this->vorrangSchluessel($team, $alt);

        return $v !== 0 || $alt === null ? $v : $this->unPaar($neu, $alt);
    }

    /** Kandidatenliste: Score absteigend; bei gleichem Score GP mit Favorit/Stamm-Vorrang zuerst, sonst stabil wie bisher. */
    private function kandidatenSortierung(Team $team): \Closure
    {
        return function ($a, $b) use ($team) {
            $s = $b['score'] <=> $a['score'];
            if (abs((float) $a['score'] - (float) $b['score']) > MatchHeuristics::SCORE_EPS) {
                return $s;
            }
            [$gb, $ga] = [$this->kandidatGp($team, $b), $this->kandidatGp($team, $a)];
            $v = $this->vorrangSchluessel($team, $gb) <=> $this->vorrangSchluessel($team, $ga);
            if ($v === 0 && $gb !== null && $ga !== null) {
                $v = $this->unPaar($gb, $ga);
            }

            return $v !== 0 ? $v : $s;
        };
    }

    private function kandidatGp(Team $team, array $c): ?FoodAlchemistGp
    {
        if (($c['kind'] ?? null) !== 'gp') {
            return null;
        }
        $id = (int) $c['id'];
        if (! array_key_exists($id, $this->tiebreakMemo['gp'] ?? [])) {
            $this->tiebreakMemo['gp'][$id] = FoodAlchemistGp::visibleToTeam($team)->find($id, ['id', 'name', 'zusatz', 'lead_la_supplier_item_id', 'commodity_group_code']);
        }

        return $this->tiebreakMemo['gp'][$id];
    }

    private function bestSubrecipeMatch(Team $team, array $queryTokens, ?string $querySlug): ?array
    {
        $typHint = $this->heuristik->detectSubTypHint($queryTokens);

        $best = null;
        // Spec 80 B4 im Matcher (Live-Test demo 09.10., Lauf 82): die automatische ENTSCHEIDUNG nimmt nur freigegebene
        // Basisrezepte (Bestand = nur freigegeben) — sonst verknüpften Heilung/Überarbeiten/Review nachträglich
        // Entwürfe (#374 „Wurzel-Püree“). Die Vorschlagsliste für Menschen (candidatesFor) zeigt Entwürfe weiter.
        $queryTyp = $this->explizitesTyp((string) $this->currentIngredientName);
        foreach ($this->subPool($team, $queryTokens, $querySlug, nurFreigegeben: true) as $sub) {
            if ($this->terminology->isAntiMarker($this->currentIngredientName, $sub->name)) {
                continue;   // S2: Anti-Marker nie als Entscheidung
            }
            // Nennt die Zeile einen Typ („Matte: Petersilie“), entscheidet der Matcher nicht für einen anderen Typ
            // („Gel: Petersilie“) — Funktion vor Namensähnlichkeit (Spec 80 B3).
            if ($queryTyp !== null && ($kandTyp = $this->explizitesTyp((string) $sub->name)) !== null
                && array_intersect(\Platform\FoodAlchemist\Support\RezeptTypVokabular::gruppenVon($queryTyp),
                    \Platform\FoodAlchemist\Support\RezeptTypVokabular::gruppenVon($kandTyp)) === []) {
                continue;
            }
            $score = $this->scoreMitFloor($queryTokens, $querySlug, $sub->name, null, $sub->name);

            // 4.4b — Sub-Typ-Hint-Boost (+0.20, capped) wenn der Kandidat den Tag trägt
            if ($typHint !== null && ($sub->sub_typ_slug ?? '') === $typHint) {
                $score = min(1.0, $score + MatchHeuristics::SUB_TYP_HINT_BOOST);
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'target' => 'sub_recipe', 'status' => MatchBand::fuerScore($score),
                    'gp_id' => null, 'gp_name' => null,
                    'recipe_id' => $sub->id, 'recipe_name' => $sub->name, 'score' => $score,
                ];
            }
        }

        return $best;
    }

    /** match_score + Name-Containment-Floor (4.4o, pool-agnostisch). */
    private function scoreMitFloor(array $queryTokens, ?string $querySlug, string $candText, ?string $candSlug, string $candName): float
    {
        $score = $this->engine->matchScore($queryTokens, $querySlug, $this->engine->tokenize($candText), $candSlug ?: null);
        if ($score < MatchHeuristics::NAME_CONTAINMENT_FLOOR && $this->engine->headMatchesQuery($candName, $queryTokens)) {
            $score = MatchHeuristics::NAME_CONTAINMENT_FLOOR;
        }

        return $score;
    }

    /** LIKE-Vorfilter (build_like_clauses) — GP-Pool, ORDER BY id (Inv. 7), LIMIT 300. */
    /**
     * Breiter Pool ∪ enge Sonden. Siehe Begründung an der Aufrufstelle: `gpPool()` kappt
     * bei 300 nach `id`, wodurch breite Token-Mengen exakte Treffer mit hoher ID abschneiden.
     *
     * Die Sonden laufen NUR, wenn die Erweiterung den Token-Satz tatsächlich verbreitert hat
     * bzw. ein längstes Token existiert — sonst wäre es dieselbe Abfrage zweimal.
     */
    private function poolMitEngenSonden(Team $team, array $poolTokens, array $queryTokens, ?string $querySlug)
    {
        $treffer = $this->gpPool($team, $poolTokens, $querySlug);
        foreach ($this->engeSonden($poolTokens, $queryTokens) as $sonde) {
            $treffer = $treffer->concat($this->gpPool($team, $sonde, $querySlug));
        }

        return $treffer->unique('id')->values();
    }

    /** Dasselbe für die Basisrezepte (subPool kappt bei 200, gleiches Muster). */
    private function subPoolMitEngenSonden(Team $team, array $poolTokens, array $queryTokens, ?string $querySlug)
    {
        $treffer = $this->subPool($team, $poolTokens, $querySlug);
        foreach ($this->engeSonden($poolTokens, $queryTokens) as $sonde) {
            $treffer = $treffer->concat($this->subPool($team, $sonde, $querySlug));
        }

        return $treffer->unique('id')->values();
    }

    /**
     * Die engen Sonden: Original-Tokens (falls die Erweiterung verbreitert hat) und das
     * längste Einzel-Token (bei deutschen Komposita der unterscheidende Teil —
     * „Sherryessig" gegenüber „sherry"+„essig").
     *
     * @return list<list<string>>
     */
    private function engeSonden(array $poolTokens, array $queryTokens): array
    {
        $sonden = [];
        if ($queryTokens !== [] && count($poolTokens) > count($queryTokens)) {
            $sonden[] = $queryTokens;
        }
        $laengstes = null;
        foreach ($queryTokens as $t) {
            if ($laengstes === null || mb_strlen($t) > mb_strlen($laengstes)) {
                $laengstes = $t;
            }
        }
        if ($laengstes !== null && $queryTokens !== [$laengstes]) {
            $sonden[] = [$laengstes];
        }

        return $sonden;
    }

    /**
     * POST-MATCH-DRAW, Auswahl-Hälfte — die Kandidaten, aus denen nach einer erfolglosen
     * Matcher-Entscheidung noch gezogen werden DARF, in Score-Reihenfolge.
     *
     * Hintergrund: die Match-ENTSCHEIDUNG ({@see matchIngredient}) ist bewusst lexikalisch mit
     * Band-Gate — unter dem Gate heißt „zu schwach", nicht „ungeprüft". Semantik kommt danach
     * als eigener Zug über einen HÖHEREN, modus-abhängigen Boden. Das ist die Erdungs-Stärke
     * B2 (2026-08-20) und war bisher privat in `RecipeGeneratorService::ziehtAusBestand`
     * eingebaut — mit der Folge, dass `foodalchemist.gps.MATCH` diesen Zug NICHT kannte und
     * `none` meldete, wo die Pipeline gezogen hätte. Ein Agent, der der Tool-Anleitung folgt
     * („kein Treffer → minten oder Beschaffungs-Wunsch"), legte daraufhin ein GP-Duplikat oder
     * Phantom-Bedarf an, obwohl ein approved-Kandidat in derselben Antwort stand.
     *
     * Darum liegt die Auswahl jetzt HIER, an einer Stelle, und beide Aufrufer teilen sie. Die
     * PRÜFUNG bleibt beim Aufrufer: der Generator braucht zusätzlich den Zyklus-Schutz gegen das
     * Eltern-Rezept, das MCP-Tool hat kein Eltern-Rezept. Darum die ganze Liste statt nur des
     * ersten Treffers — der Aufrufer nimmt den ersten, der seine Prüfung besteht.
     *
     * @param  list<array<string, mixed>>  $shortlist  aus {@see candidatesFor} (score-desc)
     * @param  'gp'|'sub'  $wantKind
     * @return list<array{kind: string, id: int, score: float}>
     */
    public function drawKandidaten(array $shortlist, string $wantKind, string $bestand): array
    {
        $floor = $this->drawFloor($bestand);
        if ($floor === null) {
            return [];   // komplett_neu zieht NIE
        }
        $out = [];
        foreach ($shortlist as $c) {
            if (($c['kind'] ?? null) !== $wantKind) {
                continue;
            }
            // Nur semantisch (mit)getragene Kandidaten. Rein lexikalische sind bereits durch
            // das Band-Gate der Entscheidung gelaufen; sie hier erneut zu ziehen würde das
            // Gate aushebeln.
            if (! in_array($c['origin'] ?? 'lexical', ['both', 'semantic'], true)) {
                continue;
            }
            if ((float) ($c['score'] ?? 0) < $floor) {
                break;   // desc-sortiert: ab hier liegt alles unter dem Boden
            }
            $id = (int) ($c['id'] ?? 0);
            if ($id > 0) {
                $out[] = ['kind' => (string) $c['kind'], 'id' => $id, 'score' => (float) $c['score']];
            }
        }

        return $out;
    }

    /**
     * Der Modus-Boden des Post-Match-Draws — `null` bedeutet „dieser Modus zieht nicht".
     * Öffentlich, weil `gps.MATCH` ihn in der Antwort ausweist: ein gezogener Treffer ohne
     * sichtbaren Boden wäre für den Aufrufer nicht von einem Namenstreffer zu unterscheiden.
     */
    public function drawFloor(string $bestand): ?float
    {
        return match ($bestand) {
            'nur_bestand' => 0.55,   // Datenbank-Modus: breiter erden (B1)
            'hybrid' => 0.70,
            default => null,         // komplett_neu / unbekannt
        };
    }

    private static function istFreigegeben(FoodAlchemistGp $gp): bool
    {
        $status = $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status;

        return $status === 'approved';
    }

    private function gpPool(Team $team, array $queryTokens, ?string $querySlug)
    {
        $query = FoodAlchemistGp::visibleToTeam($team)->lebensmittel()   // Non-Food ist keine Zutat
            ->whereIn('status', ['approved', 'tentative'])
            ->where('is_platzhalter', false);
        $this->likeVorfilter($query, $queryTokens, $querySlug, ['name', 'main_ingredient_slug', 'main_ingredient_display']);

        return $query->orderBy('id')->limit(300)
            ->get(['id', 'name', 'main_ingredient_slug', 'main_ingredient_display', 'condition', 'bio', 'team_id', 'is_derivat', 'status',
                'zusatz', 'lead_la_supplier_item_id', 'commodity_group_code', 'sub_category']);   // Marken-Varianten: Score ohne Klammer, Tiebreak Stamm-Lieferant; sub_category: Zustands-Gate
    }

    /** Typ aus dem Präfix eines Namens, nur wenn er im Typ-Vokabular steht (sonst null). */
    private function explizitesTyp(string $name): ?string
    {
        $p = \Platform\FoodAlchemist\Support\RezeptTypVokabular::praefix($name);

        return $p === null ? null : \Platform\FoodAlchemist\Support\RezeptTypVokabular::finde($p);
    }

    /**
     * Sub-Pool: Basisrezepte, ORDER BY id, LIMIT 200. Für Vorschläge alle Workflow-Stadien inkl. stub; für die
     * automatische Entscheidung (`nurFreigegeben`) nur freigegebene (Bestand-Regel, Spec 80 B4).
     */
    private function subPool(Team $team, array $queryTokens, ?string $querySlug, bool $nurFreigegeben = false)
    {
        $query = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereIn('status', $nurFreigegeben ? ['approved'] : ['stub', 'draft', 'review', 'approved']);
        $this->likeVorfilter($query, $queryTokens, $querySlug, ['name']);

        return $query->orderBy('id')->limit(200)
            ->get(['id', 'name', 'sub_recipe_type_legacy_id', 'team_id'])
            ->each(function ($sub) {
                // 4.4b — Sub-Typ-Slug über das (Legacy-)Vokabular; Tabelle folgt mit V-20,
                // bis dahin leerer Tag (Boost greift nur bei gepflegtem Slug)
                $sub->setAttribute('sub_typ_slug', $this->subTypSlug($sub->sub_recipe_type_legacy_id));
            });
    }

    private array $subTypCache = [];

    private function subTypSlug(?int $legacyId): string
    {
        if ($legacyId === null) {
            return '';
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_vocab_sub_rezept_typ')) {
            return '';
        }

        return $this->subTypCache[$legacyId] ??= (string) \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_sub_rezept_typ')
            ->where('legacy_id', $legacyId)->value('slug');
    }

    private function likeVorfilter($query, array $queryTokens, ?string $querySlug, array $spalten): void
    {
        $tokens = array_values(array_filter($queryTokens, fn ($t) => mb_strlen($t) >= 3));
        if ($tokens === []) {
            $tokens = $queryTokens;
        }
        // Lauf 88 (demo, 10.10.): „Lauch: frisch, ganz" LIKEte auch „frisch"/„ganz" — das trifft tausende GPs, nach
        // orderBy(id)->limit(300) kamen die Lauch-GPs (id ≥ 11857) nie in den Pool, die Zeile blieb offen (ebenso
        // „Pilzmischung: frisch, ganz"). Reine Zustands-/Schnittwörter tragen seit #233/#240 keinen Treffer — also
        // filtern sie auch nicht vor, solange ein Produktwort übrig bleibt. Gescort wird weiter mit allen Tokens.
        $produkt = array_values(array_filter($tokens, fn ($t) => ! $this->engine->istReinesMerkmal($t)));
        if ($produkt !== []) {
            $tokens = $produkt;
        }
        // M6-07 (Hebel-1-Rest »umlaut-blindes Reuse-Inventar«): Tokens sind
        // umlaut-expandiert (kuerbis), DB-Namen tragen Umlaute (Kürbis) — je
        // Token zusätzlich die Umlaut-Rückform LIKEn. Rein additiv: erweitert
        // NUR das Kandidaten-Set, Scores bleiben token-normalisiert.
        $varianten = [];
        foreach ($tokens as $t) {
            $varianten[$t] = true;
            $u = str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], $t);
            if ($u !== $t) {
                $varianten[$u] = true;
            }
        }
        $tokens = array_keys($varianten);
        $slugN = $querySlug !== null ? $this->engine->normalizeSlug($querySlug) : null;

        $query->where(function ($q) use ($tokens, $slugN, $spalten) {
            foreach ($tokens as $t) {
                foreach ($spalten as $spalte) {
                    $q->orWhereRaw("LOWER({$spalte}) LIKE ?", ['%' . $t . '%']);
                }
            }
            if ($slugN !== null && $slugN !== '') {
                foreach ($spalten as $spalte) {
                    $q->orWhereRaw("LOWER({$spalte}) LIKE ?", ['%' . $slugN . '%']);
                }
            }
        });
    }

    // ── Alias-Auflösung (Token-Set-Gleichheit, Existenz-Guard) ──────────

    private function resolveSubByName(Team $team, string $targetName): ?array
    {
        $targetTokens = $this->engine->tokenize($targetName);
        if ($targetTokens === []) {
            return null;
        }
        sort($targetTokens);
        // Spec 80 B4: der Alias ist eine automatische ENTSCHEIDUNG (kehrt vor poolLauf zurück) — also nur
        // freigegebene Basisrezepte, wie bestSubrecipeMatch. Vorher verknüpften Heilung/Revise über diesen Zweig
        // weiter Entwürfe („Heller Kalbsfond"-Stub); der Generator fing es nur dank validiereProposedSub ab.
        $query = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('status', 'approved');
        // Alias-Gleichheit bleibt tokenbasiert; nur passende Namen statt des gesamten
        // Stamms hydrieren. Kein LIMIT, damit hohe IDs weiterhin erreichbar bleiben.
        $this->likeVorfilter($query, $targetTokens, null, ['name']);
        foreach ($query->orderBy('id')->cursor(['id', 'name']) as $r) {
            $tokens = $this->engine->tokenize($r->name);
            sort($tokens);
            if ($tokens === $targetTokens) {
                return ['id' => $r->id, 'name' => $r->name];
            }
        }

        return null;
    }

    private function resolveGpByName(Team $team, string $targetName): ?array
    {
        $targetTokens = $this->engine->tokenize($targetName);
        if ($targetTokens === []) {
            return null;
        }
        sort($targetTokens);
        $query = FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('status', ['approved', 'tentative'])->where('is_platzhalter', false);
        $this->likeVorfilter($query, $targetTokens, null, ['name']);
        foreach ($query->orderBy('id')->cursor(['id', 'name', 'condition']) as $gp) {
            $tokens = $this->engine->tokenize($gp->name);
            sort($tokens);
            if ($tokens === $targetTokens) {
                return ['id' => $gp->id, 'name' => $gp->name, 'condition' => $gp->condition];
            }
        }

        return null;
    }

    private function besserer(?array $a, ?array $b): array
    {
        if ($a !== null && $b !== null) {
            return $a['score'] >= $b['score'] ? $a : $b;
        }

        return $a ?? $b ?? $this->noMatch(0.0);
    }

    private function noMatch(float $score): array
    {
        return [
            'target' => 'none', 'status' => MatchBand::NoMatch,
            'gp_id' => null, 'gp_name' => null,
            'recipe_id' => null, 'recipe_name' => null, 'score' => $score,
        ];
    }
}
