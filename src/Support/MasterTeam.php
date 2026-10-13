<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPlattformEinstellung;

/**
 * Spec 82 §13 B4 — EINE Stelle, die das Master-Team auflöst (statt `config('foodalchemist.master_team_id')` an mehreren Orten).
 *
 * Reihenfolge: plattformweite FA-Einstellung (Tabelle, nur Plattform-Admin) vor ENV `FOODALCHEMIST_MASTER_TEAM_ID`.
 * Gibt es in der Tabelle keinen Wert, gilt die ENV (Fallback, Rollback = Zeile löschen). Nur der Tabellenwert wird je
 * Prozess/Request gecacht ({@see flush}); die ENV wird jedes Mal frisch gelesen (Tests und Konfigurationswechsel).
 * Fehlt die Tabelle (Migration noch nicht gelaufen), gilt die ENV — die Auflösung darf nie am Schema scheitern.
 */
final class MasterTeam
{
    /** @var array{gelesen: bool, wert: ?int} */
    private static array $cache = ['gelesen' => false, 'wert' => null];

    /** Master-Team-ID oder null (kein Master konfiguriert). */
    public static function id(): ?int
    {
        return self::ausEinstellung() ?? self::ausEnv();
    }

    public static function istMaster(?Team $team): bool
    {
        $id = self::id();

        return $team !== null && $id !== null && (int) $team->id === $id;
    }

    /** Woher kommt der Wert? einstellung | env | keiner */
    public static function quelle(): string
    {
        return self::ausEinstellung() !== null ? 'einstellung' : (self::ausEnv() !== null ? 'env' : 'keiner');
    }

    public static function ausEnv(): ?int
    {
        $env = config('foodalchemist.master_team_id');

        return is_numeric($env) ? (int) $env : null;
    }

    public static function ausEinstellung(): ?int
    {
        if (! self::$cache['gelesen']) {
            try {
                $wert = DB::table((new FoodAlchemistPlattformEinstellung)->getTable())
                    ->where('schluessel', FoodAlchemistPlattformEinstellung::MASTER_TEAM)->value('wert');
                self::$cache = ['gelesen' => true, 'wert' => is_numeric($wert) ? (int) $wert : null];
            } catch (\Throwable) {
                return null;   // Tabelle fehlt: nicht cachen, nächster Aufruf versucht es erneut
            }
        }

        return self::$cache['wert'];
    }

    public static function flush(): void
    {
        self::$cache = ['gelesen' => false, 'wert' => null];
    }
}
