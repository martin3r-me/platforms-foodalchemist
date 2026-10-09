<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Lesezugriff auf die aktiven Regeln (Spec 81 Teil D). Die Tabelle ist klein; sie wird einmal geladen und im
 * Container gehalten — 10 Minuten, damit langlebige Queue-Worker eine Änderung ohne Neustart sehen. Speichern
 * einer Regel leert das Memo sofort.
 *
 * v1: nur globale Regeln (team_id NULL). Team-eigene Regeln sind im Schema vorbereitet, nicht im Lesepfad.
 *
 * Fehlt eine Regel, die der Code erwartet, wird das geloggt (fail-closed = laut, nicht still): die Seeds
 * liegen in einer Migration, eine fehlende Regel ist ein Betriebsfehler.
 */
final class RegelBuch
{
    private const MEMO = 'foodalchemist.regelbuch';

    /** Aktive Regel zum Schlüssel oder null. */
    public static function per(string $schluessel): ?FoodAlchemistRule
    {
        $r = self::alle()[$schluessel] ?? null;
        if ($r === null) {
            self::meldeFehlend($schluessel);
        }

        return $r;
    }

    /** Wie `per()`, aber ohne Log — für optionale Regeln. */
    public static function falls(string $schluessel): ?FoodAlchemistRule
    {
        return self::alle()[$schluessel] ?? null;
    }

    /** @return list<FoodAlchemistRule> aktive Regeln für ein Ziel (z. B. `gp.name`) */
    public static function fuerZiel(string $ziel): array
    {
        return array_values(array_filter(self::alle(), static fn (FoodAlchemistRule $r) => $r->ziel === $ziel));
    }

    /** @return array<string, FoodAlchemistRule> */
    public static function alle(): array
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : null;
        if (is_array($memo) && $memo['bis'] > time()) {
            return $memo['regeln'];
        }
        try {
            $regeln = FoodAlchemistRule::query()->whereNull('team_id')->where('aktiv', true)->get()
                ->keyBy('schluessel')->all();
        } catch (\Throwable $e) {
            Log::error('Regelbuch nicht lesbar: ' . $e->getMessage());
            $regeln = [];
        }
        app()->instance(self::MEMO, ['regeln' => $regeln, 'bis' => time() + 600, 'gemeldet' => [], 'listen' => []]);

        return $regeln;
    }

    /**
     * Wortliste einer Regel (`params.tokens`), in Vergleichsform (klein, Umlaute umschrieben) und im Memo
     * gehalten — für heiße Pfade wie das Matching. Fehlt die Regel: leere Liste + Log.
     *
     * @return list<string>
     */
    public static function liste(string $schluessel, string $feld = 'tokens'): array
    {
        self::alle();
        $memo = app(self::MEMO);
        $k = $schluessel . '|' . $feld;
        if (! isset($memo['listen'][$k])) {
            $r = self::per($schluessel);
            $memo = app(self::MEMO);
            $memo['listen'][$k] = $r === null ? [] : array_values(array_unique(array_filter(array_map(
                static fn ($w) => RegelText::norm((string) $w), (array) ($r->params[$feld] ?? [])))));
            app()->instance(self::MEMO, $memo);
        }

        return $memo['listen'][$k];
    }

    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    private static function meldeFehlend(string $schluessel): void
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : null;
        if (! is_array($memo) || isset($memo['gemeldet'][$schluessel])) {
            return;
        }
        $memo['gemeldet'][$schluessel] = true;
        app()->instance(self::MEMO, $memo);
        Log::warning("Regel «{$schluessel}» fehlt oder ist inaktiv — die zugehörige Prüfung läuft nicht.");
    }
}
