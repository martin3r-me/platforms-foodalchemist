<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly FassungService $fassungen) {}

    /** allergen_* und additive_* ohne Zeitstempel und Konfidenz — EINE Definition, die der Fassungs-Veröffentlichung. @return array<string,mixed> */
    public function allergenBild(FoodAlchemistRecipe $r): array
    {
        return $this->fassungen->allergenBild($r);
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
        // Spec 84 B3 Regel B: Zwischenfassungen sind Historie, keine Nutzung — lesbar, solange das Team die Familie sieht
        return \Platform\FoodAlchemist\Support\AboSicht::historie(\Platform\FoodAlchemist\Support\FaTeam::aktuell(), 'recipe', $familieId)
            ->where('fassung_nr', '>', $vonNr)->where('fassung_nr', '<=', $nachNr)->orderBy('fassung_nr')->get()
            ->map(fn ($f) => ['fassung_id' => (int) $f->id, 'fassung_nr' => (int) $f->fassung_nr, 'aenderungsnotiz' => $f->aenderungsnotiz,
                'allergen_geaendert' => (bool) $f->fassung_allergen_geaendert])->all();
    }

    /** Merge-Rang des 4-Wert-Allergenmodells (GL-01): enthalten > spuren > nicht_enthalten > unbekannt; sonstige Werte (Zusatzstoff-Flags) nach Wahrheitswert. */
    private const RANG = ['enthalten' => 4, 'spuren' => 3, 'nicht_enthalten' => 2, 'unbekannt' => 1];

    /**
     * Allergen- und Zusatzstoff-Bild eines Konzepts oder Pakets (Fassung) über die ganze Kette: je Feld der höchste Rang aller Rezept-Fassungen
     * darunter (ALL-MAXIMAL wie beim Rezept). Ein Konzept/Paket trägt keine eigenen Allergenspalten, das Erbe kommt aus den Rezepten.
     *
     * @param  'concept'|'paket'|'format'  $art
     * @return array<string,mixed>
     */
    public function kettenBild(string $art, int $fassungId): array
    {
        return $this->kettenBildAus($this->rezeptIdsDerKette($art, $fassungId));
    }

    /**
     * Ketten-Bild (höchster Rang je Feld) aus einer Menge Rezept-Fassungen — auch für die Vorschau „was würde das Veröffentlichen ändern?“.
     *
     * @param  list<int>  $rezeptIds
     * @return array<string,mixed>
     */
    public function kettenBildAus(array $rezeptIds): array
    {
        $bild = [];
        $rezepte = FoodAlchemistRecipe::mitFassungen()->whereIn('id', $rezeptIds)->get();
        foreach ($rezepte as $r) {
            foreach ($this->allergenBild($r) as $feld => $wert) {
                if (! array_key_exists($feld, $bild) || $this->rang($wert) > $this->rang($bild[$feld])) {
                    $bild[$feld] = $wert instanceof \BackedEnum ? $wert->value : $wert;
                }
            }
        }
        ksort($bild);

        return $bild;
    }

    /**
     * Diff zweier Ketten-Bilder (von → nach), nur die Unterschiede.
     *
     * @param  array<string,mixed>  $von
     * @param  array<string,mixed>  $nach
     * @return array<string, array{von: mixed, nach: mixed}>
     */
    public function bilderDiff(array $von, array $nach): array
    {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($von), array_keys($nach))) as $feld) {
            if (($von[$feld] ?? null) != ($nach[$feld] ?? null)) {
                $diff[$feld] = ['von' => $von[$feld] ?? null, 'nach' => $nach[$feld] ?? null];
            }
        }
        ksort($diff);

        return $diff;
    }

    /** Alle Rezept-IDs unter einem Konzept bzw. Paket (Slots, Paket-Gerichte, eingebettete Paket-Konzepte rekursiv). @return list<int> */
    public function rezeptIdsDerKette(string $art, int $id, int $tiefe = 0): array
    {
        if ($tiefe > 4) {
            return [];
        }
        if ($art === 'format') {   // Format → Konzepte der Slots → deren Kette
            $ids = [];
            foreach (DB::table('foodalchemist_format_slots')->where('format_id', $id)->whereNull('deleted_at')->whereNotNull('concept_id')->pluck('concept_id')->unique() as $cid) {
                array_push($ids, ...$this->rezeptIdsDerKette('concept', (int) $cid, $tiefe + 1));
            }

            return array_values(array_unique($ids));
        }
        if ($art === 'paket') {
            return DB::table('foodalchemist_package_dishes')->where('package_id', $id)->whereNull('deleted_at')
                ->pluck('sales_recipe_id')->map(fn ($v) => (int) $v)->all();
        }
        $slots = DB::table('foodalchemist_concept_slots')->where('concept_id', $id)->whereNull('deleted_at')->get(['package_id', 'embedded_concept_id', 'sales_recipe_id']);
        $ids = $slots->pluck('sales_recipe_id')->filter()->map(fn ($v) => (int) $v)->all();
        foreach ($slots->pluck('package_id')->filter()->unique() as $pid) {
            array_push($ids, ...$this->rezeptIdsDerKette('paket', (int) $pid, $tiefe + 1));
        }
        foreach ($slots->pluck('embedded_concept_id')->filter()->unique() as $cid) {
            array_push($ids, ...$this->rezeptIdsDerKette('concept', (int) $cid, $tiefe + 1));
        }

        return array_values(array_unique($ids));
    }

    private function rang(mixed $wert): int
    {
        if ($wert instanceof \BackedEnum) {
            $wert = $wert->value;
        }

        return is_string($wert) && isset(self::RANG[$wert]) ? self::RANG[$wert] : ($wert ? 1 : 0);
    }
}
