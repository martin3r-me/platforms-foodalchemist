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
            foreach (['regenerieren', 'ausgabe'] as $zweck) {
                $bedarf = $this->fuerZweck($team, $k['recipe'], $zweck, $k['menge_kg'], $k['label']);
                if ($bedarf !== null) {
                    $raus[] = $bedarf;
                }
            }
        }

        return $raus;
    }

    /**
     * Zählt einen durchgängigen Behälter EINMAL.
     *
     * Ragout im GN mit Deckel geht aus dem Kühlhaus direkt in den Ofen. Beide Zeilen zweimal zu
     * zählen hiesse doppeltes Geschirr auf dem Zettel — und niemand glaubt der Liste mehr.
     */
    public function zusammenlegen(?array $abfuellen, array $jeKomponente): array
    {
        $regen = collect($jeKomponente)->firstWhere('zweck', 'regenerieren');
        $zusammen = $this->rechner->zusammenlegen($abfuellen, $regen);

        return $zusammen['durchgaengig']
            ? $zusammen + ['behaelter' => $abfuellen['varianten'][0]['behaelter'] ?? null]
            : $zusammen;
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
