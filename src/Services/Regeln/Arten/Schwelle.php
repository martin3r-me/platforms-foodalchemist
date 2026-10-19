<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Eine Zahl, die eine Entscheidung trägt. params: `wert` (bei `>=`, `<=`, `>`, `<`) oder `min`/`max`
 * (bei `zwischen`), `vergleich`, `einheit`. `pruefe()` bekommt die gemessene Zahl als Text.
 *
 * Spec 85 D-7 (HACCP-Temperaturen): optional `dauer` + `dauer_art` (`mindestens` = Haltezeit, `hoechstens` = „in … min“),
 * `geltung` (wofür die Zahl gilt) und an der GLOBALEN Regel die Untergrenze für Team-Abweichungen: `untergrenze`
 * (+ `untergrenze_dauer`) mit Pflicht-Quelle `untergrenze_quelle` (Norm/Gesetz wörtlich). Ohne Untergrenze ist der globale
 * Wert selbst die Grenze — ein Team darf dann nur strenger werden.
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

        $fehler = is_numeric($params['wert'] ?? null) ? [] : ['wert muss eine Zahl sein'];
        if (isset($params['dauer']) && (! is_numeric($params['dauer']) || (float) $params['dauer'] <= 0)) {
            $fehler[] = 'dauer muss eine Zahl > 0 sein';
        }
        if (isset($params['dauer']) && ! in_array($params['dauer_art'] ?? 'mindestens', ['mindestens', 'hoechstens'], true)) {
            $fehler[] = 'dauer_art: mindestens|hoechstens';
        }
        if (isset($params['untergrenze']) || isset($params['untergrenze_dauer'])) {
            if (isset($params['untergrenze']) && ! is_numeric($params['untergrenze'])) {
                $fehler[] = 'untergrenze muss eine Zahl sein';
            }
            if (trim((string) ($params['untergrenze_quelle'] ?? '')) === '') {
                $fehler[] = 'untergrenze braucht eine Quelle (untergrenze_quelle: Norm/Gesetz wörtlich)';
            }
        }

        return $fehler;
    }

    /**
     * Darf die Team-Zeile `$team` die globale `$global` ersetzen? Strenger immer; lockerer nur bis zur Untergrenze der
     * globalen Regel (ohne Untergrenze: gar nicht). Richtung aus `vergleich` bzw. `dauer_art`.
     */
    public function zulaessig(FoodAlchemistRule $global, FoodAlchemistRule $team): bool
    {
        $g = (array) $global->params;
        $t = (array) $team->params;
        $v = $g['vergleich'] ?? '>=';
        if (($t['vergleich'] ?? $v) !== $v || $v === 'zwischen' || ! is_numeric($t['wert'] ?? null)) {
            return false;   // Richtung oder Form geändert — keine Abweichung, sondern eine andere Regel
        }
        $grenze = (float) ($g['untergrenze'] ?? $g['wert']);
        $okWert = in_array($v, ['>=', '>'], true) ? (float) $t['wert'] >= $grenze : (float) $t['wert'] <= $grenze;
        if (! isset($g['dauer'])) {
            return $okWert;
        }
        $grenzeDauer = (float) ($g['untergrenze_dauer'] ?? $g['dauer']);
        $dauer = (float) ($t['dauer'] ?? $g['dauer']);
        $okDauer = ($g['dauer_art'] ?? 'mindestens') === 'mindestens' ? $dauer >= $grenzeDauer : $dauer <= $grenzeDauer;

        return $okWert && $okDauer;
    }

    /** Ist die Team-Zeile lockerer als die globale (Wert oder Dauer)? Für den Prompt-Hinweis „gesetzliches Minimum“. */
    public function lockerer(FoodAlchemistRule $global, FoodAlchemistRule $team): bool
    {
        $g = (array) $global->params;
        $t = (array) $team->params;
        $v = $g['vergleich'] ?? '>=';
        $wert = in_array($v, ['>=', '>'], true) ? (float) ($t['wert'] ?? 0) < (float) $g['wert'] : (float) ($t['wert'] ?? 0) > (float) $g['wert'];
        if (! isset($g['dauer'])) {
            return $wert;
        }
        $d = (float) ($t['dauer'] ?? $g['dauer']);

        return $wert || (($g['dauer_art'] ?? 'mindestens') === 'mindestens' ? $d < (float) $g['dauer'] : $d > (float) $g['dauer']);
    }

    /** „> 75 °C, 2 min“ bzw. „< 7 °C in 120 min“ — eine Darstellung für Anzeige und Prompt. */
    public static function text(array $p): string
    {
        $einheit = trim((string) ($p['einheit'] ?? ''));
        $soll = ($p['vergleich'] ?? '>=') === 'zwischen'
            ? ($p['min'] ?? '') . '–' . ($p['max'] ?? '') : ($p['vergleich'] ?? '>=') . ' ' . ($p['wert'] ?? '');
        $s = trim($soll . ' ' . $einheit);
        if (isset($p['dauer'])) {
            $s .= (($p['dauer_art'] ?? 'mindestens') === 'hoechstens' ? ' in ' : ', ') . $p['dauer'] . ' ' . ($p['dauer_einheit'] ?? 'min');
        }

        return $s;
    }

    /** Untergrenze als Text („≥ 72 °C, 2 min (DIN 10506 …)“) oder null, wenn keine belegt ist. */
    public static function untergrenzeText(array $p): ?string
    {
        if (! isset($p['untergrenze']) && ! isset($p['untergrenze_dauer'])) {
            return null;
        }
        $q = ['vergleich' => in_array($p['vergleich'] ?? '>=', ['>=', '>'], true) ? '≥' : '≤', 'wert' => $p['untergrenze'] ?? $p['wert'],
            'einheit' => $p['einheit'] ?? ''];
        if (isset($p['dauer'])) {
            $q += ['dauer' => $p['untergrenze_dauer'] ?? $p['dauer'], 'dauer_art' => $p['dauer_art'] ?? 'mindestens', 'dauer_einheit' => $p['dauer_einheit'] ?? 'min'];
        }

        return self::text($q) . ' (' . trim((string) ($p['untergrenze_quelle'] ?? '')) . ')';
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
