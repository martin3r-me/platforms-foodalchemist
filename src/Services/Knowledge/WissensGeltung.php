<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/** Fachlicher Vertrag: UND zwischen Achsen, ODER zwischen Werten einer Achse. */
final class WissensGeltung
{
    /**
     * ★ `ausgabeform` hiess bis 2026-09-11 `format` — umbenannt, weil „Format" in
     * FoodAlchemist bereits eine **Konzeptzusammenstellung** ist (ein Produkt mit Slots,
     * Druck und Kalkulation; auf demo z. B. „TAiste & fly"). Ein Kurator konnte nicht
     * wissen, welches der beiden gemeint war. Die Achse meint die Form, in der das Essen
     * herausgeht — sie bestimmt den Mengen-Multiplikator.
     *
     * Der Umbenennung musste eine Datenmigration beiliegen: {@see self::lesen()} validiert
     * NICHT, es decodiert nur. Eine zurückgebliebene `format`-Geltung hätte gegen einen
     * Parameter geprüft, den niemand mehr schickt — und damit nie mehr getroffen, ohne
     * Fehlermeldung.
     */
    /**
     * Gebräuchliche Bezugsgrössen — als ORIENTIERUNG, nicht als Schranke.
     *
     * ⚠ Bewusst NICHT erzwungen. Diese Liste entstand für sechs Mengen-Dossiers und deckt
     * nachweislich nicht alles ab: ein bestehender Vertragstest nutzt „verzehrfertig pro
     * Portion" — eine völlig sinnvolle Angabe, die hier fehlt. Eine geschlossene Liste würde
     * Leute zwingen, falsch zu taggen. Bevor daraus ein Vokabular wird, braucht es eine
     * Bestandsaufnahme der tatsächlich benutzten Bezüge; das gehört in den Korpus-Umbau.
     *
     * Erzwungen wird nur die Kleinschreibung (s. u.) — dafür gibt es einen harten Grund.
     *
     * ★ Der Bezug ist das Feld, das den Wert erst benutzbar macht: „180 g" allein sagt nicht,
     * ob roh oder gegart. Bei 30 % Garverlust sind das 80 g Unterschied pro Person im Einkauf.
     */
    public const BEZUGSGROESSEN = [
        'roh', 'gegart', 'trocken', 'mit_knochen', 'mit_schale', 'stueck', 'ganzes_tier',
        'relativ_a_la_carte',
    ];

    public const ACHSEN = [
        'gang' => 'Gang', 'komponentenrolle' => 'Komponentenrolle', 'portionskontext' => 'Portionskontext',
        'niveau' => 'Niveau', 'saison' => 'Saison', 'warengruppe' => 'Warengruppe',
        'occasion' => 'Anlass', 'sektor' => 'Verpflegungskontext', 'ausgabeform' => 'Ausgabeform',
    ];

    public static function normalisieren(mixed $input): array
    {
        if (! is_array($input)) throw new \RuntimeException('Geltung muss ein Objekt aus Achsen und Werten sein.');
        $result = [];
        foreach ($input as $axis => $values) {
            if (! isset(self::ACHSEN[$axis])) throw new \RuntimeException("Unbekannte Achse: {$axis}.");
            $values = is_string($values) ? explode(',', $values) : $values;
            if (! is_array($values)) throw new \RuntimeException("Werte für {$axis} müssen eine Liste sein.");
            $clean = [];
            foreach ($values as $value) {
                if (! is_string($value) || mb_strlen($value) > 100) throw new \RuntimeException("Ungültiger Achsenwert für {$axis}.");
                $value = mb_strtolower(trim($value));
                if ($value === '') continue;
                // ★ Prüfen statt still annehmen. Ein erfundener Wert faellt sonst NICHT auf —
                // er trifft einfach nie, und niemand merkt es. Genau so sind meine ersten
                // Tags entstanden (`protein` statt `komponente`, `obst_zitrus` statt der
                // GP-Warengruppe). Freie Achsen und leere Quellen schraenken nicht ein.
                if (! WissensAchsenVokabular::gueltig($axis, $value)) {
                    $erlaubt = WissensAchsenVokabular::werteListe($axis) ?? [];
                    throw new \RuntimeException(sprintf('Unbekannter Wert "%s" fuer die Achse %s. Erlaubt: %s.',
                        $value, $axis, implode(', ', array_slice($erlaubt, 0, 20)).(count($erlaubt) > 20 ? ' …' : '')));
                }
                $clean[] = $value;
            }
            if ($clean !== []) $result[$axis] = array_values(array_unique($clean));
        }
        ksort($result);
        return $result;
    }

