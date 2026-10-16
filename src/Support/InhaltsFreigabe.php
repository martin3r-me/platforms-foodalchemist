<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Spec 77d · Lese-Einschränkung für Inhalte. Ein Unter-Team mit Haken „übernimmt alles vom Oberteam" = aus
 * sieht von seinen Vorfahren nur noch, was in seiner gespeicherten Hülle (`foodalchemist_freigabe_objekte`)
 * steht; eigene Inhalte, globaler Bestand und Teams unterhalb der eingeschränkten Stufe bleiben sichtbar.
 *
 * Gilt NUR für Inhalts-Typen (Rezepte, Konzepte, Formate, Pakete, Foodbooks, Speisepläne, Speisekarten, Angebote).
 * Grundprodukte, Lieferantenartikel und Vokabular bleiben Stammdaten und werden weiter voll geerbt — sonst
 * könnte ein Standort keine eigenen Rezepturen anlegen.
 *
 * Eingehängt an den zwei zentralen Stellen: `BelongsToTeamHierarchy::scopeVisibleToTeam` und
 * `TeamScope::applyVisible`. Damit greift die Einschränkung auch in `TeamScope::referenz()` (nicht
 * freigegebene Inhalte lassen sich nicht referenzieren) — gewollt.
 */
final class InhaltsFreigabe
{
    /** @var array<class-string, string> Model → Typ */
    public const MODELLE = [
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class => 'recipe',
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept::class => 'concept',
        \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class => 'format',
        \Platform\FoodAlchemist\Models\FoodAlchemistPaket::class => 'paket',
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class => 'foodbook',
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::class => 'speiseplan',
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::class => 'speisekarte',
        // Spec 84 E8 (Dominique 16.10.): Angebote des Oberteams sieht ein eingeschränktes Unter-Team NICHT. Angebote sind
        // (noch) kein freigebbarer Ausgabe-Typ — sichtbar bleiben sie nur bei „übernimmt alles“ und für das eigene Team.
        \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::class => 'angebot',
    ];

    /** @var array<string, string> Tabelle → Typ (für rohe Queries) */
    public const TABELLEN = [
        'foodalchemist_recipes' => 'recipe',
        'foodalchemist_concepts' => 'concept',
        'foodalchemist_formats' => 'format',
        'foodalchemist_packages' => 'paket',
        'foodalchemist_foodbooks' => 'foodbook',
        'foodalchemist_menu_plans' => 'speiseplan',
        'foodalchemist_menu_cards' => 'speisekarte',
        'foodalchemist_offers' => 'angebot',
    ];

    /** @var array<int, array{frei: list<int>, eingeschraenkt: list<int>, huelle: ?int}> */
    private static array $cache = [];

    private static ?bool $tabelleDa = null;

    /**
     * Für ein betrachtendes Team: welche Ketten-Teams voll sichtbar sind, welche nur über die Hülle, und
     * wessen Hülle gilt (das erste Team der Kette, das nicht alles übernimmt).
     *
     * @param  list<int>  $kette  eigenes Team zuerst … Root
     * @return array{frei: list<int>, eingeschraenkt: list<int>, huelle: ?int}
     */
    public static function fuer(Team $team, array $kette): array
    {
        $id = (int) $team->id;
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        TeamAncestryRegistry::register(self::class);
        $ergebnis = ['frei' => $kette, 'eingeschraenkt' => [], 'huelle' => null];
        if (count($kette) > 1 && self::tabelleDa()) {
            $aus = DB::table('foodalchemist_team_inhalte')->whereIn('team_id', array_slice($kette, 0, -1))
                ->where('erbt_alles', false)->pluck('team_id')->map(fn ($v) => (int) $v)->all();
            foreach ($kette as $i => $tid) {
                if ($i < count($kette) - 1 && in_array($tid, $aus, true)) {
                    $ergebnis = ['frei' => array_slice($kette, 0, $i + 1), 'eingeschraenkt' => array_slice($kette, $i + 1), 'huelle' => $tid];
                    break;
                }
            }
        }

        return self::$cache[$id] = $ergebnis;
    }

