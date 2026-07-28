<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * V-032 · Spec 22 H3c-2 — der Zustand EINES Vorschlags aus einem Anreicherungs-Lauf
 * ({@see \Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal} und sein GP-Zwilling).
 *
 * Bis hier stand die Werteliste ausschließlich in den Migrations-Kommentaren von
 * 2026-06-12 bzw. 2026-07-01 — an der einen Stelle, die kein Code liest (V-020) — und
 * jeder der achtzehn Zugriffe verglich gegen einen Magic String. Dasselbe Vokabular gilt
 * für **beide** Vorschlags-Tabellen; hätte jede ihr eigenes bekommen, wären es zwei
 * Wahrheiten für einen Begriff gewesen (genau die Drift-Klasse aus V-072).
 *
 * ⚠️ Das Enum beschreibt die **Werte**, nicht die Übergänge: wann ein Vorschlag `leer`
 * statt `offen` ist, entscheidet weiterhin der Schreiber — und zwar im Rezept- und im
 * GP-Pfad bis heute **verschieden** (V-072: `null || ''` gegen `null || '' || []`). Diese
 * Asymmetrie ist bekannt, hochgegeben und in `BulkProposalSpeicherGoldenTest` eingefroren;
 * sie hier stillschweigend zu vereinheitlichen wäre ein unbeaufsichtigter
 * Verhaltenswechsel an einer Auswahl-Regel gewesen.
 */
enum BulkProposalStatus: string
{
    /** Vorschlag liegt vor und wartet auf die menschliche Entscheidung (GL-07: nie Auto-Persistenz). */
    case Offen = 'offen';

    /** Übernommen — der Wert steht im Fach-Feld, der Call-Log ist als `accepted` gestempelt. */
    case Uebernommen = 'uebernommen';

    /** Abgelehnt — Endzustand, der Call-Log ist als `rejected` gestempelt. */
    case Verworfen = 'verworfen';

    /**
     * Kein verwertbarer Vorschlag: das Modell hat nichts geliefert **oder** der Schritt ist
     * mit einer Ausnahme gescheitert (dann trägt die Zeile zusätzlich `error`). Beide Lagen
     * teilen sich bewusst einen Wert — für die Review-Liste sind sie dasselbe: nichts zu
     * entscheiden.
     */
    case Leer = 'leer';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'offen',
            self::Uebernommen => 'übernommen',
            self::Verworfen => 'verworfen',
            self::Leer => 'ohne Vorschlag',
        };
    }

    /** Wartet der Vorschlag noch auf eine Entscheidung? (Review-Prädikat) */
    public function istOffen(): bool
    {
        return $this === self::Offen;
    }
}
