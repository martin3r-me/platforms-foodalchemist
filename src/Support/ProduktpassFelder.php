<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;

/**
 * Produktpass: welche gelesenen Felder wohin gehören und wie sie übernommen werden. EIN Katalog für Lese-Service,
 * Übernahme, Anzeige und MCP — kein Feld steht an zwei Stellen.
 *
 * Schlüssel im `extrakt.felder`: `zutatenliste`, `naehrwerte.<spalte>`, `zusatzstoffe.<stoff>`, `gebinde.<spalte>`,
 * `haltbarkeit.<lagerart>_days`, `herkunft`, `marke`. Allergene stehen getrennt unter `extrakt.allergene`.
 *
 * Klassen der Übernahme:
 *  - `direkt`       überschreibt (Entscheid Dominique: Nährwerte, Zutatenliste, Zusatzstoffe, MHD mit Lagerart)
 *  - `nur_leer`     direkt nur in ein LEERES Zielfeld (Gebinde: `qty` ist die Preisbasis); sonst Vorschlag mit Klick
 *  - `vorschlag`    nie direkt (Herkunft, Marke, Haltbarkeit ohne Lagerart)
 */
final class ProduktpassFelder
{
    /** Ab dieser Konfidenz schreibt die Quelle „produktpass" direkt; darunter bleibt es ein Vorschlag. */
    public const KONFIDENZ_DIREKT = 0.8;

    public const QUELLE_PASS = 'produktpass';

    public const QUELLE_WEB = 'websuche';

    /** Salz (g/100 g) → Natrium (mg/100 g): Salz / 2,5 in g, ×1000 für mg — gleiche Formel wie der Necta-Mapper (Skript 214: ×400). */
    public const SALZ_ZU_NATRIUM_MG = 400.0;

    private const NAEHRWERTE = ['energy_kcal', 'energy_kj', 'protein', 'fat', 'saturated_fat', 'carbs_absorbable', 'sugar', 'roughage', 'sodium'];

    private const GEBINDE = ['qty', 'unit_code', 'qty_ordering_per_packaging', 'packaging_unit'];

    private const HALTBARKEIT = ['chilled' => 'gekühlt', 'frozen' => 'tiefgekühlt', 'dry' => 'trocken'];

    /** @return array{objekt:string,spalte:string,klasse:string,typ:string,label:string}|null */
    public static function ziel(string $key): ?array
    {
        if ($key === 'zutatenliste') {
            return ['objekt' => 'supplier_item', 'spalte' => 'ingredients_supplier', 'klasse' => 'direkt', 'typ' => 'text', 'label' => 'Zutatenliste'];
        }
        [$gruppe, $name] = array_pad(explode('.', $key, 2), 2, '');
        switch ($gruppe) {
            case 'naehrwerte':
                if ($name === 'salz') {
                    return ['objekt' => 'item_nutritional', 'spalte' => 'sodium', 'klasse' => 'direkt', 'typ' => 'zahl', 'label' => 'Salz → Natrium (abgeleitet)'];
                }
                if (in_array($name, self::NAEHRWERTE, true)) {
                    return ['objekt' => 'item_nutritional', 'spalte' => $name, 'klasse' => 'direkt', 'typ' => 'zahl', 'label' => 'Nährwert '.$name];
                }
                break;
            case 'zusatzstoffe':
                $alle = FoodAlchemistItemDeclaration::STOFFE + FoodAlchemistItemDeclaration::FREIWILLIG;
                if (isset($alle[$name])) {
                    return ['objekt' => 'item_declaration', 'spalte' => $name, 'klasse' => 'direkt', 'typ' => 'jn', 'label' => (string) $alle[$name]];
                }
                break;
            case 'gebinde':
                if (in_array($name, self::GEBINDE, true)) {
                    return ['objekt' => 'supplier_item', 'spalte' => $name, 'klasse' => 'nur_leer', 'typ' => in_array($name, ['qty', 'qty_ordering_per_packaging'], true) ? 'zahl' : 'text', 'label' => 'Gebinde '.$name];
                }
                break;
            case 'haltbarkeit':
                foreach (self::HALTBARKEIT as $art => $label) {
                    if ($name === "{$art}_days") {
                        return ['objekt' => 'supplier_item', 'spalte' => "shelf_life_{$art}_days", 'klasse' => 'direkt', 'typ' => 'tage', 'label' => "Haltbarkeit {$label} (Tage)"];
                    }
                }
                if ($name === 'unklar_days') {
                    return ['objekt' => 'supplier_item', 'spalte' => '', 'klasse' => 'vorschlag', 'typ' => 'tage', 'label' => 'Haltbarkeit (Lagerart unklar)'];
                }
                break;
        }
        if ($key === 'herkunft') {
            return ['objekt' => 'supplier_item', 'spalte' => 'origin', 'klasse' => 'vorschlag', 'typ' => 'text', 'label' => 'Herkunft'];
        }
        if ($key === 'marke') {
            return ['objekt' => 'supplier_item', 'spalte' => 'brand', 'klasse' => 'vorschlag', 'typ' => 'text', 'label' => 'Marke'];
        }

        return null;
    }

    /** Alle gültigen Schlüssel (für Schema/Prompt). @return list<string> */
    public static function schluessel(): array
    {
        $keys = ['zutatenliste', 'herkunft', 'marke', 'haltbarkeit.unklar_days'];
        foreach (self::NAEHRWERTE as $n) {
            $keys[] = "naehrwerte.{$n}";
        }
        $keys[] = 'naehrwerte.salz';
        foreach (array_keys(FoodAlchemistItemDeclaration::STOFFE + FoodAlchemistItemDeclaration::FREIWILLIG) as $s) {
            $keys[] = "zusatzstoffe.{$s}";
        }
        foreach (self::GEBINDE as $g) {
            $keys[] = "gebinde.{$g}";
        }
        foreach (array_keys(self::HALTBARKEIT) as $a) {
            $keys[] = "haltbarkeit.{$a}_days";
        }

        return $keys;
    }

    /** @return list<string> die 14 EU-Allergen-Schlüssel. */
    public static function allergene(): array
    {
        return array_keys(FoodAlchemistItemAllergen::ALLERGENE);
    }

    /** Vergleichbare Form eines Wertes (für Marker und Gleichheits-Prüfung): Zahl ohne Nachkomma-Rauschen, sonst getrimmter Text. */
    public static function norm(mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        if (is_bool($wert)) {
            return $wert ? '1' : '0';
        }
        if (is_numeric($wert) && ! is_string($wert)) {
            return rtrim(rtrim(number_format((float) $wert, 4, '.', ''), '0'), '.');
        }
        $s = trim((string) $wert);
        if ($s !== '' && is_numeric($s)) {
            return rtrim(rtrim(number_format((float) $s, 4, '.', ''), '0'), '.');
        }

        return $s === '' ? null : $s;
    }
}
