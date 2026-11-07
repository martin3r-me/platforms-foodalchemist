<?php

namespace Platform\FoodAlchemist\Services\Matching;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 96 · Rezeptzeilen, die den Teil einer Frucht nennen („Limette (Saft)“, „Saft von 2 Zitronen“, „Abrieb einer Orange“), gehen
 * auf das Derivat der Frucht („Limette: frisch, Saft“), wenn es im Bestand ist — nicht auf gekauften Saft. Nur Obst (Mutter WG 02):
 * „Tomatensaft“, „Karottensaft“ bleiben Produkte. „saft“ ist bewusst kein allgemeiner Nebenprodukt-Marker (MatchHeuristics, mehrdeutig).
 *
 * Das Kompositum „Limettensaft“ (ohne Mutter-Nennung) entscheidet die Leitplanke Eigenleistung (Dominique 06.11.): ohne Wahl /
 * from_scratch → Derivat (frisch gepresst), teil_/voll_convenience → gekaufter Saft (normaler Matcher). „frisch gepresst“ ist immer Derivat.
 * Kein Derivat im Bestand → null, der normale Matcher läuft wie bisher.
 */
final class FruchtDerivat
{
    private const TEIL = '(saft|abrieb|zesten?|schalen?)';

    /** @return array{frucht: string, teil: string, kompositum: bool, gepresst: bool}|null */
    public static function erkenne(string $text): ?array
    {
        $t = trim(mb_strtolower($text));
        $t = trim((string) preg_replace('/^(?:ca\.?\s*)?[\d.,\/½¼¾\s–-]*(?:(?:stk|st|stück|stueck|g|ml|el|tl)\.?\s+)?/u', '', $t));
        $gepresst = preg_match('/frisch\s+gepresst|ausgepresst|gepresst|entsaftet/u', $t) === 1;
        $t = trim((string) preg_replace('/\b(?:bio|frisch(?:e[rsn]?)?|unbehandelt(?:e[rsn]?)?|gepresste[rsn]?|frisch\s+gepresste[rsn]?)\b/u', ' ', $t));
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        // „Limette (Saft)“, „Limette, nur Saft“, „Limette, Saft und Abrieb“ (der erste Teil zählt), „Limette (ausgepresst)“
        if (preg_match('/^([\p{L}-]{3,})\s*(?:\(\s*(?:nur\s+)?(?:der\s+|die\s+)?' . self::TEIL . '\b[^)]*\)|,\s*(?:nur\s+)?(?:der\s+|die\s+)?' . self::TEIL . '\b.*|,?\s*\(?\s*(?:ausgepresst|gepresst|entsaftet)\s*\)?)$/u', $t, $m) === 1) {
            $teil = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : 'saft');

            return ['frucht' => $m[1], 'teil' => self::teil($teil), 'kompositum' => false, 'gepresst' => $gepresst];
        }
        // „Saft von 2 Limetten“, „Saft einer Zitrone“, „abgeriebene Schale von 1 Orange“
        if (preg_match('/^(?:abgeriebene\s+)?' . self::TEIL . '\s+(?:von|aus|einer|eines|der)\s+(?:(?:[\d.,½¼¾]+|einer?|zwei|drei|vier|halben?|1\/2)\s+)?([\p{L}-]{3,})/u', $t, $m) === 1) {
            return ['frucht' => $m[2], 'teil' => self::teil($m[1]), 'kompositum' => false, 'gepresst' => $gepresst];
        }
        // Kompositum „Limettensaft“, „Zitronenabrieb“, „Orangenzeste“
        if (preg_match('/^([\p{L}]{3,}?)' . self::TEIL . '$/u', $t, $m) === 1) {
            return ['frucht' => $m[1], 'teil' => self::teil($m[2]), 'kompositum' => true, 'gepresst' => $gepresst];
        }

        return null;
    }

    /**
     * Treffer auf das Frucht-Derivat oder null (normaler Matcher).
     *
     * @return array<string, mixed>|null
     */
    public static function treffer(Team $team, string $text, string $convenience = 'standard'): ?array
    {
        $e = self::erkenne($text);
        if ($e === null) {
            return null;
        }
        if ($e['kompositum'] && $e['teil'] === 'saft' && ! $e['gepresst'] && in_array($convenience, ['teil_convenience', 'voll_convenience'], true)) {
            return null;   // Leitplanke Convenience: gekaufter Saft
        }
        $gp = self::derivatFuer($team, $e['frucht'], $e['teil']);
        if ($gp === null) {
            return null;
        }
        $s = MatchHeuristics::DEFAULT_GP_ALIAS_SCORE;

        return ['target' => 'gp', 'status' => MatchBand::fuerScore($s), 'gp_id' => (int) $gp->id, 'gp_name' => (string) $gp->name,
            'recipe_id' => null, 'recipe_name' => null, 'score' => $s];
    }

    public static function derivatFuer(Team $team, string $frucht, string $teil): ?FoodAlchemistGp
    {
        $stamm = self::stamm($frucht);
        if (mb_strlen($stamm) < 3) {
            return null;
        }
        $kandidaten = FoodAlchemistGp::visibleToTeam($team)->where('is_derivat', true)->whereNotNull('derivat_von_gp_id')
            ->whereNotIn('status', ['rejected', 'merged'])
            ->with('derivedFrom:id,name,main_ingredient_slug,commodity_group_code,is_derivat')
            ->get(['id', 'name', 'status', 'derivat_von_gp_id', 'derivat_art', 'is_derivat']);
        $treffer = $kandidaten->filter(function (FoodAlchemistGp $d) use ($stamm, $teil) {
            $m = $d->derivedFrom;
            if ($m === null || $m->is_derivat || (string) $m->commodity_group_code !== '02') {
                return false;
            }
            $mutterName = trim((string) strstr((string) $m->name . ':', ':', true));
            if (self::stamm($mutterName) !== $stamm && self::stamm((string) $m->main_ingredient_slug) !== $stamm) {
                return false;
            }
            $form = self::norm((string) substr((string) $d->name, (int) strpos((string) $d->name . ':', ':') + 1));

            return $teil === 'saft' ? str_contains($form, 'saft') : (bool) preg_match('/abrieb|zeste|schale/u', $form);
        });

        return $treffer->sortBy(fn (FoodAlchemistGp $d) => [($d->status?->value ?? $d->status) === 'approved' ? 0 : 1, mb_strlen((string) $d->name)])->first();
    }

    private static function teil(string $t): string
    {
        return str_starts_with($t, 'saft') ? 'saft' : 'abrieb';   // Spec 95c: Abrieb = Schale = Zeste
    }

    private static function norm(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '-' => ' ', '_' => ' ']);
    }

    /** Limette/Limetten → limett, Zitrone/Zitronen → zitron, Blutorange → blutorang */
    private static function stamm(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', self::norm($s)));

        return (string) preg_replace('/(en|n|e|s)$/u', '', $s);
    }
}
