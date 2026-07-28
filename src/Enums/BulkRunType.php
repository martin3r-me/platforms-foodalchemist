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
 * Alle sechs Fälle haben heute einen echten Schreiber; es ist keine Wunschliste:
 * `enrich`/`enrich_vk`/`enrich_gp` aus {@see \Platform\FoodAlchemist\Services\BulkEnrichService},
 * `ingest` aus {@see \Platform\FoodAlchemist\Services\FileArticleImportService},
 * `review` aus {@see \Platform\FoodAlchemist\Services\RecipeFindingsBatchService},
 * `detektor` aus {@see \Platform\FoodAlchemist\Services\QualityRunService}.
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

    /**
     * Qualitäts-Lauf („Ampel neu messen") — {@see \Platform\FoodAlchemist\Jobs\QualityRunJob}.
     *
     * Warum überhaupt ein Lauf-Typ für etwas Deterministisches? Weil der Detektor die
     * teuerste **lesende** Operation des Moduls ist (11 Detektoren + Voll-Messung der
     * Kaskade + Snapshot + Drift) und bis hier synchron im Livewire-Request hing. Er
     * braucht dieselbe Quittung wie die KI-Läufe: „läuft noch / durch / abgebrochen"
     * über `runs.GET`, sonst ist ein Klick, der 90 Sekunden dauert, von einem Klick,
     * der ins Timeout gelaufen ist, nicht zu unterscheiden.
     */
    case Detektor = 'detektor';

    public function label(): string
    {
        return match ($this) {
            self::Enrich => 'Anreicherung (Basisrezepte)',
            self::EnrichVk => 'Anreicherung (Gerichte)',
            self::EnrichGp => 'Anreicherung (Grundprodukte)',
            self::Ingest => 'Artikel-Import',
            self::Review => 'KI-Review',
            self::Detektor => 'Qualitäts-Lauf (Ampel)',
        };
    }

    /**
     * Kostet der Lauf Provider-Geld? Die drei Anreicherungs-Arten und der Review-Pass
     * rufen das Modell, der Datei-Import und der Qualitäts-Lauf nicht — für „wer hat
     * wann was ausgelöst" (V-032, Activity-Log) ist genau das die Trennlinie.
     *
     * Bewusst als **Positiv-Liste** statt wie bisher als `!== Ingest`: die Negativ-Form
     * hat jeden neuen Lauf-Typ stillschweigend zum Kostenträger erklärt. Genau das wäre
     * `Detektor` passiert — ein gratis laufender, künftig täglich eingeplanter Job hätte
     * die Kosten-Auswertung dauerhaft verfälscht, ohne eine Zeile Fehler zu erzeugen.
     * Wer hier einen Fall ergänzt, muss sich jetzt entscheiden.
     */
    public function istKiLauf(): bool
    {
        return match ($this) {
            self::Enrich, self::EnrichVk, self::EnrichGp, self::Review => true,
            self::Ingest, self::Detektor => false,
        };
    }
}
