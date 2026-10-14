<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 51 Etappe E — verbindet die produzierte Menge mit dem Behälter-Katalog.
 *
 * DIE NAHT, um die es geht: `explodiere()` erzeugt eine Zeile JE REZEPT mit
 * `produzierte_menge_kg` — aggregiert über alle Ziele eines Tages, festgenagelt durch
 * UNIQUE(production_order_id, recipe_id). Die Behälter-Angabe hing dagegen am Gericht. Die
 * beiden Objekte trafen sich nie; deshalb konnte das System nie einen Bedarf rechnen.
 *
 * ZWEI ZWECKE, ZWEI ORTE — und das ist keine Kosmetik:
 *
 *  - `abfuellen` gehört an JEDE Zeile, auch an Sub-Sub-Rezepte. Was produziert wird, muss
 *    irgendwo hinein: die Menge der Zeile ist genau die richtige Grösse.
 *
 *  - `regenerieren` und `ausgabe` gehören NUR an Zeilen, die auch serviert werden — je
 *    Komponente. Zwei Gründe:
 *      (a) Ein Fond wird produziert und gelagert, nicht am Pass gewärmt. Er kann Auftrags-Top
 *          sein (»Brauner Fond, 6 kg«) und stünde dann auf tiefe==0 — die Grenze ist also
 *          »wird serviert«, nicht »steht oben«.
 *      (b) Die Sauce, die sich drei Gerichte teilen, wird an drei Pässen je ANTEILIG gewärmt.
 *          Die aggregierte Basisrezept-Zeile kennt diesen Anteil nicht, die Gericht-Zeile schon.
 *
 * Gerechnet wird mit {@see BehaelterRechner}; hier liegt nur die Beschaffung: welcher Behälter,
 * welche Referenz, welche Kandidaten.
 */
class BehaelterBedarfService
{
    public function __construct(private readonly BehaelterRechner $rechner) {}

    /** @var array<int, \Illuminate\Support\Collection> Katalog je Team — eine Explosion, ein Query. */
    private array $katalogCache = [];

    /**
     * Behälter zum Abfüllen für EINE Produktionszeile.
     *
     * @return array|null null, wenn das Rezept dafür nichts hinterlegt hat — dann gibt es auch
     *                    nichts zu melden; die Lücke steht am Rezept, nicht am Auftrag.
     */
    public function abfuellen(Team $team, FoodAlchemistRecipe $recipe, ?float $mengeKg): ?array
    {
        return $this->fuerZweck($team, $recipe, FoodAlchemistVocabContainer::ZWECKE[0], $mengeKg, $recipe->name);
    }

    /**
     * Behälter zum Regenerieren und Ausgeben — je Komponente des Gerichts.
     *
     * @param  array<int, array{recipe: FoodAlchemistRecipe, label: string, menge_kg: float}>  $komponenten
     * @return array<int, array<string, mixed>>
     */
    public function jeKomponente(Team $team, array $komponenten): array
    {
        $raus = [];

        foreach ($komponenten as $k) {
            $durchgaengig = $this->istDurchgaengig($k['recipe']);
            foreach (['regenerieren', 'ausgabe'] as $zweck) {
                $bedarf = $this->fuerZweck($team, $k['recipe'], $zweck, $k['menge_kg'], $k['label']);
                if ($bedarf !== null) {
                    // Ist die Komponente durchgaengig, sind DIESE Behaelter dieselben, die an ihrer
                    // eigenen Produktionszeile schon fuers Abfuellen gezaehlt wurden. Sie reisen mit;
                    // sie noch einmal zu zaehlen ergaebe doppeltes Geschirr auf dem Zettel.
                    $raus[] = $bedarf + ['bereits_gezaehlt' => $durchgaengig && $zweck === 'regenerieren'];
                }
            }
        }

        return $raus;
    }

    /**
     * Spec 83 B3d: Zahl der Regenerier-Behälter eines GERICHTS für eine Menge — gezählt wie im Produktionsblatt
     * ({@see jeKomponente}, Zweck `regenerieren`, Basis-Variante). Die Komponenten-Massen entstehen wie in
     * PlanungsblattService::explodiere: Brutto-Masse der Sub-Rezept-Zeile × Ansätze. Durchgängige Behälter
     * (`bereits_gezaehlt`) zählen hier MIT: Sie werden fürs Geschirr nicht doppelt gezählt, kommen aber trotzdem
     * in den Ofen und wieder heraus.
     *
     * @return array{anzahl:int, komponenten:list<array{label:string, anzahl:?int}>, nicht_berechenbar:list<string>}
     */
    public function regenerierBehaelter(Team $team, FoodAlchemistRecipe $gericht, float $ansaetze): array
    {
        return $this->behaelterFuerGericht($team, $gericht, $ansaetze, 'regenerieren');
    }

