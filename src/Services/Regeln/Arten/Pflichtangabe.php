<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Unter einer Bedingung muss eine Angabe da sein. params: `bedingung: {feld: [werte]}`, `tokens: []` und/oder
 * `muster: []` (eines muss treffen), `hinweis`. Nur flaggen — der Wert wird nie erfunden.
 */
final class Pflichtangabe implements RegelArt
{
    public function validiere(array $params): array
    {
        $fehler = Bedingung::validiere($params['bedingung'] ?? null);
        if (empty($params['bedingung'])) {
            $fehler[] = 'bedingung nötig (wann ist die Angabe Pflicht?)';
        }
        if (empty($params['tokens']) && empty($params['muster'])) {
            $fehler[] = 'tokens oder muster nötig';
        }
        foreach ((array) ($params['muster'] ?? []) as $m) {
            if (($f = RegelText::musterFehler((string) $m)) !== null) {
                $fehler[] = $f;
            }
        }

        return $fehler;
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        $p = $regel->params;
        if (! Bedingung::erfuellt((array) ($p['bedingung'] ?? []), $kontext)) {
            return [];
        }
        $teil = ($p['modus'] ?? 'wort') === 'teil';
        foreach ((array) ($p['tokens'] ?? []) as $t) {
            if ($teil ? RegelText::hatTeil($text, (string) $t) : RegelText::hatWort($text, (string) $t)) {
                return [];
            }
        }
        foreach ((array) ($p['muster'] ?? []) as $m) {
            if (RegelText::passt((string) $m, $text)) {
                return [];
            }
        }
        $hinweis = trim((string) ($p['hinweis'] ?? '')) ?: 'Pflichtangabe „' . $regel->titel . '“ fehlt.';

        return [['grund' => $hinweis, 'vorschlag' => null, 'treffer' => null]];
    }

    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
    {
        return null;
    }

    public function kannKorrigieren(): bool
    {
        return false;
    }
}
