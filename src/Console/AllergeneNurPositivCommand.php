<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Support\AllergenAnnahme;

/**
 * Spec 85 (Dominique 19.10.) — Lieferanten-Merkmal `allergene_nur_positiv` pflegen. **Default ist Probelauf.**
 *
 * Bei Lieferanten, die in Necta nur positive Allergenwerte liefern, gilt beim Lesen „leer = angenommen nicht enthalten“
 * ({@see AllergenAnnahme}). Das Merkmal setzt nur, wer auf dem Server arbeitet (Plattform-Admin), immer mit Grund; Datum und
 * Person werden mitgeschrieben. Nur exakte Namen, mehrdeutige Treffer brechen ab; die Sperrliste lehnt ab, auch wenn ausdrücklich genannt.
 * Danach `foodalchemist:recompute`, weil Rezeptwerte gespeichert sind.
 */
class AllergeneNurPositivCommand extends Command
{
    /** Gemessen auf io (19.10.): 100 % teilbefüllt, 0 % jemals „nicht enthalten“. */
    public const STANDARD = ['BOS Food', 'Handelshof Mönchengladbach E', 'Kröswang', 'Edna'];

    /** Dominique 19.10.: diese nie (liefern auch „nein“) — exakter Name bzw. Präfix. */
    private const SPERRE_EXAKT = ['Handelshof Mönchengladbach'];

    private const SPERRE_PRAEFIX = ['Hanos'];

    protected $signature = 'foodalchemist:allergene-nur-positiv
        {--lieferant=* : exakter Lieferantenname (mehrfach); ohne Angabe die 4 gemessenen}
        {--grund= : Pflicht beim Setzen (z. B. „Necta-Messung io 19.10.: 0 % nicht_enthalten“)}
        {--von= : User-ID der verantwortlichen Person}
        {--aus : Merkmal zurücknehmen}
        {--apply : wirklich schreiben (ohne dieses Flag nur Probelauf)}';

    protected $description = 'Spec 85: Lieferanten markieren, die nur positive Allergenwerte liefern (leer = angenommen nicht enthalten).';

    public function handle(): int
    {
        $namen = $this->option('lieferant') ?: self::STANDARD;
        $aus = (bool) $this->option('aus');
        $apply = (bool) $this->option('apply');
        $grund = trim((string) $this->option('grund'));
        if (! $aus && $apply && $grund === '') {
            $this->error('--grund ist beim Setzen Pflicht.');

            return self::FAILURE;
        }

        $treffer = [];
        foreach ($namen as $name) {
            if ($this->gesperrt($name)) {
                $this->error("„{$name}“ steht auf der Sperrliste (liefert auch „nicht enthalten“) — abgelehnt.");

                return self::FAILURE;
            }
            $zeilen = DB::table('foodalchemist_suppliers')->where('name', $name)->whereNull('deleted_at')->get(['id', 'legacy_id', 'name', 'allergene_nur_positiv']);
            if ($zeilen->count() !== 1) {
                $this->error("„{$name}“: {$zeilen->count()} Treffer — nur genau ein exakter Treffer ist zulässig.");

                return self::FAILURE;
            }
            $treffer[] = $zeilen->first();
        }

        $tabelle = [];
        foreach ($treffer as $l) {
            [$artikel, $mitNein, $gps] = $this->messung((int) $l->id);
            $tabelle[] = [$l->id, $l->legacy_id ?? '—', $l->name, $l->allergene_nur_positiv ? 'an' : 'aus', $aus ? 'aus' : 'an',
                $artikel, $artikel > 0 ? round(100 * $mitNein / $artikel, 1) . ' %' : '—', $gps];
        }
        $this->table(['ID', 'Necta', 'Lieferant', 'jetzt', 'neu', 'Artikel mit Allergen-Zeile', 'davon mit „nicht enthalten“', 'GPs'], $tabelle);

        if (! $apply) {
            $this->info('Probelauf — nichts geschrieben. Mit --apply schreiben.');

            return self::SUCCESS;
        }
        DB::table('foodalchemist_suppliers')->whereIn('id', array_map(fn ($l) => (int) $l->id, $treffer))->update($aus
            ? ['allergene_nur_positiv' => false, 'allergene_nur_positiv_grund' => null, 'allergene_nur_positiv_seit' => null,
                'allergene_nur_positiv_von' => null, 'updated_at' => now()]
            : ['allergene_nur_positiv' => true, 'allergene_nur_positiv_grund' => $grund, 'allergene_nur_positiv_seit' => now()->toDateString(),
                'allergene_nur_positiv_von' => $this->option('von') !== null ? (int) $this->option('von') : null, 'updated_at' => now()]);
        AllergenAnnahme::vergessen();
        $this->info(count($treffer) . ' Lieferant(en) ' . ($aus ? 'zurückgenommen' : 'markiert') . '. Jetzt `php artisan foodalchemist:recompute` laufen lassen (Rezeptwerte).');

        return self::SUCCESS;
    }

    private function gesperrt(string $name): bool
    {
        if (in_array($name, self::SPERRE_EXAKT, true)) {
            return true;
        }
        foreach (self::SPERRE_PRAEFIX as $p) {
            if (str_starts_with(mb_strtolower($name), mb_strtolower($p))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:int,1:int,2:int} [Artikel mit Allergen-Zeile, davon mit mindestens einem „nicht_enthalten“, betroffene GPs] */
    private function messung(int $supplierId): array
    {
        $basis = DB::table('foodalchemist_item_allergens AS a')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'a.supplier_item_id')
            ->where('i.supplier_id', $supplierId)->whereNull('a.deleted_at')->whereNull('i.deleted_at');
        $nein = implode(' OR ', array_map(fn ($k) => "a.allergen_{$k} = 'nicht_enthalten'", array_keys(FoodAlchemistItemAllergen::ALLERGENE)));

        return [
            (clone $basis)->count(),
            (clone $basis)->whereRaw("({$nein})")->count(),
            (clone $basis)->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'a.supplier_item_id')
                ->whereNull('s.deleted_at')->distinct()->count('s.gp_id'),
        ];
    }
}
