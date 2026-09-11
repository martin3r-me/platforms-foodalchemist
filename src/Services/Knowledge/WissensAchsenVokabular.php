<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;

/**
 * Spec 52 — welche WERTE eine Geltungs-Achse tragen darf.
 *
 * ★ Das hier ist bewusst **keine neue Liste**, sondern ein Verweis auf die vorhandenen
 * Quellen. Der Anlass: beim ersten Einordnen habe ich dreimal Vokabular erfunden, das es
 * schon gab — `protein` statt `komponente`, `obst_zitrus` statt der GP-Warengruppe,
 * `format` als Achsenname neben dem Format-Modul. Solche Werte fallen nicht auf: sie
 * treffen einfach nie. Bei sechs Dossiers ist das billig, bei 1.073 nicht.
 *
 * **Gespeichert wird der stabile Bezeichner der Quelle**, nicht ein abgeleiteter Slug:
 * bei Warengruppen und Speisen-Hauptgruppen der Code, sonst der Wert selbst. Aus Labels
 * gebaute Slugs wären (a) wieder erfunden und (b) würden bei jeder Label-Korrektur alle
 * Tags brechen — die Labels sind heute schon uneinheitlich („01 Gemuese & Blattsalat"
 * trägt den Code im Text, „Kraeuter" nicht).
 *
 * ⚠ **Leere Quelle = keine Einschränkung.** Eine frische Umgebung ohne Stammdaten darf
 * nicht jedes Taggen blockieren; gegen ein Vokabular, das nicht da ist, lässt sich nicht
 * prüfen. Das ist die einzige Stelle, an der hier bewusst nichts durchgesetzt wird.
 */
final class WissensAchsenVokabular
{
    /**
     * Verpflegungskontexte.
     *
     * Warum hier und nicht in {@see FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES}:
     * dort fehlt `sektor` ABSICHTLICH — ein Test pinnt das (`BriefingLeitplankenTest`), weil
     * im Code zwei Wertesätze konkurrieren. Das sind die fünf, die die Leitstelle real setzt
     * und die `recipes.GENERATE` als Enum führt. Kein dritter Satz, sondern der benutzte.
     */
    private const SEKTOREN = ['betriebsgastronomie', 'catering', 'restaurant', 'care', 'schule_kita'];

    /**
     * Ausgabeformen — die Zielwerte der Ableitung plus die beiden, für die es keine
     * Sektor/Serviceform-Kombination gibt und die darum ausdrücklich gesetzt werden.
     * Quelle: die Multiplikator-Tabelle in `mengen_defaults--format-multiplikatoren-*`.
     */
    private const AUSGABEFORMEN = [
        'a_la_carte', 'bankett_tellergericht', 'bankett_buffet',
        'volumen_catering', 'foodtruck', 'sweet_table',
    ];

    /**
     * Erlaubte Werte einer Achse als `wert => label`, oder `null` wenn die Achse nicht
     * eingeschränkt ist (dann wird nichts geprüft).
     *
     * @return array<string, string>|null
     */
    public static function werte(string $achse): ?array
    {
        $werte = match ($achse) {
            'gang' => self::ausTabelle('foodalchemist_dish_main_groups'),
            'warengruppe' => self::ausTabelle('foodalchemist_lookup_commodity_groups'),
            'komponentenrolle' => self::alsPaare(SpeisenKlassenService::ROLLEN),
            'niveau' => self::alsPaare(FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES['level'] ?? []),
            'occasion' => self::alsPaare(FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES['occasion'] ?? []),
            'sektor' => self::alsPaare(self::SEKTOREN),
            'ausgabeform' => self::alsPaare(self::AUSGABEFORMEN),
            'saison' => self::monate(),
            // `portionskontext` und alles Künftige: bewusst frei, bis es einen echten
            // Anlass gibt. Ein vorsorgliches Vokabular ist geraten, kein Vokabular.
            default => null,
        };

        return $werte === [] ? null : $werte;      // leere Quelle schränkt nicht ein
    }

    /** Prüft einen Wert gegen die Achse. Unbekannte/freie Achse ⇒ immer gültig. */
    public static function gueltig(string $achse, string $wert): bool
    {
        $werte = self::werte($achse);

        // `isset` ist hier korrekt, obwohl die Schlüssel gemischt getypt sind (s. u.):
        // PHP wandelt den Nachschlage-Schlüssel genauso um wie den gespeicherten.
        return $werte === null || isset($werte[mb_strtolower(trim($wert))]);
    }

    /**
     * Die erlaubten Werte als STRING-Liste — für Fehlermeldungen und Oberflächen.
     *
     * ⚠ Warum das nötig ist: PHP wandelt numerische Array-Schlüssel still in `int`. Aus
     * `'12' => 'Dezember'` wird `12 => 'Dezember'`, und `array_keys()` liefert dann eine
     * Mischung aus Strings ('01'..'09') und Integern (10, 11, 12). Beim Vergleich mit
     * `'12'` schlägt das strikt fehl. Dieselbe Falle hat am selben Tag schon den
     * §-Verweis-Wächter erwischt — sie trifft nur ganzzahlige Werte und überlebt darum
     * Stichproben. Betrifft hier Monate und Warengruppen-Codes.
     *
     * @return list<string>|null
     */
    public static function werteListe(string $achse): ?array
    {
        $werte = self::werte($achse);

        return $werte === null ? null : array_map('strval', array_keys($werte));
    }

    /**
     * Monate als `01`..`12`.
     *
     * Nicht die vier Jahreszeiten: der Saisonkalender ist nach MONATSPAAREN geschnitten
     * (Jan/Feb, Maerz/Apr, …). Vier Jahreszeiten wären gröber als die Daten und wieder
     * meine Erfindung. Monate sind zudem aus dem Datum ableitbar.
     *
     * @return array<string, string>
     */
    private static function monate(): array
    {
        $namen = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $out = [];
        foreach ($namen as $i => $name) {
            $out[str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)] = $name;
        }

        return $out;
    }

    /**
     * Codes aus einer Stammdaten-Tabelle. Inaktive Zeilen fliegen raus, wo die Tabelle
     * das kennt — ein stillgelegter Gang soll nicht mehr taggbar sein.
     *
     * @return array<string, string>
     */
    private static function ausTabelle(string $tabelle): array
    {
        if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'code')) {
            return [];
        }
        $q = DB::table($tabelle);
        if (Schema::hasColumn($tabelle, 'is_inactive')) {
            $q->where('is_inactive', 0);
        }
        if (Schema::hasColumn($tabelle, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // Die Stammdaten-Tabellen sind nicht einheitlich benannt: `dish_main_groups` fuehrt
        // `label`, `lookup_commodity_groups` fuehrt `name`. Ohne beide Faelle liefe die
        // Bezeichnung still auf den Code zurueck — die Auswahl waere dann unlesbar.
        $spalte = match (true) {
            Schema::hasColumn($tabelle, 'label') => 'label',
            Schema::hasColumn($tabelle, 'name') => 'name',
            default => 'code',
        };

        $out = [];
        foreach ($q->orderBy('code')->get(['code', $spalte.' as bezeichnung']) as $z) {
            $code = mb_strtolower(trim((string) $z->code));
            if ($code !== '') {
                $out[$code] = (string) ($z->bezeichnung ?: $z->code);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $werte
     * @return array<string, string>
     */
    private static function alsPaare(array $werte): array
    {
        $out = [];
        foreach ($werte as $w) {
            $w = mb_strtolower(trim((string) $w));
            if ($w !== '') {
                $out[$w] = $w;
            }
        }

        return $out;
    }
}
