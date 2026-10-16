<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Schutz der Produktpass-Werte vor dem Necta-Abgleich. Ein Pass-Wert gilt wie Handpflege.
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

    /** @param object $fa  Zeile aus `foodalchemist_supplier_items` bzw. einer Detail-Tabelle (item_*) */
    public static function schuetzt(string $objekt, object $fa, string $spalte): bool
    {
        if (self::$hatMarker === null) {
            self::$hatMarker = DB::table('foodalchemist_produktpass_felder')->exists();
        }
        if (self::$hatMarker === false) {
            return false;
        }
        $itemId = $objekt === 'supplier_item' ? (int) ($fa->id ?? 0) : (int) ($fa->supplier_item_id ?? 0);
        if ($itemId === 0) {
            return false;
        }
        $marker = DB::table('foodalchemist_produktpass_felder')
            ->where('supplier_item_id', $itemId)->where('objekt', $objekt)->where('feld', $spalte)->first(['wert_norm']);

        return $marker !== null && ProduktpassFelder::norm($marker->wert_norm) === ProduktpassFelder::norm($fa->{$spalte} ?? null);
    }
}
