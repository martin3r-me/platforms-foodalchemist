<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;
use Platform\FoodAlchemist\Support\FaTeam;

/**
 * Lesezugriff auf die aktiven Regeln (Spec 81 Teil D). Die Tabelle ist klein; sie wird einmal geladen und im
 * Container gehalten — 10 Minuten, damit langlebige Queue-Worker eine Änderung ohne Neustart sehen. Speichern
 * einer Regel leert das Memo sofort.
 *
 * Team-Regeln (Spec 85 D-7, Spec 81 „Mandanten-Abweichungen“): je Schlüssel gilt die nächste aktive Zeile in der Team-Kette —
 * eigenes Team > Oberteam > global. Die Kette endet an der Kundengrenze (nie eine Team-Regel des Masters beim Mandanten). Team ist
 * der Kontext (`FaTeam::aktuell()`: Seite, Tool, Job im Auftragsteam) oder ausdrücklich übergeben; ohne Team nur global.
 * Nur Schlüssel aus {@see UEBERSTEUERBAR} dürfen abweichen — andere Team-Zeilen werden im Lesepfad ignoriert (Verteidigung,
 * der Schreibweg lehnt sie ab). „Zeile explizit“ (von Hand gesetzte Werte an Rezeptzeile/GP) liegt über allem und berührt
 * das RegelBuch nicht.
 *
 * Fehlt eine Regel, die der Code erwartet, wird das geloggt (fail-closed = laut, nicht still): die Seeds
 * liegen in einer Migration, eine fehlende Regel ist ein Betriebsfehler.
 */
final class RegelBuch
{
    private const MEMO = 'foodalchemist.regelbuch';

    /**
     * Schlüssel, die ein Team übersteuern darf (Spec 81: nur Zuordnung/Schwelle der Bereiche Default-GP und Hausstandard, nie
     * Naming/Matching/Ernährung). Sicherheitsgrenze — darum Code, nicht Regel-Daten.
     */
    public const UEBERSTEUERBAR = [
        'basisrezept.5.default_gp',
        'gericht.kleinstmengen',
        'basisrezept.bestand.haus_zuordnung',
    ];

    /** Aktive Regel zum Schlüssel oder null. */
    public static function per(string $schluessel, ?Team $team = null): ?FoodAlchemistRule
    {
        $r = self::alle($team)[$schluessel] ?? null;
        if ($r === null) {
            self::meldeEinmal('fehlt|' . $schluessel, "Regel «{$schluessel}» fehlt oder ist inaktiv — die zugehörige Prüfung läuft nicht.");
        }

        return $r;
    }

    /** Wie `per()`, aber ohne Log — für optionale Regeln. */
    public static function falls(string $schluessel, ?Team $team = null): ?FoodAlchemistRule
    {
        return self::alle($team)[$schluessel] ?? null;
    }

    /** @return list<FoodAlchemistRule> aktive Regeln für ein Ziel (z. B. `gp.name`) */
    public static function fuerZiel(string $ziel, ?Team $team = null): array
    {
        return array_values(array_filter(self::alle($team), static fn (FoodAlchemistRule $r) => $r->ziel === $ziel));
    }

    /** @return array<string, FoodAlchemistRule> je Schlüssel die wirksame Regel im Team (ohne Team: global) */
    public static function alle(?Team $team = null): array
    {
        $team ??= FaTeam::aktuell();
        $k = $team?->id !== null ? (int) $team->id : 0;
        $memo = self::memo();
        if (isset($memo['teams'][$k]) && $memo['teams'][$k]['bis'] > time()) {
            return $memo['teams'][$k]['regeln'];
        }
        try {
            $regeln = self::lade($team);
        } catch (\Throwable $e) {
            Log::error('Regelbuch nicht lesbar: ' . $e->getMessage());
            $regeln = [];
        }
        $memo = self::memo();
        $memo['teams'][$k] = ['regeln' => $regeln, 'bis' => time() + 600, 'listen' => []];
        app()->instance(self::MEMO, $memo);

        return $regeln;
    }

