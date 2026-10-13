<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §12 B3 · Vergleich von Fassungen: neueste Fassung einer Familie für ein Team und der Allergen-Unterschied zwischen zwei Ständen.
 *
 * Der Diff vergleicht den ENDSTAND der Allergen- und Zusatzstoff-Spalten (nicht nur das Flag `fassung_allergen_geaendert`, das nur den
 * unmittelbaren Vorgänger sieht): Wer zwei Fassungen überspringt, würde sonst die Änderung der ersten verpassen. Die Zwischenfassungen werden
 * mit Nummer, Änderungsnotiz und eigenem Flag mitgegeben, damit die Bestätigung zeigen kann, was dazwischen geschah.
 */
class FassungsVergleich
{
    /** allergen_* und additive_* ohne Zeitstempel und Konfidenz (wie FassungService::allergenBild). @return array<string,mixed> */
    public function allergenBild(FoodAlchemistRecipe $r): array
    {
        return collect($r->getAttributes())
            ->filter(fn ($v, $k) => (str_starts_with($k, 'allergen_') || str_starts_with($k, 'additive_'))
                && ! str_ends_with($k, '_at') && ! str_contains($k, 'confidence'))
            ->sortKeys()->all();
    }

    /** Die neueste Fassung der Familie, die das Team sieht (Abo-Hülle). */
    public function neuesteFuer(int $familieId, Team $team): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('ist_fassung', true)->where('fassung_von_id', $familieId)
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
    }

    /**
     * Allergen-Unterschied zwischen einem gemerkten Stand (Allergenbild als Array) und einer Fassung.
     *
     * @param  array<string,mixed>  $vonBild
     * @return array<string, array{von: mixed, nach: mixed}> Feld → Werte, nur die Unterschiede
     */
    public function bildDiff(array $vonBild, FoodAlchemistRecipe $nach): array
    {
        $nachBild = $this->allergenBild($nach);
        $diff = [];
        foreach (array_unique(array_merge(array_keys($vonBild), array_keys($nachBild))) as $feld) {
            if (($vonBild[$feld] ?? null) != ($nachBild[$feld] ?? null)) {
                $diff[$feld] = ['von' => $vonBild[$feld] ?? null, 'nach' => $nachBild[$feld] ?? null];
            }
        }
        ksort($diff);

        return $diff;
    }

    /**
     * Die Fassungen der Familie ZWISCHEN dem gemerkten und dem neuen Stand (nach Nummer), mit Notiz und Allergen-Flag.
     * Liest ohne Sichtbarkeitsfilter, gibt aber nur Nummer, Notiz und Flag zurück (das Änderungsprotokoll der Abonnenten, §12 B).
     *
     * @return list<array{fassung_id:int, fassung_nr:int, aenderungsnotiz:?string, allergen_geaendert:bool}>
     */
    public function zwischen(int $familieId, int $vonNr, int $nachNr): array
    {
        return FoodAlchemistRecipe::mitFassungen()->where('ist_fassung', true)->where('fassung_von_id', $familieId)
            ->where('fassung_nr', '>', $vonNr)->where('fassung_nr', '<=', $nachNr)->orderBy('fassung_nr')->get()
            ->map(fn ($f) => ['fassung_id' => (int) $f->id, 'fassung_nr' => (int) $f->fassung_nr, 'aenderungsnotiz' => $f->aenderungsnotiz,
                'allergen_geaendert' => (bool) $f->fassung_allergen_geaendert])->all();
    }
}
