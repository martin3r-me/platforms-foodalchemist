<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration;

/**
 * Spec 51 — die Regenerations-Kaskade: gelesen, nicht gespeichert.
 *
 * Wie eine Komponente regeneriert wird, ist eine Eigenschaft DER KOMPONENTE, nicht des Tellers.
 * »Pickles: Buchenpilze Süß-Sauer« wird in jedem Gericht kalt serviert. Bis Spec 51 musste die
 * Zeile in jedem Gericht neu getippt werden — n-fache Pflege, garantierte Drift.
 *
 * Deshalb wird die Liste am Gericht ABGELEITET. Gespeichert wird nur, was jemand dort bewusst
 * übersteuert. Muster: {@see SalesRecipeService::komponentenZeiten()} — »eine Spalte würde beim
 * nächsten Komponententausch driften«.
 *
 * Ränge:
 *   0 »Gesamt«   Zeile am Rezept mit ingredient_id IS NULL. Am Gericht: es wird als Ganzes
 *                regeneriert (Lasagne, Auflauf, Wrap). Am Basisrezept: »das bin ich« — der
 *                Default, den Gerichte erben. KEIN Altlast-Rest.
 *   1 Override   Zeile am Gericht mit gesetzter ingredient_id.
 *   2 geerbt     Die eigene(n) Gesamt-Zeile(n) des referenzierten Basisrezepts.
 *   3 Regel      GP-Komponente → aus `gps.condition` (config `foodalchemist.regeneration_regeln`).
 *   4 fehlt      Nichts davon. Wird GEZÄHLT und angezeigt, nie stillschweigend leer gelassen.
 *
 * TIEFE 1, nicht 3. Nur die Direkt-Komponenten des Gerichts werden regeneriert; der Fond im
 * Ragout taucht am Gericht nicht auf. Er wird produziert und gelagert, nicht am Pass gewärmt.
 *
 * VERTRAG ZUM MODUS: es gibt kein `modus`-Feld. `device_vocab_id IS NULL` heisst »kalt
 * servieren« — eine getroffene Entscheidung. KEINE Zeile heisst »fehlt«. Ohne diesen
 * Unterschied wäre Rang 3 von Rang 4 nicht zu trennen.
 */
class RegenerationCascadeService
{
    public const HERKUNFT_GESAMT = 'gesamt';

    public const HERKUNFT_OVERRIDE = 'override';

    public const HERKUNFT_GEERBT = 'geerbt';

    public const HERKUNFT_REGEL = 'regel';

    public const HERKUNFT_FEHLT = 'fehlt';

    /**
     * @return array{gesamt: array, komponenten: array, luecken: int, verwaist: array}
     */
    public function fuerRezept(FoodAlchemistRecipe $recipe): array
    {
        $eigene = FoodAlchemistRecipeRegeneration::query()
            ->where('recipe_id', $recipe->id)
            ->with('device:id,name')
            ->orderBy('sort_order')->get();

        $gesamt = $eigene->whereNull('ingredient_id')->values();
        $overrides = $eigene->whereNotNull('ingredient_id')->groupBy('ingredient_id');

        $zutaten = $this->direkteKomponenten($recipe);
        $lebendeIds = $zutaten->pluck('id')->map(fn ($v) => (int) $v)->flip();

        $komponenten = [];
        $luecken = 0;

        foreach ($zutaten as $zutat) {
            $zeilen = $this->fuerKomponente($zutat, $overrides->get((string) $zutat->id));
            foreach ($zeilen as $zeile) {
                if ($zeile['herkunft'] === self::HERKUNFT_FEHLT) {
                    $luecken++;
                }
            }
            $komponenten = [...$komponenten, ...$zeilen];
        }

        return [
            'gesamt' => $gesamt->map(fn ($z) => $this->zeile($z, self::HERKUNFT_GESAMT))->all(),
            'komponenten' => $komponenten,
            'luecken' => $luecken,
            'verwaist' => $this->verwaiste($overrides, $lebendeIds),
        ];
    }

