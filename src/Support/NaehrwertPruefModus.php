<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPlattformEinstellung;

/**
 * Wirksamer Modus der Nährwert-Prüfung (aus | trocken | scharf). Reihenfolge: Notaus (env, erzwingt „aus“) > Plattform-Einstellung
 * (MCP `naehrwert_pruefung.PUT`, nur Master-Team — Umschalten ohne Forge-Eingriff, Kurator 30.10.) > env-Standard.
 *
 * Die Einstellung wird höchstens alle 60 s gelesen: der Wert fragt jede GP-Aggregation ab, und ein Queue-Worker lebt Stunden —
 * ohne Ablauf würde er einen umgeschalteten Modus nie sehen.
 */
final class NaehrwertPruefModus
{
    public const MODI = ['aus', 'trocken', 'scharf'];

    private const CACHE_SEKUNDEN = 60;

    /** @var array{zeit: int, wert: ?string}|null */
    private static ?array $cache = null;

    public static function aktuell(): string
    {
        if ((bool) config('foodalchemist.naehrwert_pruefung.notaus')) {
            return 'aus';
        }

        return self::ausEinstellung() ?? self::ausEnv();
    }

    public static function istScharf(): bool
    {
        return self::aktuell() === 'scharf';
    }

    /** notaus | einstellung | env */
    public static function quelle(): string
    {
        if ((bool) config('foodalchemist.naehrwert_pruefung.notaus')) {
            return 'notaus';
        }

        return self::ausEinstellung() !== null ? 'einstellung' : 'env';
    }

    public static function ausEnv(): string
    {
        $wert = (string) config('foodalchemist.naehrwert_pruefung.modus');

        return in_array($wert, self::MODI, true) ? $wert : 'aus';
    }

    public static function ausEinstellung(): ?string
    {
        if (self::$cache === null || time() - self::$cache['zeit'] >= self::CACHE_SEKUNDEN) {
            try {
                $wert = DB::table((new FoodAlchemistPlattformEinstellung)->getTable())
                    ->where('schluessel', FoodAlchemistPlattformEinstellung::NAEHRWERT_PRUEFUNG_MODUS)->value('wert');
            } catch (\Throwable) {
                return null;   // Tabelle fehlt: env gilt, nicht cachen
            }
            self::$cache = ['zeit' => time(), 'wert' => in_array($wert, self::MODI, true) ? $wert : null];
        }

        return self::$cache['wert'];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
