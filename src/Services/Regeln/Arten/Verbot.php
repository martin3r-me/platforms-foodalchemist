<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Ein Muster ablehnen. params: `tokens: []` (ganze Wörter; mit `modus: teil` auch innerhalb eines Wortes),
 * `teile: []` (immer auch innerhalb eines Wortes — „milch" in „Vollmilch"; Ausnahmen fangen „Kokosmilch"),
 * `muster: []` (Regex, beim Speichern geprüft), `ausnahmen: []`, `bedingung: {feld: [werte]}`, `grund`.
 * `nur_mit: []` dreht die Logik um: verboten ist, was KEINEN dieser Wortteile trägt (Hausstandard Jus/Fond: ein Fleisch-
 * GP nur als Knochen/Karkasse/Abschnitt … — eine Verbotsliste der Verkaufs-Cuts läuft jedem neuen Cut hinterher,
 * demo Lauf 91: „Rinderhueften: frisch, pariert" stand auf keiner Liste).
 * `erlaubt_wenn: {feld: [werte]}` gibt eine Zeile unabhängig von Wörtern frei (Dominique 10.10.: ein GP mit
 * is_derivat=1 — Knochen, Parüren, Abschnitte, Karkassen nach Regelwerk GP §11.2 — ist immer erlaubt).
 */
final class Verbot implements RegelArt
{
    public function validiere(array $params): array
    {
        $fehler = [...Bedingung::validiere($params['bedingung'] ?? null), ...Bedingung::validiere($params['erlaubt_wenn'] ?? null)];
        if (empty($params['tokens']) && empty($params['muster']) && empty($params['teile']) && empty($params['nur_mit'])) {
            $fehler[] = 'tokens, teile, muster oder nur_mit nötig';
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
        if (! empty($p['erlaubt_wenn']) && Bedingung::erfuellt((array) $p['erlaubt_wenn'], $kontext)) {
            return null;
        }
        if (! empty($p['nur_mit'])) {
            // Wortteil, damit Komposita zählen („Rinderknochen", „Hühnerflügel").
            foreach ((array) $p['nur_mit'] as $erlaubt) {
                if (RegelText::hatTeil($text, (string) $erlaubt)) {
                    return null;
                }
            }

            return trim((string) (preg_split('/[:,(]/u', $text, 2)[0] ?? $text)) ?: $text;
        }
        $ausnahmen = array_map(static fn ($a) => RegelText::norm((string) $a), (array) ($p['ausnahmen'] ?? []));
        foreach ($ausnahmen as $a) {
            if (RegelText::hatWort($text, $a)) {
                return null;
            }
        }
        foreach ((array) ($p['teile'] ?? []) as $t) {
            // Wortteil, aber nicht innerhalb eines Ausnahme-Wortes („milch" ja, „kokosmilch" nein).
            foreach (preg_split('/[^\p{L}\p{N}]+/u', RegelText::norm($text)) ?: [] as $wort) {
                if ($wort !== '' && str_contains($wort, RegelText::norm((string) $t))
                    && ! array_filter($ausnahmen, static fn ($a) => str_contains($wort, $a))) {
                    return (string) $t;
                }
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
