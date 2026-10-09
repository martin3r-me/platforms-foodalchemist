<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Schreibweise vereinheitlichen: `paare: [{von, nach}]`, optional `ausnahmen: []` (Wörter, bei denen die Regel
 * schweigt) und `bedingung`. Ersetzt ganze Wörter, tolerant gegen Umlaut-Umschreibung.
 */
final class Ersetzung implements RegelArt
{
    public function validiere(array $params): array
    {
        if (! isset($params['paare']) || ! is_array($params['paare']) || $params['paare'] === []) {
            return ['paare: mindestens ein Paar {von, nach}'];
        }
        $fehler = Bedingung::validiere($params['bedingung'] ?? null);
        foreach ($params['paare'] as $i => $p) {
            if (! is_array($p) || trim((string) ($p['von'] ?? '')) === '' || ! array_key_exists('nach', $p)) {
                $fehler[] = "paare[{$i}]: von und nach nötig";
            }
        }

        return $fehler;
    }

    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
    {
        $p = $regel->params;
        if (isset($p['bedingung']) && ! Bedingung::erfuellt((array) $p['bedingung'], $kontext)) {
            return null;
        }
        foreach ((array) ($p['ausnahmen'] ?? []) as $a) {
            if (RegelText::hatWort($text, (string) $a)) {
                return null;
            }
        }
        $neu = $text;
        foreach ((array) ($p['paare'] ?? []) as $paar) {
            $neu = RegelText::ersetzeWort($neu, (string) $paar['von'], (string) $paar['nach']);
        }
        $neu = trim((string) preg_replace('/\s{2,}/u', ' ', $neu));

        return $neu !== trim($text) ? $neu : null;
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        $neu = $this->korrigiere($regel, $text, $kontext);

        return $neu === null ? [] : [['grund' => 'Schreibweise nach „' . $regel->titel . '“.', 'vorschlag' => $neu, 'treffer' => null]];
    }

    public function kannKorrigieren(): bool
    {
        return true;
    }
}