    /**
     * Wie {@see regenerierBehaelter}, für einen Zweck aus {@see jeKomponente} (`regenerieren` | `ausgabe`). Spec 83 B3i: Buffet/Platte
     * zählt die Ausgabe-Behälter.
     *
     * @return array{anzahl:int, komponenten:list<array{label:string, anzahl:?int}>, nicht_berechenbar:list<string>}
     */
    public function behaelterFuerGericht(Team $team, FoodAlchemistRecipe $gericht, float $ansaetze, string $zweck): array
    {
        $recompute = app(RecipeRecomputeService::class);
        $komponenten = [];
        foreach ($gericht->ingredients()->whereNotNull('referenced_recipe_id')->with('referencedRecipe')->get() as $z) {
            $sub = $z->referencedRecipe;
            $brutto = $recompute->bruttoMasseG($z);
            if ($sub !== null && $brutto > 0 && ! $z->is_optional) {
                $komponenten[] = ['recipe' => $sub, 'label' => (string) ($z->display_name ?: $sub->name), 'menge_kg' => round($brutto * max(0.0, $ansaetze) / 1000, 3)];
            }
        }
        $anzahl = 0;
        $liste = [];
        $offen = [];
        foreach ($this->jeKomponente($team, $komponenten) as $b) {
            if (($b['zweck'] ?? null) !== $zweck) {
                continue;
            }
            $n = ($b['berechenbar'] ?? false) ? (int) ($b['varianten'][0]['anzahl'] ?? 0) : null;
            if ($n === null) {
                $offen[] = (string) ($b['label'] ?? '?');
            }
            $anzahl += (int) $n;
            $liste[] = ['label' => (string) ($b['label'] ?? '?'), 'anzahl' => $n];
        }

        return ['anzahl' => $anzahl, 'komponenten' => $liste, 'nicht_berechenbar' => $offen];
    }

    /**
     * Trägt DIESES Rezept seine Ware durchgängig — vom Abfüllen bis in den Ofen?
     *
     * Ragout im GN mit Deckel geht aus dem Kühlhaus direkt in den Konvektomat: ein Behälter, eine
     * Zahl. Ein Eimer kann das nicht, dann wird umgefüllt und beide zählen.
     *
     * BEFUND beim Nachprüfen: die erste Fassung verglich die `abfuellen`-Zeile des GERICHTS mit der
     * `regenerieren`-Zeile der ERSTEN Komponente. Das sind zwei verschiedene Rezepte und zwei
     * verschiedene Behälter-Reisen — bei einem Gericht mit fünf Komponenten war das Ergebnis
     * schlicht willkürlich. Durchgängigkeit ist eine Eigenschaft EINES Rezepts.
     */
    public function istDurchgaengig(FoodAlchemistRecipe $recipe): bool
    {
        $zeilen = DB::table('foodalchemist_recipe_containers')
            ->where('recipe_id', $recipe->id)->whereNull('deleted_at')
            ->whereIn('zweck', ['abfuellen', 'regenerieren'])
            ->pluck('container_vocab_id', 'zweck');

        $ab = $zeilen['abfuellen'] ?? null;
        $re = $zeilen['regenerieren'] ?? null;

        return $ab !== null && $re !== null && (int) $ab === (int) $re;
    }

    /**
     * Der Bedarf EINES Rezepts, zusammengelegt: gleicher Behälter für Abfüllen und Regenerieren
     * heisst eine Zahl, nicht zwei.
     */
    public function zusammenlegen(Team $team, FoodAlchemistRecipe $recipe, ?array $abfuellen, ?float $mengeKg): array
    {
        if (! $this->istDurchgaengig($recipe)) {
            return ['durchgaengig' => false, 'anzahl' => null, 'behaelter' => null,
                'hinweis' => $abfuellen !== null ? 'Umfüllen am Einsatztag' : ''];
        }

        $regen = $this->fuerZweck($team, $recipe, 'regenerieren', $mengeKg, $recipe->name);
        $zusammen = $this->rechner->zusammenlegen($abfuellen, $regen);

        return $zusammen + ['behaelter' => $abfuellen['varianten'][0]['behaelter'] ?? null];
    }

