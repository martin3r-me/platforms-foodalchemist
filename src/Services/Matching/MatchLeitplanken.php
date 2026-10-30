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

    /** Vorgabe-Werte am Rezept (Spalten vorgabe_convenience / vorgabe_niveau, 29.10.) — dieselben Werte wie in der Planung. */
    public const EIGENLEISTUNG = ['from_scratch', 'teil_convenience', 'voll_convenience'];

    public const NIVEAU = ['haute_cuisine', 'gehoben', 'klassisch'];

    private const VORGABE_SPALTEN = ['vorgabe_convenience' => self::EIGENLEISTUNG, 'vorgabe_niveau' => self::NIVEAU];

    /** Beim Anlegen: Vorgabe-Spalten aus den Leitplanken der Planung (ungültige Werte → keine Vorgabe). */
    public static function vorgabeAus(array $parameter): array
    {
        $conv = $parameter['convenience'] ?? null;
        $niveau = $parameter['level'] ?? $parameter['niveau'] ?? null;

        return ['vorgabe_convenience' => in_array($conv, self::EIGENLEISTUNG, true) ? $conv : null,
            'vorgabe_niveau' => in_array($niveau, self::NIVEAU, true) ? $niveau : null];
    }

    /**
     * Editor/MCP: nur mitgesandte Vorgabe-Spalten, '' = keine Vorgabe; ein unbekannter Wert ist ein Fehler (nie still verworfen).
     *
     * @return array<string, ?string>
     */
    public static function vorgabeFelder(array $in): array
    {
        $out = [];
        foreach (self::VORGABE_SPALTEN as $spalte => $erlaubt) {
            if (! array_key_exists($spalte, $in)) {
                continue;
            }
            $wert = $in[$spalte] === null ? '' : trim((string) $in[$spalte]);
            if ($wert !== '' && ! in_array($wert, $erlaubt, true)) {
                throw new \RuntimeException("Unbekannte Vorgabe {$spalte}: „{$wert}“ (erlaubt: " . implode(', ', $erlaubt) . ').');
            }
            $out[$spalte] = $wert === '' ? null : $wert;
        }

        return $out;
    }

    /** Leitplanken des Rezepts für die Weiche — die Weiche liest am Rezept NUR diese (nie die Planung). */
    public static function ausRezept(object $recipe): array
    {
        return array_filter(['convenience' => $recipe->vorgabe_convenience ?? null, 'level' => $recipe->vorgabe_niveau ?? null]);
    }

    /** Matcher-Argumente (convenience, zubereitung) aus der Rezept-Vorgabe — für Läufe an einem bestehenden Rezept. */
    public static function matcherArgsFuerRezept(object $recipe): array
    {
        $lp = self::aus(self::ausRezept($recipe));

        return ['convenience' => $lp['convenience'], 'zubereitung' => $lp['zubereitung']];
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
