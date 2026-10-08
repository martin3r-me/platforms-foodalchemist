<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;

/**
 * Spec 77d · Lese-Einschränkung für Inhalte. Ein Unter-Team mit Haken „übernimmt alles vom Oberteam" = aus
 * sieht von seinen Vorfahren nur noch, was in seiner gespeicherten Hülle (`foodalchemist_freigabe_objekte`)
 * steht; eigene Inhalte, globaler Bestand und Teams unterhalb der eingeschränkten Stufe bleiben sichtbar.
 *
 * Gilt NUR für Inhalts-Typen (Rezepte, Konzepte, Formate, Pakete, Foodbooks, Speisepläne, Speisekarten).
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
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    public static function anwenden($q, string $teamSpalte, string $idSpalte, string $typ, Team $team, array $kette): void
    {
        $f = self::fuer($team, $kette);
        $q->whereNull($teamSpalte);
        if ($f['frei'] !== []) {
            $q->orWhereIn($teamSpalte, $f['frei']);
        }
        if ($f['eingeschraenkt'] !== []) {
            $q->orWhere(fn ($w) => $w->whereIn($teamSpalte, $f['eingeschraenkt'])
                ->whereIn($idSpalte, fn ($s) => $s->select('objekt_id')->from('foodalchemist_freigabe_objekte')
                    ->where('team_id', $f['huelle'])->where('typ', $typ)));
        }
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
    }

    private static function tabelleDa(): bool
    {
        return self::$tabelleDa ??= Schema::hasTable('foodalchemist_team_inhalte');
    }
}
