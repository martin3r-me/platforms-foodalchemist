<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Eine Zahl, die eine Entscheidung trägt. params: `wert` (bei `>=`, `<=`, `>`, `<`) oder `min`/`max`
 * (bei `zwischen`), `vergleich`, `einheit`. `pruefe()` bekommt die gemessene Zahl als Text.
 */
final class Schwelle implements RegelArt
{
    private const VERGLEICHE = ['>=', '<=', '>', '<', 'zwischen'];

    public function validiere(array $params): array
    {
        $v = $params['vergleich'] ?? '>=';
        if (! in_array($v, self::VERGLEICHE, true)) {
            return ['vergleich: ' . implode('|', self::VERGLEICHE)];
        }
        if ($v === 'zwischen') {
            return is_numeric($params['min'] ?? null) && is_numeric($params['max'] ?? null) && $params['min'] <= $params['max']
                ? [] : ['zwischen braucht min ≤ max'];
        }

        return is_numeric($params['wert'] ?? null) ? [] : ['wert muss eine Zahl sein'];
    }

    public function wert(FoodAlchemistRule $regel): float
    {
        return (float) ($regel->params['wert'] ?? 0);
    }

    public function erfuellt(FoodAlchemistRule $regel, float $zahl): bool
    {
        $p = $regel->params;

        return match ($p['vergleich'] ?? '>=') {
            '>=' => $zahl >= (float) $p['wert'],
            '<=' => $zahl <= (float) $p['wert'],
            '>' => $zahl > (float) $p['wert'],
            '<' => $zahl < (float) $p['wert'],
            'zwischen' => $zahl >= (float) $p['min'] && $zahl <= (float) $p['max'],
            default => true,
        };
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        $t = str_replace(',', '.', trim($text));
        if (! is_numeric($t) || $this->erfuellt($regel, (float) $t)) {
            return [];
        }
        $p = $regel->params;
        $soll = ($p['vergleich'] ?? '>=') === 'zwischen' ? ($p['min'] . '–' . $p['max']) : (($p['vergleich'] ?? '>=') . ' ' . $p['wert']);

        return [['grund' => "{$text} " . ($p['einheit'] ?? '') . " verletzt „{$regel->titel}“ (Soll {$soll} " . ($p['einheit'] ?? '') . ').',
            'vorschlag' => null, 'treffer' => $text]];
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
