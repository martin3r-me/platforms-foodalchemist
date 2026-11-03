<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 94c · Fester Alkohol-Wert am GP (`gps.alkohol` ja / nein / offen, alle Warengruppen), gepflegt im Modal und über gps.POST/PUT; er hat
 * Vorrang vor den Artikeldaten (GpAggregateService::enthaeltAlkohol). Die Regeln unten dienen NUR der einmaligen Vorbelegung der Getränke
 * (foodalchemist:alkohol-vorbelegen, WG 15), aus Struktur, nie aus Namensraterei — das Erste, was zutrifft (Kurator 03.11.2026):
 *  1. vol-% in Pflichtangabe bzw. Name (GP-Regelwerk §7.1a/§8: „Pils: 4,9 vol-%“; tolerant gegen „Vol%“, „vol.-%“; „vol“ nur mit Zahl)
 *     oder „alkoholfrei“: ≥ 0,5 = ja, sonst nein. Die Pflichtangabe schlägt die Unterkategorie (alkoholfreies Bier in 15.2 = nein).
 *  2. Unterkategorie 15.5 Wasser, 15.6 Säfte, 15.7 Erfrischungsgetränke, 15.8 Heißgetränke, 15.9 Sirupe = nein (Coca-Cola ohne vol-%).
 *  3. Unterkategorie 15.1 Wein, 15.3 Spirituosen und Liköre = ja, auch ohne vol-% (Kochwein).
 *  4. Sonst (z. B. 15.2 Bier ohne vol-%, wegen Alkoholfrei-Varianten) keine Aussage. Außerhalb von WG 15 nie automatisch (Kochwein in WG 10,
 *     Mirin, Rum-Aroma): die stehen nur als Vorschlag zur Freigabe in der Liste des Befehls.
 */
final class AlkoholAngabe
{
    public const WG_GETRAENKE = '15';

    /** Grenze für „alkoholfrei“ (vol-% bzw. g/100), darunter gilt ein gemessener bzw. angegebener Wert als nein. */
    public const GRENZE = 0.5;

    /** GP-Regelwerk §3: Unterkategorien der WG 15 ohne bzw. mit Alkohol (Code-Präfix der sub_category). */
    public const SUB_OHNE = ['15.5', '15.6', '15.7', '15.8', '15.9'];

    public const SUB_MIT = ['15.1', '15.3'];

    /** Eingabe ja | nein | offen (MCP) bzw. 1 | 0 | '' (Formular) → true | false | null. */
    public static function eingabe(mixed $wert): ?bool
    {
        return match (is_string($wert) ? mb_strtolower(trim($wert)) : $wert) {
            true, 1, '1', 'ja', 'true' => true,
            false, 0, '0', 'nein', 'false' => false,
            default => null,
        };
    }

    /** vol-% aus einem Text, null ohne Angabe; „alkoholfrei“ = 0. */
    public static function volProzent(?string $text): ?float
    {
        $text = (string) $text;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*vol\.?\s*-?\s*%/iu', $text, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return preg_match('/(?<![\p{L}])alkoholfrei(?![\p{L}])/iu', $text) === 1 ? 0.0 : null;
    }

    /** true / false für einen GP der WG 15 (Pflichtangabe bzw. Name, dann Unterkategorie); null ohne Aussage. */
    public static function ausGp(?string $warengruppe, ?string $pflichtangabe, ?string $name, ?string $unterkategorie = null): ?bool
    {
        if ((string) $warengruppe !== self::WG_GETRAENKE) {
            return null;
        }
        $vol = self::volProzent($pflichtangabe) ?? self::volProzent($name);
        if ($vol !== null) {
            return $vol >= self::GRENZE;
        }
        $sub = preg_match('/^\s*(\d+\.\d+)/', (string) $unterkategorie, $m) === 1 ? $m[1] : null;

        return match (true) {
            in_array($sub, self::SUB_OHNE, true) => false,
            in_array($sub, self::SUB_MIT, true) => true,
            default => null,
        };
    }
}
