<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Zukauf-Basisrezept (Dominique 10.10.): ein Gericht besteht nur aus Basisrezepten — auch gekaufte Fertigware bekommt
 * eines, damit Rüstzeit, Abbacken und Portionieren bezifferbar sind. demo Lauf 87: aus „Kürbiskernöl“ zum Beträufeln
 * baute der Generator ein neues Aromaöl (Traubenkernöl + geröstete Kerne).
 *
 * Darum legt hier der CODE Name und Ware fest, die KI schreibt nur die Handgriffe und wählt passende Hilfsstoffe
 * (recipe.ruestschritt, ~1k statt ~30k Tokens). Name: „<Typ>: <Ware> (Zukauf)“ — der Typ bleibt die Kategorie
 * („Crunch: Röstzwiebel (Zukauf)“). Fehlt der Typ, kommt er aus der pflegbaren Zuordnung Warengruppe → Typ
 * (Regel basisrezept.zukauf.typ); ist keiner ableitbar, wird normal geplant statt geraten.
 */
final class ZukaufBasisrezeptService
{
    public const ZUSATZ = '(Zukauf)';

    public const REGEL_TYP = 'basisrezept.zukauf.typ';

    /**
     * Fertigware nach GP-Taxonomie: Gewürze & Toppings (10), Öle & Essige (11), Convenience (13), Knabbereien (09.6),
     * Sprossen & Keimlinge (01.8). Ohne Typ in der Zeile zusätzlich Kräuter (03). Rohware (Gemüse, Fleisch …) wird
     * nie Zukauf — „Püree: Kürbis“ ist eine Zubereitung.
     */
    private const FERTIG_WG = ['10', '11', '13'];

    private const FERTIG_SUB = ['09.6', '01.8'];

    /** Ist die Zeile gekaufte Fertigware (sicherer GP-Treffer auf Fertigware) — unabhängig davon, ob ein Typ ableitbar ist? */
    public function istFertigware(Team $team, string $text): bool
    {
        return $this->fertigwareGp($team, $text) !== null;
    }

    private function fertigwareGp(Team $team, string $text): ?FoodAlchemistGp
    {
        $praefix = RezeptTypVokabular::praefix($text);
        $typ = $praefix !== null ? RezeptTypVokabular::finde($praefix) : null;
        $bezeichnung = RezeptTypVokabular::bezeichnung($text);
        if ($bezeichnung === '' || app(MatchHeuristics::class)->queryIstHalbfabrikat(app(TokenEngine::class)->tokenize($bezeichnung))) {
            return null;
        }
        $t = app(IngredientMatchService::class)->matchIngredient($team, $bezeichnung, null, 'gp_first');
        if (($t['target'] ?? null) !== 'gp' || ($t['status'] ?? null) !== MatchBand::Exact) {
            return null;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $t['gp_id']);

        return $gp !== null && $this->istFertigwareGp($gp, $typ === null) ? $gp : null;
    }

    /**
     * @return array{gp_id: int, gp_name: string, typ: string, bezeichnung: string}|null
     */
    public function erkenne(Team $team, string $text): ?array
    {
        $praefix = RezeptTypVokabular::praefix($text);
        $typ = $praefix !== null ? RezeptTypVokabular::finde($praefix) : null;
        $bezeichnung = RezeptTypVokabular::bezeichnung($text);   // ohne Typ und ohne Klammer-Zusatz
        if ($bezeichnung === '' || app(MatchHeuristics::class)->queryIstHalbfabrikat(app(TokenEngine::class)->tokenize($bezeichnung))) {
            return null;
        }
        // Auf den reinen Warennamen matchen: mit Typ-Präfix ist die Zeile eine Basisrezept-Zeile und träfe kein GP.
        $t = app(IngredientMatchService::class)->matchIngredient($team, $bezeichnung, null, 'gp_first');
        if (($t['target'] ?? null) !== 'gp' || ($t['status'] ?? null) !== MatchBand::Exact) {
            return null;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $t['gp_id']);
        if ($gp === null || ! $this->istFertigwareGp($gp, $typ === null)) {
            return null;
        }
        $typ ??= $this->typAusWarengruppe($gp);
        if ($typ === null) {
            return null;   // kein eindeutiger Typ → normal planen statt raten
        }

        return ['gp_id' => (int) $gp->id, 'gp_name' => (string) $gp->name, 'typ' => $typ, 'bezeichnung' => $bezeichnung];
    }

