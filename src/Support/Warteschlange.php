<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Welche Warteschlange ein Fan-out-Job nimmt — getrennt nach ARTEFAKT (Dominique 2026-09-03:
 * „einer Kaskade, einer Planung, einer Rezepte, einer Gerichte, einer Anreicherung").
 *
 * DAS PROBLEM: demo fährt die Worker auf EINER Schlange. Läuft ein großer Fan-out, warten alle
 * kleinen Jobs dahinter — Titelvorschlag, Klassifikation, Bildgenerierung, ein einzelner
 * Anreicherungs-Klick. Gemessene Mengen je Klick: Speiseplan bis 90 Zellen (6 Wochen × 3 Linien
 * × 5 Werktage), Speisekarte 40 Positionen, Foodbook je Kapitel bis 30 Gerichte, Angebot und
 * Format je Slot ein Concept mit bis zu 30 Gerichten — dort ungedeckelt.
 *
 * WARUM NICHT NACH JOB-KLASSE (der naheliegende Weg, und er ist falsch): dieselbe Klasse dient
 * beiden Fällen. `GenerateRecipeJob` ist der Einzelklick »erzeug mir ein Basisrezept« UND das
 * Kaskaden-Kind; `GenerateConceptJob` ist der Kapitel-Klick UND der Slot-Fan-out beim Angebot.
 * Eine Klassen-Regel hätte entweder den Einzelklick hinter 90 Zellen gestellt oder den Fan-out
 * weiter alles verdrängen lassen. Deshalb entscheidet der AUFRUFER — der Fan-out weiß, dass er
 * einer ist.
 *
 * WARUM NACH ARTEFAKT und nicht nach »groß/klein«: die Trennung nach Menge verhindert nur, dass
 * andere warten. Die Trennung nach Artefakt macht die Kaskade selbst SCHNELLER — ein
 * 90-Zellen-Speiseplan belegt `gerichte`, seine Sub-Rezepte laufen parallel auf `rezepte` weiter.
 *
 * Belegt sind heute VIER Schlangen; jede hat genau eine Dispatch-Stelle, die nachweislich in
 * einer Schleife liegt:
 *   · `gerichte`   — Speiseplan-Zellen + Speisekarten-Positionen (bis 90 bzw. 40 je Klick)
 *   · `rezepte`    — Kaskaden-Kinder aus planChildren (bis MAX_STEPS = 50)
 *   · `kaskade`    — Slot-Concepts je Frame-Slot (Foodbook/Angebot/Format)
 *   · `anreichern` — Anreicherung je Sub-Rezept
 *
 * »Planung« hat heute KEINEN Massen-Pfad, und das ist Absicht: der Vorschlag-Gate erzeugt je
 * menschlichem Klick genau einen `GenerateDishProposalJob`. Eine Schlange anzulegen, die niemand
 * füllt, wäre eine Attrappe — sie kommt, wenn ein Fan-out sie braucht.
 *
 * DEFAULTS SIND LEER — also unverändertes Verhalten. Das ist keine Bequemlichkeit, sondern
 * Pflicht: Jobs auf eine Schlange schicken, die kein Worker liest, heißt die Generierung steht
 * still, OHNE Fehlermeldung. Reihenfolge beim Scharfstellen: deployen (nichts ändert sich) →
 * Worker in Forge je Schlange → dann die Env-Variablen setzen.
 *
 * NICHT mehrere Schlangen an EINEN Worker (`--queue=gerichte,default`): Laravel leert die Liste
 * in der angegebenen Reihenfolge — der Fan-out würde die kleinen Jobs dann noch härter
 * aushungern als heute. Je Schlange ein eigener Daemon.
 */
final class Warteschlange
{
    /** Erzeugte Gerichte/VK-Positionen — die größte Menge je Klick (bis 90). */
    public static function gerichte(): ?string
    {
        return self::name('gerichte');
    }

    /** Basisrezepte, die als Kaskaden-Kinder entstehen (bis MAX_STEPS = 50). */
    public static function rezepte(): ?string
    {
        return self::name('rezepte');
    }

    /** Concepts je Frame-Slot (Foodbook-Kapitel, Angebot-/Format-Slots). */
    public static function kaskade(): ?string
    {
        return self::name('kaskade');
    }

    /** Anreicherung je Sub-Rezept — viele kleine Läufe hinter einem Klick. */
    public static function anreichern(): ?string
    {
        return self::name('anreichern');
    }

    /**
     * `null` durchzureichen ist gefahrlos: `PendingDispatch::onQueue(null)` lässt die
     * Standard-Schlange stehen. Genau darum ist der Default leer.
     */
    private static function name(string $schluessel): ?string
    {
        $name = trim((string) config('foodalchemist.queue.' . $schluessel, ''));

        return $name !== '' ? $name : null;
    }
}
