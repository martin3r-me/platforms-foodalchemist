<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;

/**
 * Spec 85 (Dominique 19.10.): manche Lieferanten liefern in Necta nur POSITIVE Allergenwerte (enthalten/spuren, nie nicht_enthalten).
 * Bei Lieferanten mit Merkmal `allergene_nur_positiv` gilt beim LESEN: ein leerer Wert (NULL) = angenommen nicht enthalten.
 *
 * Die eine Stelle für diese Annahme. Geschrieben wird nichts — `foodalchemist_item_allergens` gehört dem Necta-Abgleich.
 * Nur NULL wird angenommen: ein ausdrücklich gespeichertes `unbekannt` (Handpflege) bleibt unbekannt, ein Artikel ganz
 * ohne Allergen-Zeile auch (Necta hat dann keinen Allergen-Block geliefert). Ein ausdrücklicher Wert gewinnt immer.
 */
final class AllergenAnnahme
{
    public const QUELLE = 'angenommen';

    public const HINWEIS = 'angenommen (Lieferant liefert nur Positivangaben)';

    /**
     * Dominique 23.10.: Anzeige eines angenommenen Werts. Necta lässt Spuren oft weg (BOS: 0 Spuren-Werte in 18.689 Artikeln; Edna-Datenblatt
     * 758 nennt „kann Spuren enthalten: Eier, Schalenfrüchte“, Necta k. A.) — „nicht enthalten“ gilt nur angenommen, Spuren sind unbekannt.
     * Etiketten und Speisekarten bleiben unverändert (Pflicht sind nur enthaltene Allergene).
     */
    public const ANZEIGE = 'nicht enthalten (angenommen) · Spuren unbekannt';

    public const ERKLAERUNG = 'Der Lieferant liefert nur positive Angaben. Ohne Angabe gilt das Allergen als nicht enthalten; Spuren gibt er oft nicht an, sie bleiben unbekannt.';

    public const WERT = 'nicht_enthalten';

    private const MEMO = 'foodalchemist.allergen_annahme_lieferanten';

    /** @return array<int, true> Lieferanten mit Merkmal (Memo im Container, je Request/Test frisch). */
    public static function lieferanten(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }
        $ids = DB::table('foodalchemist_suppliers')->where('allergene_nur_positiv', true)->whereNull('deleted_at')
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        app()->instance(self::MEMO, $ids);

        return $ids;
    }

    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    public static function giltFuer(?int $supplierId): bool
    {
        return $supplierId !== null && isset(self::lieferanten()[$supplierId]);
    }

    /**
     * Wirksamer Rohwert eines Feldes: der gespeicherte Wert, bei NULL + Merkmal die Annahme.
     *
     * @return array{wert: ?string, angenommen: bool}
     */
    public static function feld(?string $roh, bool $merkmal): array
    {
        return $roh === null && $merkmal ? ['wert' => self::WERT, 'angenommen' => true] : ['wert' => $roh, 'angenommen' => false];
    }

    /**
     * Alle 14 Felder einer Allergen-Zeile. Ohne Zeile: alles NULL, nichts angenommen.
     *
     * @return array<string, array{wert: ?string, angenommen: bool}>
     */
    public static function zeile(?FoodAlchemistItemAllergen $a, ?int $supplierId): array
    {
        $merkmal = $a !== null && self::giltFuer($supplierId);
        $out = [];
        foreach (array_keys(FoodAlchemistItemAllergen::ALLERGENE) as $k) {
            $out[$k] = self::feld($a?->getAttribute("allergen_{$k}"), $merkmal);
        }

        return $out;
    }

    /** SQL-Ausdruck für Bulk-Pfade: `$a` = Alias der Allergen-Zeile, `$lieferant` = Alias von foodalchemist_suppliers (LEFT JOIN). */
    public static function sqlWert(string $a, string $feld, string $lieferant): string
    {
        return "COALESCE({$a}.allergen_{$feld}, CASE WHEN {$lieferant}.allergene_nur_positiv = 1 THEN '" . self::WERT . "' END)";
    }
}