    /**
     * Teilfertig (Dominique 10.10.): die Hauptzeile ist sicher TK-Gemüse/-Obst, das in der Küche noch gewürzt, glasiert
     * oder angeschwenkt wird — gebaut wird kurz und ohne Komponenten-Plan, Fertigungstiefe teilfertig, Name ohne Zusatz.
     *
     * @return array{gp_id: int, gp_name: string}|null
     */
    public function erkenneTeilfertig(Team $team, string $text): ?array
    {
        $bezeichnung = RezeptTypVokabular::bezeichnung($text);
        if ($bezeichnung === '' || app(MatchHeuristics::class)->queryIstHalbfabrikat(app(TokenEngine::class)->tokenize($bezeichnung))) {
            return null;
        }
        $t = app(IngredientMatchService::class)->matchIngredient($team, $bezeichnung, null, 'gp_first');
        if (($t['target'] ?? null) !== 'gp' || ($t['status'] ?? null) !== MatchBand::Exact) {
            return null;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $t['gp_id']);
        if ($gp === null || mb_strtoupper((string) $gp->condition) !== 'TK'
            || ! in_array((string) $gp->commodity_group_code, ['01', '02'], true)) {
            return null;
        }

        return ['gp_id' => (int) $gp->id, 'gp_name' => (string) $gp->name];
    }

