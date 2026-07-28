<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * V-032 / V-054 · Spec 22 H3a — der Zustand eines Laufs in `foodalchemist_bulk_runs`.
 *
 * Der Migrations-Kommentar von 2026-06-12 kennt nur `running | done`; `failed` schreibt
 * seit 13·S3b der {@see \Platform\FoodAlchemist\Jobs\ImportArticlesJob}-Fehl-Pfad — der
 * dritte Wert existiert also im Bestand, nur nicht im dokumentierten Vokabular. Genau
 * diese Lücke beschreibt V-020: die erlaubte Werteliste steht an der einen Stelle, die
 * kein Code liest.
 *
 * ⚠️ `Failed` ist heute erst an EINEM der sieben Job-Pfade verdrahtet. Die übrigen fünf
 * Jobs ohne `failed()`-Hook und das Alters-Kriterium für verwaiste `running`-Läufe sind
 * ausdrücklich Sache von 22·H3b — dieses Enum stellt das Vokabular bereit, es behauptet
 * nicht, dass jeder tote Lauf ihn schon erreicht.
 */
enum BulkRunStatus: string
{
    /** Angelegt und (vermeintlich) in Arbeit — ohne H3b auch der Zustand eines toten Laufs. */
    case Running = 'running';

    /** Regulär zu Ende gelaufen; `failed` zählt dabei die gescheiterten Einzelposten, nicht den Lauf. */
    case Done = 'done';

    /** Der Lauf selbst ist gescheitert (Ausnahme/Timeout) — kein Teilergebnis zu erwarten. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'läuft',
            self::Done => 'abgeschlossen',
            self::Failed => 'abgebrochen',
        };
    }

    /** Wartet ein Aufrufer noch auf ein Ergebnis? (Polling-Prädikat) */
    public function istOffen(): bool
    {
        return $this === self::Running;
    }
}
