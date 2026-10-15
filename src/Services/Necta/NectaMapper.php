<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;

/**
 * Necta Raw-API (camelCase, Stichprobe 15.10.2026) → FA-Spalten. Konventionen wie der bestehende Stamm (ImportSliceCommand und
 * die Nachpflege-Skripte), damit neue Zeilen nicht anders aussehen als die 264.516 vorhandenen:
 *  - Allergene: Necta 3/2/1/0 → enthalten / spuren / nicht_enthalten / NULL (unbekannt), Unterarten als JSON in `details`.
 *  - Deklaration: rohe Necta-Integer {0,1,3,NULL} (GL-09), Feldnamen wie der Alt-Import (DEKLARATIONEN).
 *  - Nährwerte je 100 g; `sodium` aus `sodium`, sonst Salz × 400 mg (Skript 214), `sugar`/`saturated_fat` aus Summenfeldern;
 *    nicht gemappte Werte (≠ NULL) landen in `raw_json`.
 *  - Einheit/Land: Necta-ID → Wert wie im Bestand; unbekanntes Land → `TODO_country_<id>` (Bestandskonvention).
 */
class NectaMapper
{
    /** Necta unitId → unit_code (lookup_unit der WaWi-Kopie, alle drei Werte im Bestand). */
    public const EINHEITEN = [1001 => 'kg', 1004 => 'l', 1005 => 'Stk'];

    /** Necta originCountryId → origin_country (lookup_country der WaWi-Kopie, wie im Bestand). */
    public const LAENDER = [1 => 'Österreich', 2 => 'Deutschland', 3 => 'Belgien', 9 => 'Frankreich', 14 => 'Italien',
        17 => 'Spanien?', 18 => 'Niederlande'];

    /** Necta-Allergen → FA-Spalte (14 Hauptallergene). */
    public const ALLERGENE = ['gluten' => 'allergen_gluten', 'crustaceans' => 'allergen_crustaceans', 'egg' => 'allergen_eggs',
        'fish' => 'allergen_fish', 'peanut' => 'allergen_peanuts', 'soy' => 'allergen_soy', 'milk' => 'allergen_milk',
        'nuts' => 'allergen_tree_nuts', 'celery' => 'allergen_celery', 'mustard' => 'allergen_mustard', 'sesame' => 'allergen_sesame',
        'sulfites' => 'allergen_sulphites', 'lupin' => 'allergen_lupin', 'molluscs' => 'allergen_molluscs'];

    /** Necta-Unterart → Schlüssel in `details` (wie Bestand). */
    public const ALLERGEN_UNTERARTEN = ['allergeneWheat' => 'wheat', 'allergeneRye' => 'rye', 'allergeneBarley' => 'barley',
        'allergeneOats' => 'oats', 'allergeneSpelt' => 'spelt', 'allergeneKamut' => 'kamut', 'allergeneAlmond' => 'almond',
        'allergeneHazelnut' => 'hazelnut', 'allergeneWalnut' => 'walnut', 'allergeneCashewNut' => 'cashew', 'allergenePecanNut' => 'pecan',
        'allergeneBrazilNut' => 'brazil', 'allergenePistachio' => 'pistachio', 'allergeneMacadamiaNut' => 'macadamia',
        'allergeneQueenslandNut' => 'queensland'];

