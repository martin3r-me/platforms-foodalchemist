<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Textbaustein-Vorspann der gesplitteten §-Dossiers entfernen, bevor sie in einen Prompt gehen.
 *
 * Der §-Dossier-Split (2026-08-27) setzt jedem Dossier einen identischen Provenienz-Vorspann
 * voran. Gemessen auf demo (2026-09-03):
 *
 *   Basisrezepte  17 Dossiers  51.030 Z.  davon 9.261 Vorspann (18,1 %)
 *   Grundprodukte 20 Dossiers  61.952 Z.  davon 13.612 Vorspann (22,0 %)
 *   Lieferantenart. 9 Dossiers 25.730 Z.  davon 3.224 Vorspann (12,5 %)
 *
 * Er lautet in jedem Dossier gleich und erzählt dem Modell von der VAULT-Seite:
 *
 *   »**Regelwerk Basisrezepte** (verbindlich, Domain `03_KUECHE/03.02_Basisrezepte/`).
 *    Pflicht-Referenz bei Recipe-Migration (Skripte 200-208), Recipe-Skills (…) und
 *    Rezept-Imports. Bei Konflikt mit Skript-Code oder Memory gewinnt dieses Regelwerk.
 *    Verwandte Regelwerke: `Regelwerk_Grundprodukte`, `Regelwerk_Lieferantenartikel`.
 *
 *    Dieses Dossier deckt **§6 — Mengen, Einheiten & Yield** ab und ist eigenständig anwendbar.«
 *
 * Kein Satz davon ist eine Regel. Die Provenienz trägt bereits der Dokument-Titel
 * (»Regelwerk Basisrezepte §6 — Mengen, Einheiten & Yield«), den beide Aufrufer als
 * Block-Kopf ausgeben; die Skript-Nummern beziehen sich auf ein anderes System.
 *
 * WARUM NICHT über die geplante `knowledge_sections`-Tabelle (W1-4/W3-3): gemessen tragen die
 * §-Dossiers **null** Changelog-Zeichen (die Changelogs stecken in den ungesplitteten
 * Original-Docs), und 21 % ihres Textes sind Tabellenzeilen — die aber NORMATIV sind
 * (§5 Default-GP-Tabelle, §8 Pflichtangaben-Matrix). Die Plan-Annahme »tabellendominant →
 * kind=referenz« hätte damit bindende Regeln verworfen. Nach Abzug von Changelog und
 * Referenz-Tabellen bleibt als einziger nicht-normativer Anteil genau dieser Vorspann —
 * und für den braucht es kein Schema, keinen Sectionizer und keinen Re-Index.
 * Die Sections-Tabelle verdient sich ihren Platz beim Chunking (heading_path im Vektor),
 * nicht hier.
 */
final class DossierText
{
    /**
     * Erkennungsmuster, absichtlich eng: der Vorspann MUSS mit dem fett gesetzten
     * Regelwerk-Namen beginnen und vor der ersten `##`-Überschrift liegen. Alles andere
     * bleibt unangetastet — ein Dokument, das keinen generierten Vorspann trägt, geht
     * unverändert durch. Lieber ein Dossier nicht bereinigen als in einem fremden Aufbau
     * echten Inhalt abschneiden.
     */
    private const VORSPANN_START = '/^\*\*Regelwerk\b/u';

    public static function ohneVorspann(string $md): string
    {
        $md = ltrim($md);

        if (preg_match(self::VORSPANN_START, $md) !== 1) {
            return $md;
        }

        // Ohne `##`-Überschrift gäbe es keinen Rest — dann ist der »Vorspann« der ganze
        // Text und wird selbstverständlich behalten.
        if (preg_match('/^##+\s+/mu', $md, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $md;
        }

        // ACHTUNG: `PREG_OFFSET_CAPTURE` liefert einen BYTE-Offset, `mb_substr` zählt
        // ZEICHEN. Der Vorspann enthält »§«, »—«, »…«, Umlaute — mit `mb_substr` landet
        // der Schnitt darum mitten im Text (der Testfall lieferte »— Mengen, Einheiten &
        // Yield« statt »## §6 …«, also einen abgeschnittenen Paragraphen). Byte-Offset
        // verlangt `substr`; der Offset zeigt auf ein »#«, ist also eine gültige
        // UTF-8-Grenze.
        $rest = ltrim(substr($md, (int) $m[0][1]));

        return $rest !== '' ? $rest : $md;
    }

    /**
     * Wie viele Zeichen `ohneVorspann()` entfernen würde — für Messsonde und Protokoll.
     */
    public static function vorspannLaenge(string $md): int
    {
        return max(0, mb_strlen(ltrim($md)) - mb_strlen(self::ohneVorspann($md)));
    }
}