    /**
     * Wortliste einer Regel (`params.tokens`), in Vergleichsform (klein, Umlaute umschrieben) und im Memo
     * gehalten — für heiße Pfade wie das Matching. Fehlt die Regel: leere Liste + Log.
     *
     * @return list<string>
     */
    public static function liste(string $schluessel, string $feld = 'tokens', ?Team $team = null): array
    {
        $team ??= FaTeam::aktuell();
        $k = $team?->id !== null ? (int) $team->id : 0;
        self::alle($team);
        $memo = self::memo();
        $lk = $schluessel . '|' . $feld;
        if (! isset($memo['teams'][$k]['listen'][$lk])) {
            $r = self::per($schluessel, $team);
            $memo = self::memo();
            $memo['teams'][$k]['listen'][$lk] = $r === null ? [] : array_values(array_unique(array_filter(array_map(
                static fn ($w) => RegelText::norm((string) $w), (array) ($r->params[$feld] ?? [])))));
            app()->instance(self::MEMO, $memo);
        }

        return $memo['teams'][$k]['listen'][$lk];
    }

    /** Herkunft der wirksamen Regel: `global` oder `team:<id>` (für Anzeige/MCP). */
    public static function herkunft(FoodAlchemistRule $regel): string
    {
        return $regel->team_id === null ? 'global' : 'team:' . (int) $regel->team_id;
    }

    /** Memo leeren — für alle Teams (Speichern einer globalen oder einer Team-Regel). */
    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Globale aktive Regeln, darüber die Team-Zeilen der gekappten Kette (nächste gewinnt). Eigene Abfrage ohne Team-Sicht:
     * die Kette ist hier ausdrücklich gebaut — gleiches Ergebnis auf jeder Stufe und aus jedem Kontext.
     *
     * @return array<string, FoodAlchemistRule>
     */
    private static function lade(?Team $team): array
    {
        $regeln = FoodAlchemistRule::ohneTeamSicht('regelbuch')->whereNull('team_id')->where('aktiv', true)->get()
            ->keyBy('schluessel')->all();
        if ($team === null) {
            return $regeln;
        }
        $kette = TeamSicht::gekappt(FoodAlchemistRule::teamAncestryIds($team));   // eigenes Team zuerst … Kundengrenze
        $rang = array_flip($kette);
        $teamZeilen = FoodAlchemistRule::ohneTeamSicht('regelbuch')->whereIn('team_id', $kette)->where('aktiv', true)->get()
            ->sortBy(fn (FoodAlchemistRule $r) => $rang[(int) $r->team_id] ?? PHP_INT_MAX);
        $gesetzt = [];
        foreach ($teamZeilen as $r) {
            $s = (string) $r->schluessel;
            if (isset($gesetzt[$s])) {
                continue;   // ein näheres Team hat den Schlüssel schon
            }
            if (! in_array($s, self::UEBERSTEUERBAR, true) || ! isset($regeln[$s])) {
                self::meldeEinmal('team|' . $r->id, "Team-Regel «{$s}» (Team {$r->team_id}) ignoriert — nicht übersteuerbar oder ohne globale Regel.");

                continue;
            }
            $regeln[$s] = $r;
            $gesetzt[$s] = true;
        }

        return $regeln;
    }

    /** @return array{teams: array<int, array{regeln: array, bis: int, listen: array}>, gemeldet: array<string, true>} */
    private static function memo(): array
    {
        $m = app()->bound(self::MEMO) ? app(self::MEMO) : null;

        return is_array($m) && isset($m['teams']) ? $m : ['teams' => [], 'gemeldet' => []];
    }

    private static function meldeEinmal(string $schluessel, string $text): void
    {
        $memo = self::memo();
        if (isset($memo['gemeldet'][$schluessel])) {
            return;
        }
        $memo['gemeldet'][$schluessel] = true;
        app()->instance(self::MEMO, $memo);
        Log::warning($text);
    }
}
