<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/**
 * Spec 52 · Grundsatz A / H1 — die fünf Wissensarten.
 *
 * Die **Kategorie** sagt, worum es geht. Die **Art** sagt, wie das Wissen benutzt werden darf.
 * Das ist der Unterschied, an dem die Steuerung bisher gescheitert ist: `workflow` enthält
 * Handwerkswissen für den Prompt UND Agenten-Anleitungen, die dort nichts verloren haben.
 *
 * Bewusst eine **Code-Konstante**, kein pflegbares Vokabular: der Code entscheidet anhand
 * dieser Werte. Wäre die Liste zur Laufzeit änderbar, könnte er sich nicht darauf verlassen,
 * dass `ablauf` „niemals in einen Prompt" bedeutet.
 */
final class Wissensart
{
    /**
     * Verbindliche Regeln — Naming-§§, Pflichtfelder, erlaubte Vokabulare.
     * Gehören gezielt in den Prompt UND, wo maschinell prüfbar, zusätzlich in den Code.
     */
    public const REGEL = 'regel';

    /**
     * Nachschlagewerk — Mengen-Standards, Garverlust-Faktoren, Stück-Gewichte.
     *
     * ★ **Wird aufgelöst, nicht gesucht.** Ein Mengen-Standard, der für einen Gang verbindlich
     * gilt, darf nicht davon abhängen, ob die Suche das passende Dossier unter die ersten drei
     * Treffer bekommt — er wird über Gang × Komponentenrolle × Portionskontext bestimmt. Das
     * Muster existiert bereits als `achsenBlock()` + `ai.knowledge_axis_map`; `mengen_defaults`
     * benutzt es nur noch nicht, sondern steht als Prosa im Kanon beider Generatoren.
     */
    public const DATENWERK = 'datenwerk';

    /**
     * Fachwissen — Bindeverhalten, Garverfahren, Geschmacksbalance, Komponenten-Handwerk.
     * Der einzige echte Suchfall: nach Aufgabe und Zutaten gezielt finden.
     */
    public const FACHWISSEN = 'fachwissen';

    /** Referenz & Inspiration — Vergleichsrezepte, Küchenstile, Plating. Optional, gekennzeichnet. */
    public const REFERENZ = 'referenz';

    /**
     * Ablauf-Anleitung für einen AGENTEN — „Regel 1: alles ist Entwurf", „Schritt 1: Rahmen
     * laden", `primaer=lieferantenartikel_waehlen`.
     *
     * ★ **Gehört in KEINEN Prompt.** Der Generator ruft keine Werkzeuge, er produziert JSON —
     * eine Werkzeug-Reihenfolge ist dort reines Rauschen. Diese Dossiers erreichen Agenten über
     * `ablauf.GET`, und das ist der richtige Weg.
     */
    public const ABLAUF = 'ablauf';

    /** @var list<string> */
    public const ALLE = [self::REGEL, self::DATENWERK, self::FACHWISSEN, self::REFERENZ, self::ABLAUF];

    /**
     * Arten, die niemals in einen KI-Prompt gehören.
     *
     * @var list<string>
     */
    public const NIE_IM_PROMPT = [self::ABLAUF];

    /** @var array<string, string> */
    public const LABELS = [
        self::REGEL => 'Regel (verbindlich)',
        self::DATENWERK => 'Datenwerk (auflösen, nicht suchen)',
        self::FACHWISSEN => 'Fachwissen (Suchfall)',
        self::REFERENZ => 'Referenz & Inspiration',
        self::ABLAUF => 'Ablauf-Anleitung (nur für Agenten)',
    ];

    public static function gueltig(?string $art): bool
    {
        return $art === null || in_array($art, self::ALLE, true);
    }

    /** Darf ein Dossier dieser Art in einen Prompt? `null` (noch nicht eingeordnet) ja — sonst bricht der Bestand. */
    public static function darfInPrompt(?string $art): bool
    {
        return $art === null || ! in_array($art, self::NIE_IM_PROMPT, true);
    }
}