    /** @param  array{gp_id: int, gp_name: string, typ: string, bezeichnung: string}  $ware */
    public function baue(Team $team, FoodAlchemistCascadeRunStep $child, string $text, array $ware, array $params): FoodAlchemistRecipe
    {
        // Ware in der Menge des Bedarfs (Zukauf wird nicht „gekocht“ — es gibt keine Charge zu wählen).
        $menge = (float) ($params['ziel_menge'] ?? $params['bedarf_menge'] ?? 0);
        $menge = $menge > 0 ? $menge : 1000.0;
        $einheit = $params['ziel_einheit'] ?? $params['bedarf_einheit'] ?? 'g';
        $einheit = in_array($einheit, ['g', 'kg', 'ml', 'l'], true) ? $einheit : 'g';
        $eltern = $child->parent_step_id !== null ? FoodAlchemistCascadeRunStep::find($child->parent_step_id) : null;

        $vorschlag = app(AiGatewayService::class)->propose('recipe.ruestschritt', array_filter([
            'ware' => $ware['gp_name'],
            'zeile_im_gericht' => $text,
            'funktion' => $ware['typ'],
            'ansatz' => $menge . ' ' . $einheit,
            'gericht' => $eltern?->label,
        ]));
        $schritte = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), (array) ($vorschlag->werte['schritte'] ?? []))));

        $rezepte = app(RecipeService::class);
        $recipe = $rezepte->create($team, [
            'name' => $ware['typ'] . ': ' . $ware['bezeichnung'] . ' ' . self::ZUSATZ,
            'preparation' => $schritte === [] ? null : implode("\n", array_map(static fn ($s, $i) => ($i + 1) . '. ' . $s, $schritte, array_keys($schritte))),
            'created_via' => 'generator',
        ]);

        $zeilen = [[
            'gp_id' => $ware['gp_id'], 'raw_text' => $ware['gp_name'], 'quantity' => $menge,
            'unit_vocab_id' => $this->einheitId($team, $einheit), 'auto_ground' => false,
        ]];
        $basis = $menge * (in_array($einheit, ['kg', 'l'], true) ? 1000 : 1);
        foreach ((array) ($vorschlag->werte['hilfsstoffe'] ?? []) as $hs) {
            if (($zeile = $this->hilfsstoff($team, (array) $hs, $basis)) !== null) {
                $zeilen[] = $zeile;
            }
        }
        $rezepte->syncIngredients($team, (int) $recipe->id, $zeilen);
        // Kennzeichen im Namen UND im Feld (Dominique 10.10.): Fertigungstiefe convenience, von der Kaskade gesetzt.
        $recipe->forceFill(['production_depth' => 'convenience', 'production_depth_source' => 'kaskade'])->save();

        return $recipe->refresh();
    }

    /** Brot & Backwaren (WG 09 außer 09.6 Knabbereien) — Dominique 10.10.: „Ich stelle keinen Toast hin, ich mache einen Crunch daraus.“ */
    /** Panko trägt das Toastaroma und wird wie Brot verarbeitet (Dominique 10.10.) — gleich welche Warengruppe. */
    private const BROT_WOERTER = ['toast', 'baguette', 'brot', 'broetchen', 'ciabatta', 'focaccia', 'brioche', 'sauerteig', 'pumpernickel', 'laugen', 'panko'];

    private static function istBrotGp(FoodAlchemistGp $gp): bool
    {
        if (str_contains(mb_strtolower((string) $gp->name), 'panko')) {
            return true;
        }

        return (string) $gp->commodity_group_code === '09' && ! str_starts_with((string) ($gp->sub_category ?? ''), '09.6');
    }

    /**
     * Ist das Gericht selbst ein Brot-Angebot (Brotkorb, Brotkonfekt, Brot & Butter)? Dann sind die Brote das Angebot und
     * bleiben unverarbeitet Grundprodukte. Erkannt am Kürzel „[BRO]“, am Namen oder daran, dass Brot den Hauptteil stellt.
     *
     * @param  list<string>  $zeilen
     */
    public function istBrotAngebot(Team $team, string $name, array $zeilen): bool
    {
        if (preg_match('/^\[BRO\]/iu', trim($name)) === 1
            || preg_match('/brotkorb|brotkonfekt|brot\s*(&|und)\s*butter|brotauswahl/iu', $name) === 1) {
            return true;
        }
        $zeilen = array_values(array_filter($zeilen, static fn ($z) => trim($z) !== ''));
        if ($zeilen === []) {
            return false;
        }
        $brot = count(array_filter($zeilen, fn ($z) => $this->istBrotZeile($team, $z)));

        return $brot * 2 >= count($zeilen);
    }

    /**
     * Ist die Zeile Brot/Backware? Dann nie Zukauf und nie als Scheibe im Gericht — sie wird zur verarbeiteten
     * Komponente (Crunch, Kruste, Croûton, Brösel). Erkannt über das GP (WG 09 außer 09.6), sonst über das Brot-Wort.
     */
    public function istBrotZeile(Team $team, string $text): bool
    {
        $bezeichnung = RezeptTypVokabular::bezeichnung($text);
        if ($bezeichnung === '') {
            return false;
        }
        $t = app(IngredientMatchService::class)->matchIngredient($team, $bezeichnung, null, 'gp_first');
        if (($t['target'] ?? null) === 'gp' && IngredientMatchService::istAutomatischVerdrahtbar($t)
            && ($gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $t['gp_id'])) !== null) {
            return self::istBrotGp($gp);
        }
        foreach (app(TokenEngine::class)->tokenize($bezeichnung) as $wort) {
            foreach (self::BROT_WOERTER as $b) {
                if (str_starts_with($wort, $b) || str_ends_with($wort, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function istFertigwareGp(FoodAlchemistGp $gp, bool $ohneTyp): bool
    {
        if (self::istBrotGp($gp)) {
            return false;   // Brot wird verarbeitet, nie als Zukauf hingestellt
        }
        $wg = (string) ($gp->commodity_group_code ?? '');
        $sub = (string) ($gp->sub_category ?? '');
        if (in_array($wg, self::FERTIG_WG, true)) {
            return true;
        }
        foreach (self::FERTIG_SUB as $s) {
            if (str_starts_with($sub, $s)) {
                return true;
            }
        }

        return $ohneTyp && $wg === '03';
    }

    /** Typ aus der Warengruppe — Regel basisrezept.zukauf.typ (wert = Typ, aliase = WG-/Sub-Präfixe). Längster Präfix gewinnt. */
    private function typAusWarengruppe(FoodAlchemistGp $gp): ?string
    {
        $regel = RegelBuch::falls(self::REGEL_TYP);
        if ($regel === null) {
            return null;
        }
        $schluessel = [(string) ($gp->sub_category ?? ''), (string) ($gp->commodity_group_code ?? '')];
        $best = null;
        $bestLen = 0;
        foreach ((array) ($regel->params['werte'] ?? []) as $w) {
            foreach ((array) ($w['aliase'] ?? []) as $praefix) {
                $praefix = (string) $praefix;
                foreach ($schluessel as $s) {
                    if ($praefix !== '' && str_starts_with($s, $praefix) && mb_strlen($praefix) > $bestLen) {
                        $best = RezeptTypVokabular::finde((string) ($w['wert'] ?? ''));
                        $bestLen = mb_strlen($praefix);
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Hilfsstoffe wählt die KI je nach Ware (Trennfett, Salz, Butter …, Dominique 10.10.). Schutz gegen ein erfundenes
     * Zweitprodukt: höchstens 10 % des Ansatzes und ein sicherer Grundprodukt-Treffer — sonst verworfen.
     *
     * @param  array<string, mixed>  $hs
     */
    private function hilfsstoff(Team $team, array $hs, float $basisGramm): ?array
    {
        $text = trim((string) ($hs['text'] ?? ''));
        $menge = (float) str_replace(',', '.', (string) ($hs['menge'] ?? 0));
        $einheit = mb_strtolower(trim((string) ($hs['einheit'] ?? 'g')));
        if ($text === '' || $menge <= 0 || ! in_array($einheit, ['g', 'kg', 'ml', 'l'], true)) {
            return null;
        }
        if ($menge * (in_array($einheit, ['kg', 'l'], true) ? 1000 : 1) > 0.1 * $basisGramm) {
            return null;   // mehr als eine Zugabe: die KI baut ein anderes Produkt (300 ml Traubenkernöl auf 400 ml Kernöl)
        }
        $t = app(IngredientMatchService::class)->matchIngredient($team, $text, null, 'gp_first');
        if (($t['target'] ?? null) !== 'gp' || ! IngredientMatchService::istAutomatischVerdrahtbar($t)) {
            return null;
        }

        return ['gp_id' => (int) $t['gp_id'], 'raw_text' => $text, 'quantity' => $menge,
            'unit_vocab_id' => $this->einheitId($team, $einheit), 'auto_ground' => false];
    }

    private function einheitId(Team $team, string $slug): int
    {
        $id = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $slug)->value('id');
        if ($id === null) {
            throw new \RuntimeException("Einheit „{$slug}“ fehlt im Vokabular.");
        }

        return (int) $id;
    }
}
