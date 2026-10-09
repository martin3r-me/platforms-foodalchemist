<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Begriff → festes Ziel. params: `eintraege: [{begriff, aliase?: [], ziel_typ: gp|rezept|wert, ziel_id?, ziel_name,
 * kontext?: {feld: [werte]}}]`. Ein Eintrag mit passendem Kontext schlägt einen ohne Kontext (Olivenöl kalt/heiß).
 *
 * `vergleich: tokens` (Regel-Ebene) vergleicht Wortmengen statt ganzer Texte, Reihenfolge egal. Je Eintrag dann:
 * `erlaubt: []` (Zusatzwörter, die stören dürfen — „Pfeffer schwarz gemahlen"), `enthaelt: true` (beliebige
 * Zusatzwörter). Ein Begriffs-Wort mit `*` am Ende trifft auch längere Wörter („pfeffer weiss*" trifft
 * „weisser pfeffer", aber „pfeffer" nicht „pfefferkoerner").
 */
final class Zuordnung implements RegelArt
{
    public function validiere(array $params): array
    {
        if (! isset($params['eintraege']) || ! is_array($params['eintraege']) || $params['eintraege'] === []) {
            return ['eintraege: mindestens ein Eintrag'];
        }
        $fehler = [];
        foreach ($params['eintraege'] as $i => $e) {
            if (! is_array($e) || trim((string) ($e['begriff'] ?? '')) === '' || trim((string) ($e['ziel_name'] ?? '')) === '') {
                $fehler[] = "eintraege[{$i}]: begriff und ziel_name nötig";
            }
            if (is_array($e) && ! in_array($e['ziel_typ'] ?? 'wert', ['gp', 'rezept', 'wert'], true)) {
                $fehler[] = "eintraege[{$i}]: ziel_typ gp|rezept|wert";
            }
            if (is_array($e)) {
                $fehler = [...$fehler, ...Bedingung::validiere($e['kontext'] ?? null)];
            }
        }

        return $fehler;
    }

    /**
     * Passender Eintrag für den ganzen Begriff `$text` oder null.
     *
     * @return array{begriff: string, ziel_typ: string, ziel_id: ?int, ziel_name: string}|null
     */
    public function finde(FoodAlchemistRule $regel, string $text, array $kontext = []): ?array
    {
        $n = RegelText::norm($text);
        if ($n === '') {
            return null;
        }
        $tokens = ($regel->params['vergleich'] ?? 'text') === 'tokens';
        $ohneKontext = null;
        foreach ((array) ($regel->params['eintraege'] ?? []) as $e) {
            $namen = [(string) ($e['begriff'] ?? ''), ...array_map('strval', (array) ($e['aliase'] ?? []))];
            $passt = false;
            foreach ($namen as $name) {
                if ($name !== '' && ($tokens ? $this->tokensPassen($name, $n, $e) : RegelText::norm($name) === $n)) {
                    $passt = true;
                    break;
                }
            }
            if (! $passt) {
                continue;
            }
            $treffer = ['begriff' => (string) $e['begriff'], 'ziel_typ' => (string) ($e['ziel_typ'] ?? 'wert'),
                'ziel_id' => isset($e['ziel_id']) ? (int) $e['ziel_id'] : null, 'ziel_name' => (string) $e['ziel_name']];
            if (! empty($e['kontext'])) {
                if (Bedingung::erfuellt((array) $e['kontext'], $kontext)) {
                    return $treffer;
                }
                continue;
            }
            $ohneKontext ??= $treffer;
        }

        return $ohneKontext;
    }

    /** Wortmengen-Vergleich: jedes Begriffs-Wort steckt im Text; Text-Wörter außerhalb nur, wenn erlaubt. */
    private function tokensPassen(string $begriff, string $normText, array $eintrag): bool
    {
        $soll = array_values(array_filter(explode(' ', RegelText::norm($begriff))));
        $ist = array_values(array_unique(array_filter(explode(' ', $normText))));
        $trifft = static fn (string $s, string $i) => str_ends_with($s, '*') ? str_starts_with($i, rtrim($s, '*')) : $i === $s;
        foreach ($soll as $s) {
            if (! array_filter($ist, static fn ($i) => $trifft($s, $i))) {
                return false;
            }
        }
        if ((bool) ($eintrag['enthaelt'] ?? false)) {
            return true;
        }
        $erlaubt = array_map(static fn ($w) => RegelText::norm((string) $w), (array) ($eintrag['erlaubt'] ?? []));
        foreach ($ist as $i) {
            if (! array_filter($soll, static fn ($s) => $trifft($s, $i)) && ! in_array($i, $erlaubt, true)) {
                return false;
            }
        }

        return true;
    }

    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        return [];
    }

    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
    {
        $e = $this->finde($regel, $text, $kontext);

        return $e !== null && $e['ziel_name'] !== trim($text) ? $e['ziel_name'] : null;
    }

    public function kannKorrigieren(): bool
    {
        return true;
    }
}