    /**
     * Spec 52 — `ausgabeform` aus Sektor × Serviceform ABLEITEN.
     *
     * Beide Werte schickt die Leitstelle ohnehin mit; eine eigene Auswahl wäre ein drittes
     * Feld für eine Information, die schon da ist. Dass Serviceform allein nicht genügt, ist
     * der Grund für die Kombination: Bankett-Buffet und Kantinen-Buffet tragen verschiedene
     * Mengen-Faktoren (0,75–0,85 vs. 0,85–0,95) bei identischer Serviceform.
     *
     * ★ **Nicht gelistete Kombination = KEINE Ausgabeform.** Catering+Flying, Care+Boxed und
     * alles andere ergibt hier nichts — der Resolver meldet dann eine Lücke statt einen
     * falschen Faktor anzuwenden. `foodtruck` und `sweet_table` haben bewusst keine
     * Kombination; die bleiben ausdrücklich setzbar.
     *
     * Eine explizit übergebene `ausgabeform` gewinnt immer: der Mensch weiss mehr als die Regel.
     */
    public static function mitAbleitung(array $params): array
    {
        if (isset($params['ausgabeform']) && is_string($params['ausgabeform']) && trim($params['ausgabeform']) !== '') {
            return $params;
        }
        $sektor = is_string($params['sektor'] ?? null) ? mb_strtolower(trim($params['sektor'])) : '';
        $service = is_string($params['serviceform'] ?? null) ? mb_strtolower(trim($params['serviceform'])) : '';
        if ($sektor === '') {
            return $params;
        }

        $regeln = config('foodalchemist.ai.ausgabeform_ableitung', []);
        $regeln = is_array($regeln) ? $regeln : [];
        // Erst die genaue Kombination, dann die serviceform-unabhängige Regel des Sektors.
        $treffer = $regeln[$sektor.'|'.$service] ?? $regeln[$sektor.'|*'] ?? null;
        if (is_string($treffer) && $treffer !== '') {
            $params['ausgabeform'] = $treffer;
        }

        return $params;
    }

    public static function passt(array $geltung, array $params): bool
    {
        $params['niveau'] ??= $params['level'] ?? null;
        $params = self::mitAbleitung($params);
        foreach ($geltung as $axis => $values) {
            $actual = $params[$axis] ?? null;
            $actual = is_array($actual) ? $actual : [$actual];
            $actual = array_map(static fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : '', $actual);
            if (array_intersect($values, $actual) === []) return false;
        }
        return true;
    }

    public static function lesen(mixed $json): array
    {
        return is_array($json) ? $json : (json_decode((string) $json, true) ?: []);
    }

    /** Eine Validierung für UI und MCP; Datenwerk-Werte sind Teil derselben Dossierversion. */
    public static function payload(?string $art, mixed $geltung, mixed $werte): array
    {
        if (! Wissensart::gueltig($art)) throw new \RuntimeException('Unbekannte Wissensart.');
        $geltung = self::normalisieren($geltung);
        if ($geltung !== [] && in_array($art, [Wissensart::REGEL, Wissensart::ABLAUF], true)) throw new \RuntimeException('Geltungsfilter gelten für Fachwissen, Referenzen und Datenwerke. Regeln werden über den Kanon zugeordnet, Abläufe über den Agenten.');
        if (! is_array($werte) || ! array_is_list($werte)) throw new \RuntimeException('Datenwerte müssen eine Liste sein.');
        if ($werte !== [] && $art !== Wissensart::DATENWERK) throw new \RuntimeException('Strukturierte Datenwerte gehören ausschließlich zu einem Datenwerk.');
        $normalized = [];
        foreach ($werte as $row) {
            if (! is_array($row)) throw new \RuntimeException('Ungültiger Datenwert.');
            foreach (['kennzahl', 'einheit', 'bezug', 'quelle'] as $key) {
                if (! is_string($row[$key] ?? null) || trim($row[$key]) === '') throw new \RuntimeException("Datenwerk: {$key} ist Pflicht.");
            }
            // NORMALISIEREN, nicht einschraenken. Der Resolver erkennt einen Widerspruch ueber
            // [min, max, einheit, bezug] — „Roh" und „roh" waeren sonst zwei verschiedene
            // Bezuege und damit ein Widerspruch, den es nicht gibt.
            $bezug = mb_strtolower(trim($row['bezug']));
            if (! is_numeric($row['min'] ?? null) || ! is_numeric($row['max'] ?? null)
                || ! is_finite((float) $row['min']) || ! is_finite((float) $row['max']) || (float) $row['min'] > (float) $row['max']) {
                throw new \RuntimeException('Datenwerk: gültigen Wert oder Bereich min ≤ max angeben.');
            }
            $conditions = self::normalisieren($row['geltung'] ?? []);
            if ($geltung === [] && $conditions === []) throw new \RuntimeException('Datenwerk: mindestens eine Geltungsbedingung angeben.');
            $normalized[] = [
                'kennzahl' => trim($row['kennzahl']), 'min' => (float) $row['min'], 'max' => (float) $row['max'],
                'einheit' => trim($row['einheit']), 'bezug' => $bezug, 'quelle' => trim($row['quelle']),
                'geltung' => $conditions,
            ];
        }
        return ['art' => $art, 'geltung' => json_encode($geltung, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'datenwerte' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }
}