    /**
     * Eine Zeile fürs Blatt: gedruckt wird EIN Wert, nie drei Varianten.
     *
     * »Küche entscheidet« heisst nicht, dass der Produktionsschein die Entscheidung abdruckt.
     * Er zeigt die Basis und sagt dazu, dass es Alternativen gibt — gewählt wird im Editor.
     */
    public static function kurz(?array $block): ?string
    {
        $teile = self::teile($block);

        return $teile === [] ? null : implode(' · ', $teile);
    }

    /** @return list<string> die einzelnen Aussagen (Abfüllen/durchgängig + je Komponente), wie sie `kurz()` mit · verbindet */
    private static function teile(?array $block): array
    {
        if ($block === null) {
            return [];
        }

        $teile = [];
        $zusammen = $block['zusammen'] ?? [];

        if (($zusammen['durchgaengig'] ?? false) && ($zusammen['behaelter'] ?? null) !== null) {
            // Ragout im GN mit Deckel: aus dem Kühlhaus direkt in den Ofen. Einmal zählen.
            $teile[] = "{$zusammen['anzahl']}× {$zusammen['behaelter']} · durchgängig";
        } elseif (($ab = self::varianteKurz($block['abfuellen'] ?? null)) !== null) {
            $teile[] = 'Abfüllen: '.$ab;
        }

        foreach ($block['je_komponente'] ?? [] as $k) {
            if ($k['bereits_gezaehlt'] ?? false) {
                continue;   // reist im eigenen Abfüllbehälter mit — an dessen Zeile schon gezählt
            }
            $kurz = self::varianteKurz($k);
            if ($kurz !== null) {
                $teile[] = ucfirst($k['zweck']).' '.$k['label'].': '.$kurz;
            }
        }

        return $teile;
    }

    /**
     * Kompakt-Form für die ENGE Tabellenzelle des Produktions-Editors: EINE kurze Summe („3× GN 1/1 65mm" bzw. „5 Behälter") plus die
     * volle Aufstellung je Komponente für ein Popover. Bei Gerichten wird der Bedarf aller Komponenten gebündelt; die lange Kette
     * („Ausgabe Chip: Zwiebelring: 1× GN … · geschätzt · 4 Alternativen · …") gehört nicht in die Zelle.
     *
     * Die Summe zählt die BASIS-Variante je Position (dieselbe wie `kurz()`), nie eine Alternative, und lässt Behälter weg, die schon an
     * einer anderen Zeile gezählt sind. Positionen ohne berechenbaren Bedarf zählen als Lücke.
     *
     * @return array{kurz: string, zeilen: list<string>, geschaetzt: bool, alternativen: int, luecken: int}|null
     */
    public static function kompakt(?array $block): ?array
    {
        $zeilen = self::teile($block);
        if ($zeilen === []) {
            return null;
        }

        $positionen = [];
        $zusammen = $block['zusammen'] ?? [];
        if (($zusammen['durchgaengig'] ?? false) && ($zusammen['behaelter'] ?? null) !== null) {
            $positionen[] = ['berechenbar' => true, 'varianten' => [['behaelter' => $zusammen['behaelter'], 'anzahl' => $zusammen['anzahl']]]];
        } elseif (($block['abfuellen'] ?? null) !== null) {
            $positionen[] = $block['abfuellen'];
        }
        foreach ($block['je_komponente'] ?? [] as $k) {
            if (! ($k['bereits_gezaehlt'] ?? false)) {
                $positionen[] = $k;
            }
        }

        $summe = [];       // Behälter-Name => Stückzahl
        $geschaetzt = false;
        $alternativen = 0;
        $luecken = 0;
        foreach ($positionen as $p) {
            $basis = ($p['berechenbar'] ?? false) ? ($p['varianten'][0] ?? null) : null;
            if ($basis === null) {
                $luecken++;

                continue;
            }
            $summe[(string) $basis['behaelter']] = ($summe[(string) $basis['behaelter']] ?? 0) + (int) $basis['anzahl'];
            $geschaetzt = $geschaetzt || (($basis['konfidenz'] ?? 'hoch') !== 'hoch');
            $alternativen += max(0, count($p['varianten'] ?? []) - 1);
        }

        $gesamt = array_sum($summe);
        $kurz = match (true) {
            $summe === [] => 'Angaben fehlen',
            count($summe) === 1 => $gesamt.'× '.array_key_first($summe),
            default => $gesamt.' Behälter',
        };

        return ['kurz' => $kurz, 'zeilen' => $zeilen, 'geschaetzt' => $geschaetzt, 'alternativen' => $alternativen, 'luecken' => $luecken];
    }

