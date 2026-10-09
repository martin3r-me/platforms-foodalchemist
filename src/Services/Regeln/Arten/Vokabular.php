<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Nur erlaubte Werte; Aliase führen auf den kanonischen Wert.
 *
 * params: `werte: [{wert, aliase?: [], gruppe?: string}]`, optional `muster: ["würfel <mm>"]`
 * (`<mm>` = Zahl + „mm", `<zahl>` = Zahl). Geprüft wird ein EINZELNER Wert (ein Feld, ein Präfix).
 */
final class Vokabular implements RegelArt
{
    public function validiere(array $params): array
    {
        $fehler = [];
        if (! isset($params['werte']) || ! is_array($params['werte']) || $params['werte'] === []) {
            return ['werte: mindestens ein Wert'];
        }
        foreach ($params['werte'] as $i => $w) {
            if (! is_array($w) || trim((string) ($w['wert'] ?? '')) === '') {
                $fehler[] = "werte[{$i}]: wert fehlt";
            }
        }

        return $fehler;
    }

    /** Kanonischer Wert für `$text` (Wert, Alias oder Muster) oder null. */
    public function kanonisch(FoodAlchemistRule $regel, string $text): ?string
    {
        $n = RegelText::norm($text);
        if ($n === '') {
            return null;
        }
        foreach ((array) ($regel->params['werte'] ?? []) as $w) {
            foreach ([(string) ($w['wert'] ?? ''), ...array_map('strval', (array) ($w['aliase'] ?? []))] as $kandidat) {
                if ($kandidat !== '' && RegelText::norm($kandidat) === $n) {
                    return (string) $w['wert'];
                }
            }
        }
        foreach ((array) ($regel->params['muster'] ?? []) as $m) {
            $teile = preg_split('/(<mm>|<zahl>)/u', RegelText::norm((string) $m), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $rx = '/^' . implode('', array_map(static fn (string $t) => match ($t) {
                '<mm>' => '\d+(?:[.,]\d+)?\s*mm', '<zahl>' => '\d+(?:[.,]\d+)?', default => preg_quote($t, '/'),
            }, $teile)) . '$/u';
            if (RegelText::passt($rx, $n)) {
                return trim($text);
            }
        }

        return null;
    }

    /** @return list<string> alle Werte (optional einer Gruppe) */
    public function werte(FoodAlchemistRule $regel, ?string $gruppe = null): array
    {
        $out = [];
        foreach ((array) ($regel->params['werte'] ?? []) as $w) {
            if ($gruppe === null || RegelText::norm((string) ($w['gruppe'] ?? '')) === RegelText::norm($gruppe)) {
                $out[] = (string) $w['wert'];
            }
        }

        return $out;
    }

    public function gruppeVon(FoodAlchemistRule $regel, string $text): ?string
    {
        $k = $this->kanonisch($regel, $text);
        foreach ((array) ($regel->params['werte'] ?? []) as $w) {
            if ($k !== null && (string) $w['wert'] === $k) {
                return isset($w['gruppe']) ? (string) $w['gruppe'] : null;
            }
        }

        return null;
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        if (trim($text) === '' || $this->kanonisch($regel, $text) !== null) {
            return [];
        }

        return [['grund' => '»' . trim($text) . '« steht nicht im Vokabular „' . $regel->titel . '“.', 'vorschlag' => null, 'treffer' => trim($text)]];
    }

    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
    {
        $k = $this->kanonisch($regel, $text);

        return $k !== null && $k !== trim($text) ? $k : null;
    }

    public function kannKorrigieren(): bool
    {
        return true;
    }
}