    /**
     * Sichtbarkeits-Bedingung in eine (bereits geklammerte) where-Gruppe schreiben.
     *
     * `$basisTabelle`: die echte Tabelle hinter `$idSpalte` (auch bei Alias). Nur damit lässt sich der Abo-Zweig als Menge bauen.
     * `$mitFassungen`: eigene Kette einschließlich Fassungen (nur der automatische Sichtfilter nach `mitFassungen()`).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    public static function anwenden($q, string $teamSpalte, string $idSpalte, string $typ, Team $team, array $kette,
        ?string $basisTabelle = null, bool $mitFassungen = false): void
    {
        $f = self::fuer($team, $kette);
        // Spec 82 §12 B: Fassungs-Zeilen (veröffentlichte Master-Stände) gehören in keine normale Sicht — auch nicht in
        // die Bibliothek des Masters selbst. Sichtbar werden sie ausschließlich über den Abo-Zweig.
        $mitFassungsRegel = ! $mitFassungen && self::hatFassungen($typ, $basisTabelle ?? self::standardTabelle($typ, $idSpalte));

        // Spec 84 B3 (Messung §5a E-3): Abo-Zweig als Menge — `id IN (SELECT id FROM (normale Sicht UNION ALL Abo-IDs) m)`.
        // Die abgeleitete Tabelle zwingt MySQL zum Materialisieren; beide Hälften laufen über Index. Das OR darunter las
        // die ganze Tabelle (35.090 Rezepte: 35 ms → 1,6 ms, gleiche Zeilen). Nur mit eingeschaltetem B3.
        if ($basisTabelle !== null && TeamSicht::aboAlsMenge() && ($abo = AboSicht::objektIds($typ, $kette, $f)) !== null) {
            $innen = DB::table($basisTabelle . ' as ts_innen')->select('ts_innen.id')
                ->where(fn ($w) => self::normaleSicht($w, 'ts_innen.team_id', 'ts_innen.id', $typ, $f));
            if ($mitFassungsRegel) {
                $innen->where('ts_innen.ist_fassung', false);
            }
            $q->whereIn($idSpalte, fn ($s) => $s->select('ts_menge.id')->fromSub($innen->unionAll($abo), 'ts_menge'));

            return;
        }

        if ($mitFassungsRegel) {
            $q->where(fn ($w) => self::normaleSicht($w, $teamSpalte, $idSpalte, $typ, $f))
                ->where(self::praefix($idSpalte) . 'ist_fassung', false);
        } else {
            self::normaleSicht($q, $teamSpalte, $idSpalte, $typ, $f);
        }
        // Spec 82 §12 A1: Abo-Zweig (Master → Mandant), eigene Regel, siehe AboSicht
        AboSicht::anhaengen($q, $idSpalte, $typ, $kette, $f);
    }

    /**
     * Globaler Seed, freie Kette, eingeschränkte Kette nur über die Freigabe-Hülle (77d).
     *
     * @param  array{frei: list<int>, eingeschraenkt: list<int>, huelle: ?int}  $f
     */
    private static function normaleSicht($w, string $teamSpalte, string $idSpalte, string $typ, array $f): void
    {
        $w->whereNull($teamSpalte);
        if ($f['frei'] !== []) {
            $w->orWhereIn($teamSpalte, $f['frei']);
        }
        if ($f['eingeschraenkt'] !== []) {
            $w->orWhere(fn ($e) => $e->whereIn($teamSpalte, $f['eingeschraenkt'])
                ->whereIn($idSpalte, fn ($s) => $s->select('objekt_id')->from('foodalchemist_freigabe_objekte')
                    ->where('team_id', $f['huelle'])->where('typ', $typ)));
        }
    }

    /** Schema-Merker je Tabelle und Prozess (nicht im Team-Flush), siehe flushTeamAncestryCache. */
    private static array $fassungDa = [];

    private const FASSUNGS_TABELLEN = ['recipe' => 'foodalchemist_recipes', 'concept' => 'foodalchemist_concepts', 'paket' => 'foodalchemist_packages', 'format' => 'foodalchemist_formats'];

    /** Tabelle aus der ID-Spalte (ohne Präfix: Standardtabelle des Typs). Foodbook/Speiseplan/Speisekarte haben keine Fassung. */
    private static function standardTabelle(string $typ, string $idSpalte): ?string
    {
        $standard = self::FASSUNGS_TABELLEN[$typ] ?? null;
        if ($standard === null) {
            return null;
        }

        return str_contains($idSpalte, '.') ? strstr($idSpalte, '.', true) : $standard;
    }

    /** Hat die Tabelle eine Spalte `ist_fassung` (migriert) und der Typ überhaupt Fassungen? */
    private static function hatFassungen(string $typ, ?string $tabelle): bool
    {
        if ($tabelle === null || ! isset(self::FASSUNGS_TABELLEN[$typ])) {
            return false;
        }

        return self::$fassungDa[$tabelle] ??= Schema::hasColumn($tabelle, 'ist_fassung');
    }

    /** Präfix der ID-Spalte (`alias.` bzw. `tabelle.`, sonst leer) für die Fassungs-Spalte. */
    private static function praefix(string $idSpalte): string
    {
        return str_contains($idSpalte, '.') ? strstr($idSpalte, '.', true) . '.' : '';
    }

    public static function typFuerModel(string $klasse): ?string
    {
        return self::MODELLE[$klasse] ?? null;
    }

    public static function typFuerTabelle(string $tabelle): ?string
    {
        return self::TABELLEN[$tabelle] ?? null;
    }

    /** Cache leeren (Haken umgeschaltet, Tests). Teil von TeamAncestryRegistry::flushAll(). */
    public static function flushTeamAncestryCache(): void
    {
        self::$cache = [];
        self::$tabelleDa = null;
        // $fassungDa bleibt: ein Schema-Stand ändert sich nicht mit dem Team-Cache (Spec77dLeistungTest: keine Abfrage je Flush)
    }

    private static function tabelleDa(): bool
    {
        return self::$tabelleDa ??= Schema::hasTable('foodalchemist_team_inhalte');
    }
}
