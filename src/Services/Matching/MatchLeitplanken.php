<?php

namespace Platform\FoodAlchemist\Services\Matching;

/**
 * Rezept-Leitplanken → Matcher-Parameter (GL-04-Hooks). EINE Abbildung für Generator, Rezept-Import und den Mint-/Bestands-Pfad
 * (Kurator 27.10.: die Leitplanken der Planungs-Session kamen im Import und in der Anreicherung nie an). Vorher lebte sie inline in
 * RecipeGeneratorService::generiere.
 *
 *  - convenience (from_scratch | teil_convenience | standard) → Pool-Modus und Rohware-Vorrang (A-1)
 *  - frische (frisch | tk | konserve | behandelt) → Zustands-Präferenz; ohne Angabe frisch zuerst
 *  - bio_pref (bio | conventional | neutral) gewinnt; sonst der Bool `bio` (MCP-Pfad, 4.4r); ohne Angabe konventionell
 *  - zubereitung (eigen | zukauf | ohne, Dominique 29.10.): für jede Basiskomponente (Fond, Sauce, Gel, Pesto, „Öl:“ …; Zukauf nur mit GP im Bestand) —
 *    Eigenleistung zuerst (from_scratch → eigen, teil/voll_convenience → zukauf), sonst Niveau (haute_cuisine/gehoben → eigen,
 *    klassisch → zukauf); ohne beides gilt {@see OHNE_VORGABE}
 */
final class MatchLeitplanken
{
    /** Weg ohne Leitplanke (Eigenleistung/Niveau) — die EINE Stelle (Kurator 29.10.: „Geflügelfond ist meistens ein Unterrezept“). */
    public const OHNE_VORGABE = 'eigen';

    /** @return array{convenience: string, mode: string, preferRaw: bool, pref: string, bio: string, zubereitung: string} */
    public static function aus(array $parameter, bool $vkModus = false): array
    {
        $convenience = (string) ($parameter['convenience'] ?? 'standard');

        return [
            'convenience' => $convenience,
            'mode' => $vkModus || in_array($convenience, ['from_scratch', 'teil_convenience'], true) ? 'sub_recipe_first' : 'gp_first',
            'preferRaw' => $convenience === 'from_scratch',
            'pref' => match ($parameter['frische'] ?? null) {
                'tk' => 'frozen_first',
                'konserve', 'behandelt' => 'preserved_first',   // behandelt = gepökelt/geräuchert/luftgetrocknet (v3.6 §9)
                default => 'fresh_first',
            },
            // Bio dreiwertig: „egal“ ⇒ neutral (Adjustment 0), damit Bio-GPs nicht ungewollt bestraft werden
            'bio' => match ($parameter['bio_pref'] ?? null) {
                'bio' => 'bio',
                'neutral' => 'neutral',
                'conventional' => 'conventional',
                default => ($parameter['bio'] ?? false) ? 'bio' : 'conventional',
            },
            'zubereitung' => self::zubereitung($parameter),
        ];
    }

    /** eigen | zukauf | ohne — Eigenleistung (convenience) vor Niveau (level, Alt-Key niveau). */
    public static function zubereitung(array $parameter): string
    {
        return match ($parameter['convenience'] ?? null) {
            'from_scratch' => 'eigen',
            'teil_convenience', 'voll_convenience' => 'zukauf',
            default => match ($parameter['level'] ?? $parameter['niveau'] ?? null) {
                'haute_cuisine', 'gehoben' => 'eigen',
                'klassisch' => 'zukauf',
                default => 'ohne',
            },
        };
    }
}
