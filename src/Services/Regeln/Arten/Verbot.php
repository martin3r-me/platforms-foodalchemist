<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Ein Muster ablehnen. params: `tokens: []` (ganze Wörter; mit `modus: teil` auch innerhalb eines Wortes),
 * `muster: []` (Regex, beim Speichern geprüft), `ausnahmen: []`, `bedingung: {feld: [werte]}`, `grund`.
 */
final class Verbot implements RegelArt
{
    public function validiere(array $params): array
    {
        $fehler = Bedingung::validiere($params['bedingung'] ?? null);
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

    /** Erstes verbotenes Token/Muster in `$text` oder null. */
    public function treffer(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
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
        $teil = ($p['modus'] ?? 'wort') === 'teil';
        foreach ((array) ($p['tokens'] ?? []) as $t) {
            if ($teil ? RegelText::hatTeil($text, (string) $t) : RegelText::hatWort($text, (string) $t)) {
                return (string) $t;
            }
        }
        foreach ((array) ($p['muster'] ?? []) as $m) {
            if (RegelText::passt((string) $m, $text)) {
                return (string) $m;
            }
        }

        return null;
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        $t = $this->treffer($regel, $text, $kontext);
        if ($t === null) {
            return [];
        }
        $grund = trim((string) ($regel->params['grund'] ?? '')) ?: '„' . $regel->titel . '“';

        return [['grund' => "»{$t}« ist nicht erlaubt: {$grund}", 'vorschlag' => null, 'treffer' => $t]];
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
