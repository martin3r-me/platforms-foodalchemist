<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Multi-Wort-Suche für Katalog-Picker (GPs, LAs, Rezepte).
 *
 * Problem: ein einzelnes `LOWER(name) LIKE '%<ganze Eingabe>%'` scheitert, sobald
 * ein zweites Wort getippt wird — «mehrkorn tom» müsste als zusammenhängender
 * Teilstring vorkommen und findet «Tomatenbrot Mehrkorn» nie.
 *
 * Lösung: die Eingabe in Tokens splitten; JEDES Token muss (in beliebiger
 * Reihenfolge) als Teilstring vorkommen. Tokens sind gebundene Parameter (safe);
 * die Spaltennamen kommen ausschließlich aus vertrauenswürdigem Aufrufer-Code.
 */
final class Suche
{
    /** @return array<int, string> lower-case Tokens ohne Leerstrings */
    public static function tokens(string $suche): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($suche))) ?: [],
            fn ($t) => $t !== '',
        ));
    }

    /**
     * Hängt pro Token ein AND `LOWER(<column>) LIKE ?` an den Query-Builder.
     * Gibt den Builder zurück (chainbar). Leere Suche = kein Filter.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function like($query, string $column, string $suche)
    {
        return self::likeAny($query, [$column], $suche);
    }

    /**
     * Umlaut-tolerante Varianten eines Tokens (Dominique 19.10.: GP-Namen mit echten Umlauten, Bestand großteils ASCII):
     * „pueree“ findet „Püree“ und umgekehrt, „suess“ findet „süß“. ß→ss nur in eine Richtung (nie „Wasser“ → „Waßer“).
     *
     * @return list<string>
     */
    public static function varianten(string $token): array
    {
        $ascii = strtr($token, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $umlaut = strtr($ascii, ['ae' => 'ä', 'oe' => 'ö', 'ue' => 'ü']);

        return array_values(array_unique([$token, $ascii, $umlaut]));
    }

    /**
     * Wie like(), aber über MEHRERE Spalten: jedes Token muss treffen (AND), pro Token
     * reicht EINE der Spalten (OR). So bleibt Mehr-Wort-Suche korrekt, wenn Treffer über
     * Name + Nebenfeld (Rolle, Anlass, Slug …) verteilt sein dürfen. Spalten kommen aus
     * vertrauenswürdigem Aufrufer-Code (auch Ausdrücke wie "COALESCE(role, '')" erlaubt).
     *
     * Synonyme (GP-Browser, 12.11.2026 — „Anchovis“ fand „Sardellenfilet“ nicht): mit `$aliasGruppen`
     * (Sätze bedeutungsgleicher Phrasen, z. B. {@see \Platform\FoodAlchemist\Services\TerminologyService::aliasGroups})
     * wird ein Suchwort bzw. eine Wortfolge, die GENAU einem Gruppenmitglied entspricht, zusätzlich über die
     * anderen Mitglieder gesucht (ODER). Die Alternativen treffen nur am Wortanfang („obers“ trifft nicht
     * „Rinderoberschale“), das Originalwort weiter als Teilstring. Das UND zwischen den übrigen Wörtern bleibt.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @param  array<int, string>  $columns
     * @param  list<list<string>>  $aliasGruppen
     * @return T
     */
    public static function likeAny($query, array $columns, string $suche, array $aliasGruppen = [])
    {
        foreach (self::einheiten(self::tokens($suche), $aliasGruppen) as [$teil, $alternativen]) {
            if ($alternativen === []) {
                $query->where(fn ($w) => self::tokenTreffer($w, $columns, $teil[0]));

                continue;
            }
            $query->where(function ($w) use ($columns, $teil, $alternativen) {
                $w->where(function ($o) use ($columns, $teil) {
                    foreach ($teil as $token) {
                        $o->where(fn ($t) => self::tokenTreffer($t, $columns, $token));
                    }
                });
                foreach ($alternativen as $alt) {
                    $w->orWhere(fn ($a) => self::wortanfangTreffer($a, $columns, $alt));
                }
            });
        }

        return $query;
    }

    /** Ein Token als Teilstring in irgendeiner Spalte (mit Umlaut-Varianten) — das bisherige Suchverhalten. */
    private static function tokenTreffer($w, array $columns, string $token): void
    {
        $erstes = true;
        foreach (array_values($columns) as $col) {
            foreach (self::varianten($token) as $v) {
                $erstes ? $w->whereRaw("LOWER({$col}) LIKE ?", ['%' . $v . '%']) : $w->orWhereRaw("LOWER({$col}) LIKE ?", ['%' . $v . '%']);
                $erstes = false;
            }
        }
    }

    /** Eine Alias-Phrase am Wortanfang (Namensanfang oder nach Leerzeichen, Bindestrich, Klammer) in irgendeiner Spalte. */
    private static function wortanfangTreffer($w, array $columns, string $phrase): void
    {
        $erstes = true;
        foreach (array_values($columns) as $col) {
            foreach (self::varianten($phrase) as $v) {
                foreach ([$v . '%', '% ' . $v . '%', '%-' . $v . '%', '%(' . $v . '%'] as $muster) {
                    $erstes ? $w->whereRaw("LOWER({$col}) LIKE ?", [$muster]) : $w->orWhereRaw("LOWER({$col}) LIKE ?", [$muster]);
                    $erstes = false;
                }
            }
        }
    }

    /**
     * Zerlegt die Tokens in Einheiten: eine Wortfolge, die einem Alias-Mitglied entspricht (längste zuerst), wird zu
     * einer Einheit mit den anderen Mitgliedern als Alternativen; jedes übrige Token ist eine Einheit ohne Alternativen.
     *
     * @param  list<string>  $tokens
     * @param  list<list<string>>  $aliasGruppen
     * @return list<array{0: list<string>, 1: list<string>}>
     */
    private static function einheiten(array $tokens, array $aliasGruppen): array
    {
        $schluessel = fn (string $s): string => strtr(implode(' ', self::tokens($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $mitglieder = [];   // Schlüssel → Alternativen (andere Mitglieder aller Gruppen, in denen er vorkommt)
        $maxWorte = 1;
        foreach ($aliasGruppen as $gruppe) {
            $gruppe = array_values(array_filter(array_map(fn ($m) => implode(' ', self::tokens((string) $m)), $gruppe), fn ($m) => $m !== ''));
            foreach ($gruppe as $m) {
                $k = $schluessel($m);
                foreach ($gruppe as $anderes) {
                    if ($schluessel($anderes) !== $k) {
                        $mitglieder[$k][$anderes] = true;
                    }
                }
                $maxWorte = max($maxWorte, count(explode(' ', $k)));
            }
        }

        $einheiten = [];
        for ($i = 0, $n = count($tokens); $i < $n;) {
            for ($laenge = min($maxWorte, $n - $i); $laenge >= 1; $laenge--) {
                $teil = array_slice($tokens, $i, $laenge);
                $k = $schluessel(implode(' ', $teil));
                if (isset($mitglieder[$k]) || $laenge === 1) {
                    $einheiten[] = [$teil, array_keys($mitglieder[$k] ?? [])];
                    $i += $laenge;
                    break;
                }
            }
        }

        return $einheiten;
    }
}
