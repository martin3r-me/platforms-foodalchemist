<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riegel für den Rückbau der Geschirr-DB (Dominique 2026-10-16): vor dem Drop wird GELESEN, ob die alten Tabellen noch Zeilen und die alten Spalten noch Werte
 * tragen — nicht nur, ob die Tabelle existiert. Ohne diese Prüfung ginge eine Zuordnung still verloren. Die Migration bricht mit klarer Meldung ab;
 * `foodalchemist:geschirr-typ-uebernehmen --apply --leeren` übernimmt vorher den Servier-Typ und räumt die alten Daten bewusst ab.
 */
final class GeschirrRueckbau
{
    /** Tabellen der alten Geschirr-Welt (Reihenfolge = Drop-Reihenfolge: Artikel vor Lieferanten). */
    public const TABELLEN = ['foodalchemist_tableware_items' => 'Geschirr-Artikel', 'foodalchemist_tableware_suppliers' => 'Geschirr-Lieferanten'];

    /** Spalten der alten Welt: Tabelle → [Spalte => Beschreibung]. */
    public const SPALTEN = [
        'foodalchemist_concept_slots' => ['tableware_item_id' => 'Konzept-Slots mit Haupt-Geschirr', 'tableware_alt_item_id' => 'Konzept-Slots mit Alternativ-Geschirr'],
        'foodalchemist_package_dishes' => ['tableware_item_id' => 'Paket-Posten mit Haupt-Geschirr', 'tableware_alt_item_id' => 'Paket-Posten mit Alternativ-Geschirr'],
        'foodalchemist_recipe_presentations' => ['tableware_item_id' => 'Darreichungen mit Standard-Geschirr'],
    ];

    /**
     * Was noch Daten trägt (leer = frei zum Droppen).
     *
     * @return array<string, int> Beschreibung → Anzahl
     */
    public static function offeneDaten(): array
    {
        $offen = [];
        foreach (self::TABELLEN as $tabelle => $label) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $q = DB::table($tabelle);
            if (Schema::hasColumn($tabelle, 'deleted_at')) {
                $q->whereNull('deleted_at');   // gelöschte Zeilen sind gewollt weg
            }
            if (($n = $q->count()) > 0) {
                $offen[$label] = $n;
            }
        }
        foreach (self::SPALTEN as $tabelle => $spalten) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            foreach ($spalten as $spalte => $label) {
                if (! Schema::hasColumn($tabelle, $spalte)) {
                    continue;
                }
                $q = DB::table($tabelle)->whereNotNull($spalte);
                if (Schema::hasColumn($tabelle, 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                if (($n = $q->count()) > 0) {
                    $offen[$label] = $n;
                }
            }
        }

        return $offen;
    }

    /** @throws \RuntimeException wenn die alte Welt noch Daten trägt */
    public static function pruefeOderAbbrechen(): void
    {
        $offen = self::offeneDaten();
        if ($offen === []) {
            return;
        }
        $zeilen = implode(', ', array_map(fn ($label, $n) => "{$label}: {$n}", array_keys($offen), $offen));

        throw new \RuntimeException(
            'Rückbau der Geschirr-DB gestoppt: es sind noch Daten da ('.$zeilen.'). '
            .'Erst „php artisan foodalchemist:geschirr-typ-uebernehmen --apply --leeren“ ausführen (übernimmt den Servier-Typ und räumt die alten Zuordnungen ab), danach erneut migrieren.'
        );
    }

    /**
     * Die alten Daten bewusst abräumen (nach der Typ-Übernahme): Zuordnungs-Spalten auf NULL, Artikel und Lieferanten hart löschen.
     *
     * @return array<string, int> Beschreibung → Anzahl der abgeräumten Einträge
     */
    public static function leeren(): array
    {
        $geleert = self::offeneDaten();
        foreach (self::SPALTEN as $tabelle => $spalten) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            foreach (array_keys($spalten) as $spalte) {
                if (Schema::hasColumn($tabelle, $spalte)) {
                    DB::table($tabelle)->whereNotNull($spalte)->update([$spalte => null]);
                }
            }
        }
        foreach (array_keys(self::TABELLEN) as $tabelle) {
            if (Schema::hasTable($tabelle)) {
                DB::table($tabelle)->delete();
            }
        }

        return $geleert;
    }
}
