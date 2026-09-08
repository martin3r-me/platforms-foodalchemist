<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/**
 * Spec 52 · H6 — die Arten einer Dossier-Verbindung.
 *
 * Code-Konstante, kein pflegbares Vokabular: `ersetzt` steuert Verhalten (die
 * Nachfolger-Empfehlung im Integritäts-Bericht), und darauf muss sich der Code verlassen
 * können. Dieselbe Begründung wie bei {@see Wissensart}.
 */
final class Wissensverbindung
{
    /**
     * „von" löst „nach" ab — die Kante, die den Korpus-Umbau nachvollziehbar hält.
     *
     * ★ Sie ist nicht nur Dokumentation: zeigt eine Kanon-Zeile auf ein deaktiviertes Dossier
     * und existiert ein `ersetzt`-Nachfolger, nennt der Integritäts-Bericht ihn. Aus „irgendwas
     * ist kaputt" wird damit „häng die Zeile auf DIESES Dossier um".
     */
    public const ERSETZT = 'ersetzt';

    /** „von" führt „nach" im Detail aus (§1 → §1.2). Reine Navigation. */
    public const VERFEINERT = 'verfeinert';

    /** Thematisch verwandt, ohne Rangfolge. */
    public const SIEHE_AUCH = 'siehe_auch';

    /**
     * „von" widerspricht „nach" — beide gelten, sagen aber Unterschiedliches.
     *
     * Bewusst festhaltbar statt sofort aufzulösen: ein Widerspruch zwischen zwei Dossiers ist
     * eine fachliche Entscheidung, keine technische. Sichtbar zu machen ist der erste Schritt.
     */
    public const WIDERSPRICHT = 'widerspricht';

    /** @var list<string> */
    public const ALLE = [self::ERSETZT, self::VERFEINERT, self::SIEHE_AUCH, self::WIDERSPRICHT];

    /** @var array<string, string> */
    public const LABELS = [
        self::ERSETZT => 'ersetzt (Nachfolger)',
        self::VERFEINERT => 'verfeinert (Detail von)',
        self::SIEHE_AUCH => 'siehe auch',
        self::WIDERSPRICHT => 'widerspricht',
    ];

    public static function gueltig(string $art): bool
    {
        return in_array($art, self::ALLE, true);
    }
}