    /** Basis-Variante plus ehrlicher Hinweis auf Alternativen bzw. auf den Grund. */
    public static function varianteKurz(?array $ergebnis): ?string
    {
        if ($ergebnis === null) {
            return null;
        }
        if (! ($ergebnis['berechenbar'] ?? false)) {
            return $ergebnis['grund'] ?? null;
        }

        $basis = $ergebnis['varianten'][0] ?? null;
        if ($basis === null) {
            return null;
        }

        $text = isset($basis['stueck_je_behaelter'])
            ? "{$basis['anzahl']}× {$basis['behaelter']} (à {$basis['stueck_je_behaelter']} Stk.)"
            : "{$basis['anzahl']}× {$basis['behaelter']}";

        if (($basis['konfidenz'] ?? 'hoch') !== 'hoch') {
            $text .= ' · geschätzt';
        }
        $weitere = max(0, count($ergebnis['varianten']) - 1);
        if ($weitere > 0) {
            $text .= " · {$weitere} Alternative".($weitere > 1 ? 'n' : '');
        }

        return $text;
    }

    private function fuerZweck(Team $team, FoodAlchemistRecipe $recipe, string $zweck, ?float $mengeKg, string $label): ?array
    {
        $zeile = DB::table('foodalchemist_recipe_containers')
            ->where('recipe_id', $recipe->id)->where('zweck', $zweck)->whereNull('deleted_at')->first();

        if ($zeile === null || $zeile->container_vocab_id === null) {
            return null;                       // nichts hinterlegt — die Lücke gehört ans Rezept
        }

        $katalog = $this->katalog($team);
        $basisBehaelter = $katalog->firstWhere('id', (int) $zeile->container_vocab_id);
        if ($basisBehaelter === null) {
            return null;
        }

        $ergebnis = $this->rechner->varianten(
            (float) ($mengeKg ?? 0),
            [
                'container' => $basisBehaelter,
                'referenz_menge_kg' => $zeile->referenz_menge_kg,
                'dichteklasse' => $recipe->dichteklasse,
                'skalierung' => $zeile->skalierung,
                'max_schichthoehe_mm' => $zeile->max_schichthoehe_mm,
                'stueck_je_behaelter' => $zeile->stueck_je_behaelter,
                'stueck_gesamt' => $this->stueckGesamt($recipe, $mengeKg),
                'konfidenz_rang3' => false,
                'referenz_quelle' => $zeile->source ?? null,
            ],
            // Alternativen nur aus derselben Familie: ein Eimer ist keine Variante zu einem GN.
            $katalog->where('familie', $basisBehaelter->familie)->all(),
            $zweck
        );

        return $ergebnis + ['zweck' => $zweck, 'label' => $label, 'menge_kg' => $mengeKg];
    }

    /**
     * Masse → Stück, über den Stückertrag des Rezepts (yield_kg je yield_pieces).
     * Ohne beide Zahlen NULL — der Rechner meldet dann den Grund, statt eine Stückzahl zu raten.
     */
    private function stueckGesamt(FoodAlchemistRecipe $recipe, ?float $mengeKg): ?float
    {
        $stueckErtrag = $recipe->yield_pieces !== null ? (float) $recipe->yield_pieces : 0.0;
        $yieldKg = $recipe->yield_kg !== null ? (float) $recipe->yield_kg : 0.0;

        if ($mengeKg === null || $stueckErtrag <= 0 || $yieldKg <= 0) {
            return null;
        }

        return $mengeKg / ($yieldKg / $stueckErtrag);
    }

    /** Sichtbarkeit statt Eigentum: geerbte und globale Behälter sind am eigenen Rezept nutzbar. */
    private function katalog(Team $team)
    {
        return $this->katalogCache[(int) $team->id] ??= TeamScope::applyVisible(
            DB::table('foodalchemist_vocab_containers')->whereNull('deleted_at')->where('is_inactive', false),
            'team_id', $team
        )->get();
    }
}
