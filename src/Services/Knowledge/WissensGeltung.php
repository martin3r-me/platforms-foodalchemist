<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/** Fachlicher Vertrag: UND zwischen Achsen, ODER zwischen Werten einer Achse. */
final class WissensGeltung
{
    public const ACHSEN = [
        'gang' => 'Gang', 'komponentenrolle' => 'Komponentenrolle', 'portionskontext' => 'Portionskontext',
        'niveau' => 'Niveau', 'saison' => 'Saison', 'warengruppe' => 'Warengruppe',
        'occasion' => 'Anlass', 'sektor' => 'Verpflegungskontext', 'format' => 'Format',
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
                if ($value !== '') $clean[] = $value;
            }
            if ($clean !== []) $result[$axis] = array_values(array_unique($clean));
        }
        ksort($result);
        return $result;
    }

    public static function passt(array $geltung, array $params): bool
    {
        $params['niveau'] ??= $params['level'] ?? null;
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
            if (! is_numeric($row['min'] ?? null) || ! is_numeric($row['max'] ?? null)
                || ! is_finite((float) $row['min']) || ! is_finite((float) $row['max']) || (float) $row['min'] > (float) $row['max']) {
                throw new \RuntimeException('Datenwerk: gültigen Wert oder Bereich min ≤ max angeben.');
            }
            $conditions = self::normalisieren($row['geltung'] ?? []);
            if ($geltung === [] && $conditions === []) throw new \RuntimeException('Datenwerk: mindestens eine Geltungsbedingung angeben.');
            $normalized[] = [
                'kennzahl' => trim($row['kennzahl']), 'min' => (float) $row['min'], 'max' => (float) $row['max'],
                'einheit' => trim($row['einheit']), 'bezug' => trim($row['bezug']), 'quelle' => trim($row['quelle']),
                'geltung' => $conditions,
            ];
        }
        return ['art' => $art, 'geltung' => json_encode($geltung, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'datenwerte' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }
}
