<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kontrolliertes Typ-Vokabular der Basisrezepte (Regelwerk Basisrezepte §1.2) — der Teil vor dem Doppelpunkt
 * („Püree: Petersilienwurzel"). Quelle ist das Wissens-Dossier `…--1-2-typ-vokabular-kontrolliert` im
 * FA-Wissensmodul (SSOT, Spec 41 A3); hier wird es nur GELESEN und seine Tabelle geparst — keine Liste im
 * Code, damit eine Änderung im Wissensmodul sofort wirkt.
 *
 * Spec 80: deterministische Grundlage für die Funktionsprüfung im Bestand (Teil B3) und die Vorprüfung beim
 * Anlegen (Teil G1). Fehlt das Dossier (lokale Sandbox ohne Wissen), ist das Vokabular leer und alle
 * Prüfungen, die darauf aufbauen, greifen nicht (fail-open = Verhalten wie vorher).
 */
final class RezeptTypVokabular
{
    public const DOSSIER_SLUG_LIKE = '%1-2-typ-vokabular-kontrolliert%';

    /** Container-Schlüssel des Memos — je Request/Job (bzw. je Test) frisch, nie prozessweit veraltet. */
    private const MEMO = 'foodalchemist.rezept_typ_vokabular';

    /** @return array<string, list<string>> Hauptgruppe => erlaubte Typen */
    public static function tabelle(): array
    {
        // Langlebige Queue-Worker teilen den Container über viele Jobs — nach 10 Minuten neu lesen,
        // damit eine Änderung im Wissensmodul ohne Worker-Neustart ankommt.
        if (app()->bound(self::MEMO) && (app(self::MEMO)['bis'] ?? 0) > time()) {
            return app(self::MEMO)['tabelle'];
        }
        try {
            $md = DB::table('foodalchemist_knowledge_documents')
                ->where('slug', 'like', self::DOSSIER_SLUG_LIKE)
                ->where('active', 1)->whereNull('deleted_at')
                ->orderByDesc('version')->value('content_md');
        } catch (\Throwable) {
            $md = null;
        }

        $tabelle = self::parse((string) ($md ?? ''));
        app()->instance(self::MEMO, ['tabelle' => $tabelle, 'bis' => time() + 600]);

        return $tabelle;
    }

    /** Memo leeren (Tests, nach einem Wissens-Import). */
    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Markdown-Tabelle `| Hauptgruppe | `Typ`, `Typ` … |` → Hauptgruppe => Typen. Zeilen ohne Typ in
     * Backticks (Kopf, Trenner, „Sonstiges — kein fester Typ") fallen weg.
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $md): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $md) ?: [] as $zeile) {
            if (! str_starts_with(trim($zeile), '|')) {
                continue;
            }
            $zellen = array_map('trim', explode('|', trim($zeile, " |\t")));
            if (count($zellen) < 2) {
                continue;
            }
            preg_match_all('/`([^`]+)`/u', $zellen[1], $m);
            $typen = array_values(array_unique(array_map('trim', $m[1] ?? [])));
            $gruppe = trim(preg_replace('/\([^)]*\)|[`*]/u', '', $zellen[0]) ?? '');
            if ($typen !== [] && $gruppe !== '') {
                $out[$gruppe] = $typen;
            }
        }

        return $out;
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
