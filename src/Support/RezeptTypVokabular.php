<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Kontrolliertes Typ-Vokabular der Basisrezepte (Regelwerk Basisrezepte §1.2) — der Teil vor dem Doppelpunkt
 * („Püree: Petersilienwurzel"). Seit Spec 81 eine Regel als Daten (`basisrezept.1.2.typ`, Einstellungen ›
 * Regeln); vorher wurde die Markdown-Tabelle des Dossiers geparst. Hier nur Lese-Helfer.
 *
 * Spec 80: deterministische Grundlage für die Funktionsprüfung im Bestand (Teil B3) und die Vorprüfung beim
 * Anlegen (Teil G1). Fehlt die Regel, ist das Vokabular leer, und das Regelbuch meldet es im Log.
 */
final class RezeptTypVokabular
{
    public const REGEL = 'basisrezept.1.2.typ';

    /** @return array<string, list<string>> Hauptgruppe => erlaubte Typen */
    public static function tabelle(): array
    {
        $out = [];
        foreach ((array) (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::per(self::REGEL)?->params['werte'] ?? []) as $w) {
            $gruppe = trim((string) ($w['gruppe'] ?? ''));
            $wert = trim((string) ($w['wert'] ?? ''));
            if ($gruppe !== '' && $wert !== '') {
                $out[$gruppe][] = $wert;
            }
        }

        return $out;
    }

    /** Memo leeren (Tests, nach dem Speichern einer Regel). */
    public static function vergessen(): void
    {
        \Platform\FoodAlchemist\Services\Regeln\RegelBuch::vergessen();
    }

    /** @return list<string> alle erlaubten Typen, einmal */
    public static function alle(): array
    {
        $t = self::tabelle();

        return $t === [] ? [] : array_values(array_unique(array_merge(...array_values($t))));
    }

    public static function istGeladen(): bool
    {
        return self::tabelle() !== [];
    }

    /** Kanonische Schreibweise eines Typs (Groß/klein, Umlaut/Akzent egal) oder null. */
    public static function finde(string $typ): ?string
    {
        $schluessel = self::norm($typ);
        foreach (self::alle() as $t) {
            if (self::norm($t) === $schluessel) {
                return $t;
            }
        }

        return null;
    }

    /** Hauptgruppen, in denen ein Typ vorkommt (Coulis/Velouté/Confit stehen in mehreren). @return list<string> */
    public static function gruppenVon(string $typ): array
    {
        $kanon = self::finde($typ);
        if ($kanon === null) {
            return [];
        }

        return array_keys(array_filter(self::tabelle(), static fn (array $typen) => in_array($kanon, $typen, true)));
    }

    /** Präfix vor dem Doppelpunkt, roh (auch wenn nicht im Vokabular, z. B. „Garnitur"). */
    public static function praefix(string $name): ?string
    {
        if (! str_contains($name, ':')) {
            return null;
        }
        $p = trim((string) strstr($name, ':', true));

        return $p === '' ? null : $p;
    }

    /**
     * Name ohne TYP — schneidet das Präfix nur ab, wenn es ein Vokabular-Typ ist („Püree: Petersilienwurzel" →
     * „Petersilienwurzel"), sonst bleibt der Name stehen („Ciabatta: frisch", „Kalbsjus: dunkel" — GP-Schreibweise,
     * der Teil vor dem Doppelpunkt ist das Produkt). Klammer-Zusatz fällt weg. Für Zutaten/Zeilen statt
     * {@see self::bezeichnung}, die ALLES vor dem Doppelpunkt abschneidet (Kuratorin 10.10.: „Ciabatta: frisch" → „frisch").
     */
    public static function ohneTyp(string $name): string
    {
        $p = self::praefix($name);
        if ($p !== null && self::finde($p) !== null) {
            return self::bezeichnung($name);
        }

        return trim((string) preg_replace('/\s*\([^)]*\)/u', '', $name));
    }

    /** Teil nach dem Doppelpunkt ohne Klammer-Zusatz, bzw. der ganze Name, wenn es kein Präfix gibt. */
    public static function bezeichnung(string $name): string
    {
        $b = str_contains($name, ':') ? (string) substr($name, strpos($name, ':') + 1) : $name;

        return trim((string) preg_replace('/\([^)]*\)/u', '', $b));
    }

    /**
     * Der Typ, den ein freier Text verlangt: ein Präfix aus dem Vokabular, sonst das längste
     * Vokabular-Wort, auf das ein Wort endet („Petersilienpüree" → Püree, „Cremige Polenta" → Polenta).
     * Null = der Text legt keinen Typ fest (oder das Vokabular ist nicht geladen).
     */
    public static function typImText(string $text): ?string
    {
        if (($p = self::praefix($text)) !== null && ($t = self::finde($p)) !== null) {
            return $t;
        }
        $woerter = preg_split('/[\s,;:()\/]+/u', self::norm($text)) ?: [];
        $bester = null;
        foreach (self::alle() as $typ) {
            $n = self::norm($typ);
            if (mb_strlen($n) < 3) {
                continue;
            }
            foreach ($woerter as $w) {
                if ($w === $n || (mb_strlen($w) > mb_strlen($n) && str_ends_with($w, str_replace(' ', '', $n)))) {
                    if ($bester === null || mb_strlen($n) > mb_strlen(self::norm($bester))) {
                        $bester = $typ;
                    }
                }
            }
        }

        return $bester;
    }

    /** Vergleichs-Schlüssel: klein, Umlaute/Akzente zu Grundbuchstaben, Bindestriche zu Leerzeichen. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'â' => 'a', 'à' => 'a', 'ô' => 'o', 'û' => 'u', 'î' => 'i', 'ç' => 'c', '-' => ' ']);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