    /**
     * Necta-Deklarationsfeld → FA-Spalte ({@see FoodAlchemistItemDeclaration::STOFFE}). 1:1 aus dem Alt-Import, der die 112.605
     * Bestandszeilen gebaut hat (`_legacy/POC_2026-05/46_build_master_db_1494.py`, DECLARATION_MAP, dort PascalCase aus dem
     * Necta-Export; Raw-API liefert dieselben Namen camelCase). Werte roh als Integer, wie im Bestand ({0,1,3}). Jod
     * (`declarationIodhTlig` → `iodized`, freiwillige Angabe, {@see FoodAlchemistItemDeclaration::FREIWILLIG}) seit 15.10.2026,
     * Bestand per `necta-deklaration-nachfuellen`.
     */
    public const DEKLARATIONEN = ['declarationWithDye' => 'with_dye', 'declarationWithPreservative' => 'with_preservative',
        'declarationWithAntioxidant' => 'with_antioxidant', 'declarationWithFlavourEnhancer' => 'with_flavour_enhancer',
        'declarationSulphurated' => 'sulphurated', 'declarationBlackened' => 'blackened', 'declarationWaxed' => 'waxed',
        'declarationWithPhosphate' => 'with_phosphate', 'declarationWithSweetener' => 'with_sweetener',
        'declarationContainsSourcePhenylalanine' => 'contains_phenylalanine',
        'declarationBExcessiveConsumptionCanHaveLaxativeEffect' => 'excessive_consumption_laxative',
        'declarationPackagedModifiedAtmosphere' => 'packaged_modified_atmosphere', 'declarationCaffeinated' => 'caffeinated',
        'declarationContainsMilkProtein' => 'contains_milk_protein', 'declarationContainsQuinine' => 'contains_quinine',
        'declarationTaurineContaining' => 'taurine_containing',
        'declarationCanImpairActivityAttentionChildren' => 'can_impair_attention_children',
        'declarationWithTypeSugarSweetener' => 'with_type_sugar_sweetener', 'declarationIodhTlig' => 'iodized'];

    /** Necta-Deklarationsfeld → Schlüssel in `details`, wie der Bestand (ImportSliceCommand: Integer bzw. Text). */
    public const DEKLARATION_DETAILS = ['declarationTableSweetenerBasedOn' => 'table_sweetener_basis',
        'declarationBasisTafeSweetness' => 'basis_tafe_sweetness'];

    /**
     * Necta-Felder, die der Alt-Import bewusst nicht übernommen hat (keine FA-Spalte, nicht im Bestand). Sie zählen nicht als
     * unbekannt und landen nicht in `details`, sonst wiche jede Bestandszeile im Vollvergleich ab (Prüffall je Zeile).
     */
    public const DEKLARATION_OHNE_SPALTE = ['declarationReserve'];

    /** Necta-Nährwert → FA-Spalte, wo der Name abweicht (sonst camelCase → snake_case). */
    public const NAEHRWERT_NAMEN = ['blsKey' => 'bls_key', 'energyKilocalories' => 'energy_kcal', 'energyKilojoules' => 'energy_kj',
        'waterCalculatedAccordingFormula' => 'water', 'carbohydratesAbsorbable' => 'carbs_absorbable', 'alcoholEthanol' => 'alcohol',
        'glucoseDextrose' => 'glucose', 'fructoseFructose' => 'fructose', 'galactoseMucusSugar' => 'galactose',
        'sucroseBeetSugar' => 'sucrose', 'maltoseMaltSugar' => 'maltose', 'lactoseMilkSugar' => 'lactose',
        'saturatedFattyAcids' => 'saturated_fat', 'totalSugar' => 'sugar'];

    /** FA-Nährwert-Spalten (ohne Kopf/Fremdschlüssel). */
    public const NAEHRWERT_SPALTEN = ['bls_key', 'energy_kcal', 'energy_kj', 'water', 'protein', 'fat', 'saturated_fat',
        'carbs_absorbable', 'sugar', 'roughage', 'minerals_crude_ash', 'organic_acids', 'alcohol', 'sodium', 'potassium', 'calcium',
        'magnesium', 'phosphorus', 'sulfur', 'chlorine', 'iron', 'zinc', 'copper', 'manganese', 'fluorine', 'iodine', 'fructose',
        'glucose', 'galactose', 'sucrose', 'maltose', 'lactose', 'starch', 'cellulose', 'isoleucine', 'leucine', 'lysine',
        'methionine', 'cysteine', 'phenylalanine', 'tyrosine', 'threonine', 'tryptophan', 'valine', 'arginine', 'histidine'];

