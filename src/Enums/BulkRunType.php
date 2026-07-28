<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * V-047 / V-032 · Spec 22 H3a — die Arten von Läufen in `foodalchemist_bulk_runs`.
 *
 * Die Tabelle ist bewusst geteilt („welche Läufe sind gelaufen?" soll EINE Antwort
 * haben), `type` war aber ein freier `string(32)`: der vierte und fünfte Wert sind
 * allein dadurch entstanden, dass jemand sie hineinschrieb. Ein Tippfehler (`ingests`)
 * hätte eine neue Lauf-Art erzeugt, die in keiner Auswertung auffällt, weil niemand
 * die Menge der zulässigen Werte kennt. Das Vokabular lebt darum hier und nicht im
 * Migrations-Kommentar von 2026-06-12 — der kennt nur zwei der fünf Fälle (V-020).
 *
 * Alle fünf Fälle haben heute einen echten Schreiber; es ist keine Wunschliste:
 * `enrich`/`enrich_vk`/`enrich_gp` aus {@see \Platform\FoodAlchemist\Services\BulkEnrichService},
 * `ingest` aus {@see \Platform\FoodAlchemist\Services\FileArticleImportService},
 * `review` aus {@see \Platform\FoodAlchemist\Console\RecipeFindingsCommand}.
 */
enum BulkRunType: string
{
    /** Anreicherungs-Autopilot auf Basisrezepten (M7-06). */
    case Enrich = 'enrich';

    /** Anreicherungs-Autopilot auf Verkaufsgerichten (Spec 03 L1b, eigene Schrittfolge). */
    case EnrichVk = 'enrich_vk';

    /** Anreicherungs-Autopilot auf Grundprodukten (eigener Vorschlags-Speicher). */
    case EnrichGp = 'enrich_gp';

    /** Datei-Import von Lieferantenartikeln (Spec 13 · Kanal B). */
    case Ingest = 'ingest';

    /** KI-Review-Pass über Rezepte (Spec 21 Tranche B, legt `recipe_findings` ab). */
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Enrich => 'Anreicherung (Basisrezepte)',
            self::EnrichVk => 'Anreicherung (Gerichte)',
            self::EnrichGp => 'Anreicherung (Grundprodukte)',
            self::Ingest => 'Artikel-Import',
            self::Review => 'KI-Review',
        };
    }

    /**
     * Kostet der Lauf Provider-Geld? Die drei Anreicherungs-Arten und der Review-Pass
     * rufen das Modell, der Datei-Import nicht — für „wer hat wann was ausgelöst"
     * (V-032, Activity-Log) ist genau das die Trennlinie.
     */
    public function istKiLauf(): bool
    {
        return $this !== self::Ingest;
    }
}
