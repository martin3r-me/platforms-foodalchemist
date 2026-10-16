<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Schutz der Produktpass-Werte vor dem Necta-Abgleich.
 *
 * Vorrang (Entscheid Dominique 16.10.2026, Variante 1): in den KERNGRUPPEN — Allergene samt Unterarten, Zusatzstoffe/Deklaration,
 * Nährwerte, Zutatenliste — gilt ein Feld mit Marker IMMER mit dem Pass-Wert, ohne Strenge-Regel; jede Necta-Abweichung wird ein
 * Prüffall `produktpass_abweichung` mit Signal. Alle übrigen Felder kommen aus Necta, der Marker schützt dort nur davor, dass ein
 * LEERER Necta-Wert den Pass-Wert löscht ({@see self::istKern()}).
 *
 * Warum nicht einfach „FA ≠ necta_stand"? Drei Lücken: (a) Kein Stand (erster Abgleich) → Necta würde übernehmen;
 * (b) Der Pass bestätigt zufällig den Necta-Stand, Necta ändert später → der Pass-Wert fiele als „nicht von Hand" weg;
 * (c) die Kennzeichnung (Allergene/Deklaration) folgt ohnehin der Strenge-Regel. Darum der ausdrückliche Marker
 * (`foodalchemist_produktpass_felder`): geschützt ist ein Feld, solange der FA-Wert dem Marker-Wert entspricht — wird es danach
 * von Hand geändert, gilt die normale Handpflege-Logik.
 */
final class ProduktpassSchutz
{
    /** Gibt es überhaupt Marker? Der Abgleich ruft das je abweichendem Feld — ohne Marker kostet es keine Abfrage je Feld. */
    private static ?bool $hatMarker = null;

    public static function zuruecksetzen(): void
    {
        self::$hatMarker = null;
    }

    /** Kerngruppe? Allergene (auch die Unterarten in `details`), Deklaration und Nährwerte ganz, am Artikel nur die Zutatenliste. */
    public static function istKern(string $objekt, string $feld): bool
    {
        return in_array($objekt, ['item_allergen', 'item_declaration', 'item_nutritional'], true)
            || ($objekt === 'supplier_item' && $feld === 'ingredients_supplier');
    }

    /** @param object $fa  Zeile aus `foodalchemist_supplier_items` bzw. einer Detail-Tabelle (item_*) */
    public static function schuetzt(string $objekt, object $fa, string $spalte): bool
    {
        return self::schuetztWert($objekt, self::itemId($objekt, $fa), $spalte, $fa->{$spalte} ?? null);
    }

    /** Wie {@see schuetzt()}, mit dem FA-Wert von außen. Unterarten (`details`) vergleicht es als JSON-Objekt. */
    public static function schuetztWert(string $objekt, int $itemId, string $feld, mixed $faWert): bool
    {
        if (self::$hatMarker === null) {
            self::$hatMarker = DB::table('foodalchemist_produktpass_felder')->exists();
        }
        if (self::$hatMarker === false || $itemId === 0) {
            return false;
        }
        $marker = DB::table('foodalchemist_produktpass_felder')
            ->where('supplier_item_id', $itemId)->where('objekt', $objekt)->where('feld', $feld)->first(['wert_norm']);

        if ($marker === null) {
            return false;
        }
        if ($feld === 'details') {
            return self::jsonNorm($marker->wert_norm) === self::jsonNorm($faWert);   // Unterarten: MySQL-JSON formatiert um
        }

        return ProduktpassFelder::norm($marker->wert_norm) === ProduktpassFelder::norm($faWert);
    }

    /** JSON-Objekt vergleichbar machen (Schlüssel sortiert, kompakt); leer → NULL. */
    public static function jsonNorm(mixed $wert): ?string
    {
        $a = is_string($wert) ? json_decode($wert, true) : $wert;
        if (! is_array($a)) {
            return $wert === null || $wert === '' ? null : (string) json_encode($wert);
        }
        $a = array_filter($a, fn ($v) => $v !== null);
        ksort($a);

        return $a === [] ? null : (string) json_encode($a, JSON_UNESCAPED_UNICODE);
    }

    public static function itemId(string $objekt, object $fa): int
    {
        return $objekt === 'supplier_item' ? (int) ($fa->id ?? 0) : (int) ($fa->supplier_item_id ?? 0);
    }
}