    /** @return array<string,mixed> */
    public function lieferant(array $r): array
    {
        return [
            'legacy_id' => (int) $r['id'],
            'name' => self::text($r['name'] ?? null) ?? '—',
            'branch' => self::text($r['branch'] ?? null),
            'gln' => self::text($r['gln'] ?? null),
            'postal_code' => self::text($r['postalCode'] ?? null),
            'city' => self::text($r['city'] ?? null),
            'address' => self::text($r['address'] ?? null),
            'homepage' => self::text($r['homepage'] ?? null),
            'email_order' => self::text($r['emailOrder'] ?? null),
            'is_inactive' => (bool) ($r['isInactive'] ?? false),
        ];
    }

    /** @return array<string,mixed> */
    public function artikel(array $r): array
    {
        $land = isset($r['originCountryId']) && $r['originCountryId'] !== null ? (int) $r['originCountryId'] : null;

        return [
            'legacy_id' => (int) $r['id'],
            'article_number' => self::text($r['articleNumber'] ?? null, trim: false),
            'designation' => self::text($r['designation'] ?? null, trim: false) ?? '—',
            'marketing_name' => self::text($r['marketingName'] ?? null),
            'regulated_name' => self::text($r['regulatedName'] ?? null),
            'brand' => self::text($r['brand'] ?? null),
            'manufacturer' => self::text($r['manufacturer'] ?? null),
            'origin' => self::text($r['origin'] ?? null),
            'packaging_unit' => self::text($r['packagingUnit'] ?? null),
            'ordering_unit' => self::text($r['orderingUnit'] ?? null),
            'qty_ordering_per_packaging' => $r['quantityOrderingUnitsPerPackagingUnit'] ?? null,
            'qty' => $r['quantity'] ?? null,
            'ean_packaging' => self::text($r['eanPackagingUnit'] ?? null),
            'ean_ordering' => self::text($r['eanOrderingUnit'] ?? null),
            'additional_text' => self::text($r['text'] ?? null),
            'vat' => $r['vat'] ?? null,
            'unit_code' => isset($r['unitId']) ? (self::EINHEITEN[(int) $r['unitId']] ?? null) : null,
            'origin_country' => $land === null ? null : (self::LAENDER[$land] ?? "TODO_country_{$land}"),
            'organic_control_number' => self::text($r['organicControlNumber'] ?? null),
            'ingredients_supplier' => self::text($r['ingredients'] ?? null),
            'is_organic' => self::bool($r['isOrganic'] ?? null),
            'is_vegan' => self::bool($r['isVegan'] ?? null),
            'is_vegetarian' => self::bool($r['isVegetarian'] ?? null),
            'is_alcohol' => self::bool($r['isAlcohol'] ?? null),
            'is_halal' => self::bool($r['isHalal'] ?? null),
            'is_gmo_free' => self::bool($r['isGmoFree'] ?? null),
            'is_preorder' => self::bool($r['isPreorder'] ?? null),
            'preorder_days' => $r['preorderDays'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public function allergene(array $r): array
    {
        $zeile = ['legacy_id' => (int) $r['supplierItemId']];
        foreach (self::ALLERGENE as $quelle => $spalte) {
            $zeile[$spalte] = self::allergenWert($r[$quelle] ?? null);
        }
        $details = [];
        foreach (self::ALLERGEN_UNTERARTEN as $quelle => $schluessel) {
            if (($w = self::allergenWert($r[$quelle] ?? null)) !== null) {
                $details[$schluessel] = $w;
            }
        }
        ksort($details);
        $zeile['details'] = $details === [] ? null : json_encode($details);

        return $zeile;
    }

    /**
     * Deklaration nach {@see self::DEKLARATIONEN} / {@see self::DEKLARATION_DETAILS}. Nicht erkannte Felder ≠ NULL gehen in
     * `details` (und werden gezählt), damit eine neue Necta-Benennung sichtbar wird statt still zu verschwinden.
     *
     * @return array<string,mixed>
     */
    public function deklaration(array $r, ?array &$unbekannt = null): array
    {
        $zeile = ['legacy_id' => (int) $r['supplierItemId']];
        foreach ([...array_keys(FoodAlchemistItemDeclaration::STOFFE), ...array_keys(FoodAlchemistItemDeclaration::FREIWILLIG)] as $stoff) {
            $zeile[$stoff] = null;
        }
        $details = [];
        foreach ($r as $feld => $wert) {
            if (in_array($feld, ['supplierItemId', 'id', 'tenantId'], true) || in_array($feld, self::DEKLARATION_OHNE_SPALTE, true)
                || $wert === null || $wert === '') {
                continue;
            }
            if (isset(self::DEKLARATIONEN[$feld])) {
                $zeile[self::DEKLARATIONEN[$feld]] = (int) $wert;
            } elseif (isset(self::DEKLARATION_DETAILS[$feld])) {
                $details[self::DEKLARATION_DETAILS[$feld]] = $feld === 'declarationBasisTafeSweetness' ? (string) $wert : (int) $wert;
            } else {
                $details[self::snake($feld)] = $wert;
                $unbekannt[$feld] = true;
            }
        }
        ksort($details);
        $zeile['details'] = $details === [] ? null : json_encode($details);

        return $zeile;
    }

    /** @return array<string,mixed> */
    public function naehrwerte(array $r): array
    {
        $zeile = ['legacy_id' => (int) $r['supplierItemId']];
        foreach (self::NAEHRWERT_SPALTEN as $s) {
            $zeile[$s] = null;
        }
        $rest = [];
        foreach ($r as $feld => $wert) {
            if ($feld === 'supplierItemId' || $wert === null) {
                continue;
            }
            $spalte = self::NAEHRWERT_NAMEN[$feld] ?? self::snake($feld);
            if (in_array($spalte, self::NAEHRWERT_SPALTEN, true)) {
                $zeile[$spalte] = $wert;
            } else {
                $rest[$feld] = $wert;
            }
        }
        // Skript 214: Necta liefert meist Salz statt Natrium — Natrium (mg) = Salz (g) × 400
        if ($zeile['sodium'] === null && isset($r['totalTableSalt']) && is_numeric($r['totalTableSalt'])) {
            $zeile['sodium'] = round((float) $r['totalTableSalt'] * 400, 4);
        }
        ksort($rest);
        $zeile['raw_json'] = $rest === [] ? null : json_encode($rest);

        return $zeile;
    }

    /** @return array<string,mixed> */
    public function preis(array $r): array
    {
        return [
            'legacy_id' => (int) $r['id'],
            'status' => self::text(isset($r['status']) ? (string) $r['status'] : null),
            'price' => $r['price'] ?? null,
            'price_partial' => $r['pricePartial'] ?? null,
            'note' => self::text($r['note'] ?? null),
            'valid_to' => self::zeit($r['validTo'] ?? null),
            'status_valid_from' => self::zeit($r['statusValidFrom'] ?? null),
            'is_blocked' => (bool) ($r['isBlocked'] ?? false),
            'change_date' => self::zeit($r['changeDate'] ?? null),
            'creation_date' => self::zeit($r['creationDate'] ?? null),
        ];
    }

    public static function allergenWert(mixed $v): ?string
    {
        return match ((int) ($v ?? 0)) { 1 => 'nicht_enthalten', 2 => 'spuren', 3 => 'enthalten', default => null };
    }

    public static function snake(string $feld): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $feld));
    }

    private static function text(mixed $v, bool $trim = true): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;

        return trim($s) === '' ? null : ($trim ? trim($s) : $s);
    }

    private static function bool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    /** Necta-Zeitstempel ohne Zone = Europe/Berlin → UTC, wie der Bestand (ImportSliceCommand::utcOrNull). */
    public static function zeit(mixed $v): ?string
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $v, 'Europe/Berlin')->utc()->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
