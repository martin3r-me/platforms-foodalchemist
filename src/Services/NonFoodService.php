<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use RuntimeException;

/**
 * Non-Food am Lieferantenartikel: Regelstufe über den ganzen Bestand, manuelle Pflege, Einrichtung der Warengruppe 16.
 *
 * Reihenfolge der Erkennung: Regel (hier) → KI nur für „unklar“ ({@see \Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob}) → Prüfliste
 * (alles, was die KI nicht sicher entscheidet, bleibt `pruefung_offen`). Ein Urteil von Hand (`manuell`) wird nie überschrieben,
 * ein KI-Urteil nicht von der Regelstufe. Artikel ohne Signal bekommen KEINE Zeile — ohne Zeile gilt Food.
 */
class NonFoodService
{
    /** Konfidenz-Schwelle, ab der ein KI-Urteil ohne Prüfung gilt. */
    public const KI_SCHWELLE = 0.8;

    /**
     * Regelstufe über alle aktiven Artikel.
     *
     * @param  bool  $schreiben  false = nur zählen (Trockenlauf)
     * @return array{gesamt:int, nonfood:int, unklar:int, food:int, geschrieben:int, geloescht:int, je_lieferant:array<string, array{nonfood:int, unklar:int}>, unklar_ids:list<int>}
     */
    public function regelLauf(?int $teamId = null, bool $schreiben = false, ?int $limit = null): array
    {
        $r = ['gesamt' => 0, 'nonfood' => 0, 'unklar' => 0, 'food' => 0, 'geschrieben' => 0, 'geloescht' => 0, 'je_lieferant' => [], 'unklar_ids' => []];

        $tabelle = Schema::hasTable('foodalchemist_supplier_item_nonfood');
        if ($schreiben && ! $tabelle) {
            throw new RuntimeException('Tabelle foodalchemist_supplier_item_nonfood fehlt — erst migrieren.');
        }
        $vorhanden = $tabelle ? FoodAlchemistSupplierItemNonfood::query()->pluck('quelle', 'supplier_item_id')->all() : [];

        $hatGruppen = Schema::hasTable('foodalchemist_supplier_groups') && Schema::hasColumn('foodalchemist_suppliers', 'group_id');   // vor der Migration: kein Gruppen-Signal
        $query = DB::table('foodalchemist_supplier_items as i')
            ->leftJoin('foodalchemist_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->when($hatGruppen, fn ($q) => $q->leftJoin('foodalchemist_supplier_groups as g', 'g.id', '=', 's.group_id'))
            ->whereNull('i.deleted_at')
            ->when($teamId !== null, fn ($q) => $q->where('i.team_id', $teamId))
            ->select('i.id', 'i.team_id', 'i.designation', 'i.marketing_name', 'i.is_alcohol', 'i.ingredients_supplier', 's.name as lieferant', 's.branch as branche')
            ->when($hatGruppen, fn ($q) => $q->addSelect('g.code as gruppe'), fn ($q) => $q->selectRaw('NULL as gruppe'))
            ->selectRaw('EXISTS (SELECT 1 FROM foodalchemist_item_allergens a WHERE a.supplier_item_id = i.id) AS f_all')
            ->selectRaw('EXISTS (SELECT 1 FROM foodalchemist_item_nutritionals n WHERE n.supplier_item_id = i.id) AS f_nae')
            ->selectRaw('EXISTS (SELECT 1 FROM foodalchemist_item_declarations d WHERE d.supplier_item_id = i.id) AS f_dek')
            ->orderBy('i.id');

        $query->chunkById(2000, function ($zeilen) use (&$r, $schreiben, $vorhanden, $limit) {
            foreach ($zeilen as $z) {
                if ($limit !== null && $r['gesamt'] >= $limit) {
                    return false;
                }
                $r['gesamt']++;
                $hatFood = (bool) ($z->f_all || $z->f_nae || $z->f_dek || $z->is_alcohol || trim((string) $z->ingredients_supplier) !== '');
                $b = NonFoodRegeln::bewerte([
                    'bezeichnung' => $z->designation, 'verkaufsname' => $z->marketing_name, 'branche' => $z->branche,
                    'lieferant' => $z->lieferant, 'gruppe' => $z->gruppe, 'hat_food_daten' => $hatFood,
                ]);
                $r[$b['klasse']]++;
                if ($b['klasse'] !== 'food') {
                    $l = $z->lieferant ?? '–';
                    $r['je_lieferant'][$l] ??= ['nonfood' => 0, 'unklar' => 0];
                    $r['je_lieferant'][$l][$b['klasse']]++;
                }
                $alt = $vorhanden[$z->id] ?? null;
                if ($alt === FoodAlchemistSupplierItemNonfood::QUELLE_MANUELL || $alt === FoodAlchemistSupplierItemNonfood::QUELLE_KI) {
                    continue;   // Mensch und KI-Urteil bleiben stehen
                }
                if ($b['klasse'] === 'unklar') {
                    $r['unklar_ids'][] = (int) $z->id;
                }
                if (! $schreiben) {
                    continue;
                }
                if ($b['klasse'] === 'food') {
                    if ($alt !== null) {
                        FoodAlchemistSupplierItemNonfood::query()->where('supplier_item_id', $z->id)->forceDelete();
                        $r['geloescht']++;
                    }

                    continue;
                }
                FoodAlchemistSupplierItemNonfood::updateOrCreate(['supplier_item_id' => $z->id], [
                    'team_id' => $z->team_id,
                    'ist_nonfood' => $b['klasse'] === 'nonfood' ? true : null,
                    'quelle' => FoodAlchemistSupplierItemNonfood::QUELLE_REGEL,
                    'konfidenz' => $b['konfidenz'],
                    'begruendung' => $b['grund'],
                    'subkategorie' => $b['subkategorie'],
                    'pruefung_offen' => $b['klasse'] === 'unklar',
                ]);
                $r['geschrieben']++;
            }

            return null;
        }, 'i.id', 'id');

        return $r;
    }

    /**
     * Urteil von Hand: gewinnt gegen Regel und KI und wird von keinem Lauf überschrieben.
     *
     * @param  ?bool  $nonfood  true = Non-Food, false = Food, null = Urteil zurücknehmen (Zeile weg, Artikel gilt wieder als Food ohne Urteil)
     */
    public function setzeManuell(FoodAlchemistSupplierItem $item, ?bool $nonfood, ?string $subkategorie = null): ?FoodAlchemistSupplierItemNonfood
    {
        if ($subkategorie !== null && $subkategorie !== '' && ! in_array($subkategorie, NonFoodRegeln::unterkategorien(), true)) {
            throw new RuntimeException('Unbekannte Unterkategorie „'.$subkategorie.'“.');
        }
        if ($nonfood === null) {
            FoodAlchemistSupplierItemNonfood::query()->where('supplier_item_id', $item->id)->forceDelete();

            return null;
        }

        return FoodAlchemistSupplierItemNonfood::updateOrCreate(['supplier_item_id' => $item->id], [
            'team_id' => $item->team_id,
            'ist_nonfood' => $nonfood,
            'quelle' => FoodAlchemistSupplierItemNonfood::QUELLE_MANUELL,
            'konfidenz' => 1,
            'begruendung' => 'von Hand gesetzt',
            'subkategorie' => $nonfood && $subkategorie !== '' ? $subkategorie : null,
            'pruefung_offen' => false,
        ]);
    }

    /** Ist der Artikel als Non-Food entschieden (Regel sicher, KI sicher oder von Hand)? */
    public function istNonFood(int $supplierItemId): bool
    {
        return FoodAlchemistSupplierItemNonfood::query()->where('supplier_item_id', $supplierItemId)->where('ist_nonfood', true)->exists();
    }

    /**
     * Warengruppe 16 „Non-Food“ und ihre Unterkategorien anlegen — idempotent, als Kommando statt Migration (Spec 82 §6).
     *
     * @return array{wg_angelegt:bool, unterkategorien_angelegt:list<string>}
     */
    public function einrichten(Team $team, bool $schreiben): array
    {
        $out = ['wg_angelegt' => false, 'unterkategorien_angelegt' => []];
        $wg = FoodAlchemistLookupWarengruppe::query()->where('team_id', $team->id)->where('code', NonFoodRegeln::WG_CODE)->first();
        if ($wg === null) {
            $out['wg_angelegt'] = true;
            if ($schreiben) {
                $max = FoodAlchemistLookupWarengruppe::query()->where('team_id', $team->id)->max('sort_order');
                FoodAlchemistLookupWarengruppe::create(['team_id' => $team->id, 'code' => NonFoodRegeln::WG_CODE, 'name' => NonFoodRegeln::WG_NAME, 'sort_order' => (int) $max + 1]);
            }
        }
        $vorhanden = FoodAlchemistWarengruppeSubkategorie::query()->where('team_id', $team->id)->where('commodity_group_code', NonFoodRegeln::WG_CODE)->pluck('name')->all();
        foreach (NonFoodRegeln::unterkategorien() as $i => $name) {
            if (in_array($name, $vorhanden, true)) {
                continue;
            }
            $out['unterkategorien_angelegt'][] = $name;
            if ($schreiben) {
                FoodAlchemistWarengruppeSubkategorie::create(['team_id' => $team->id, 'commodity_group_code' => NonFoodRegeln::WG_CODE, 'name' => $name, 'position' => $i + 1]);
            }
        }

        return $out;
    }
}