    /**
     * Direkte Komponenten — Tiefe 1. `whereNull('deleted_at')` ist nicht kosmetisch:
     * `syncIngredients` SOFT-löscht entfernte Zutaten, und `nullOnDelete` greift dabei nicht.
     * Ohne den Filter zeigte die Kaskade Zeilen zu Komponenten, die es nicht mehr gibt.
     */
    private function direkteKomponenten(FoodAlchemistRecipe $recipe)
    {
        return FoodAlchemistRecipeIngredient::query()
            ->where('recipe_id', $recipe->id)
            ->whereNull('deleted_at')
            ->with([
                'referencedRecipe:id,name',
                'gp:id,name,condition',
            ])
            ->orderBy('position')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function fuerKomponente(FoodAlchemistRecipeIngredient $zutat, $overrides): array
    {
        $label = $this->label($zutat);

        // Rang 1 — jemand hat an DIESEM Gericht bewusst abweichend entschieden.
        if ($overrides !== null && $overrides->isNotEmpty()) {
            return $overrides->map(fn ($z) => $this->zeile($z, self::HERKUNFT_OVERRIDE, $zutat, $label))->all();
        }

        // Rang 2 — der Default der Komponente. Änderungen dort wirken hier sofort durch.
        if ($zutat->referenced_recipe_id !== null) {
            $geerbt = FoodAlchemistRecipeRegeneration::query()
                ->where('recipe_id', $zutat->referenced_recipe_id)
                ->whereNull('ingredient_id')
                ->with('device:id,name')
                ->orderBy('sort_order')->get();

            if ($geerbt->isNotEmpty()) {
                return $geerbt->map(fn ($z) => $this->zeile(
                    $z, self::HERKUNFT_GEERBT, $zutat, $label, $zutat->referencedRecipe
                ))->all();
            }
        }

        // Rang 3 — nur was aus dem Zustand wirklich folgt (frisch/trocken = kalt servieren).
        $regel = $zutat->gp?->condition !== null
            ? (config('foodalchemist.regeneration_regeln')[$zutat->gp->condition] ?? null)
            : null;

        if ($regel !== null) {
            return [$this->virtuelleZeile($zutat, $label, $regel, self::HERKUNFT_REGEL)];
        }

        // Rang 4 — Lücke. Sichtbar, nicht still.
        return [$this->virtuelleZeile($zutat, $label, [], self::HERKUNFT_FEHLT)];
    }

    /** Gespeicherte Zeile → Anzeigeform. */
    private function zeile(
        FoodAlchemistRecipeRegeneration $z,
        string $herkunft,
        ?FoodAlchemistRecipeIngredient $zutat = null,
        ?string $label = null,
        ?FoodAlchemistRecipe $von = null
    ): array {
        // `regeneration_id` heisst »Zeile AN DIESEM Rezept« — nur die kann der Editor
        // aktualisieren, zuruecksetzen oder loeschen. Bei einer GEERBTEN Zeile gehoert die Id der
        // Komponente; sie hier durchzureichen liesse den Editor gegen ein fremdes Rezept updaten
        // (und still nichts tun). Die Herkunft steht separat in `quelle_regeneration_id`.
        $geerbt = $herkunft === self::HERKUNFT_GEERBT;

        return [
            'regeneration_id' => $geerbt ? null : (int) $z->id,
            'quelle_regeneration_id' => (int) $z->id,
            'ingredient_id' => $zutat !== null ? (int) $zutat->id : ($z->ingredient_id !== null ? (int) $z->ingredient_id : null),
            'label' => $label ?? (string) $z->component_label,
            'device_vocab_id' => $z->device_vocab_id !== null ? (int) $z->device_vocab_id : null,
            'device' => $z->device?->name,
            'temp_c' => $z->temp_c,
            'duration_min' => $z->duration_min,
            'core_temp_c' => $z->core_temp_c,
            'note' => $z->note,
            'ist_kalt' => $z->device_vocab_id === null,
            'herkunft' => $herkunft,
            'von_recipe_id' => $von?->id !== null ? (int) $von->id : null,
            'von_recipe_name' => $von?->name,
        ];
    }

    /** Abgeleitete Zeile (Regel/Lücke) — hat keine ID, weil sie nirgends steht. */
    private function virtuelleZeile(
        FoodAlchemistRecipeIngredient $zutat,
        string $label,
        array $werte,
        string $herkunft
    ): array {
        return [
            'regeneration_id' => null,
            'quelle_regeneration_id' => null,
            'ingredient_id' => (int) $zutat->id,
            'label' => $label,
            'device_vocab_id' => $werte['device_vocab_id'] ?? null,
            'device' => null,
            'temp_c' => $werte['temp_c'] ?? null,
            'duration_min' => $werte['duration_min'] ?? null,
            'core_temp_c' => $werte['core_temp_c'] ?? null,
            'note' => $werte['note'] ?? null,
            'ist_kalt' => $herkunft === self::HERKUNFT_REGEL && ($werte['device_vocab_id'] ?? null) === null,
            'herkunft' => $herkunft,
            'von_recipe_id' => null,
            'von_recipe_name' => null,
        ];
    }

    /**
     * Overrides, deren Komponenten-Zeile es nicht mehr gibt.
     *
     * Zwei Wege dorthin: `syncIngredients` soft-löscht eine entfernte Zutat (nullOnDelete greift
     * nicht), oder ein Tausch hängt die Zeile auf ein anderes Rezept um — dann beschreibt der
     * Override plötzlich etwas anderes. Beides gehört gemeldet, nicht versteckt.
     *
     * @return array<int, array{regeneration_id:int, ingredient_id:int, label:string}>
     */
    private function verwaiste($overrides, $lebendeIds): array
    {
        $raus = [];
        foreach ($overrides as $ingredientId => $zeilen) {
            if (isset($lebendeIds[(int) $ingredientId])) {
                continue;
            }
            foreach ($zeilen as $z) {
                $raus[] = [
                    'regeneration_id' => (int) $z->id,
                    'ingredient_id' => (int) $ingredientId,
                    'label' => (string) $z->component_label,
                ];
            }
        }

        return $raus;
    }

    /** Anzeigename der Komponente: Basisrezept > Grundprodukt > Freitext der Zutatenzeile. */
    private function label(FoodAlchemistRecipeIngredient $zutat): string
    {
        return (string) ($zutat->referencedRecipe?->name ?? $zutat->gp?->name ?? $zutat->display_name ?? '—');
    }
}
