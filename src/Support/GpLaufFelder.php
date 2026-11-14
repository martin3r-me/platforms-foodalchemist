<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * Feldsatz der GP-Lauf-Pläne (Kurator 14.11.2026): welche GP-Spalten ein Plan setzen darf (`foodalchemist:gp-felder`, Spalte `neu_felder` in
 * `foodalchemist:gp-umhaengen`), wie sie auf die Eingabe von {@see GpNamingService::updateGp}/`createGp` abgebildet werden (gleiche Schlüssel wie
 * MCP `gps.PUT`) und welche Werte zulässig sind. Eine Stelle für beide Kommandos.
 */
final class GpLaufFelder
{
    /** Plan-Feld (GP-Spalte) => Schlüssel der Naming-Eingabe; `null` = kein Namensfeld, wird direkt geschrieben. */
    public const FELDER = [
        'main_ingredient_display' => 'hauptzutat',
        'processing' => 'processing',
        'form' => 'form',
        'flavor' => 'flavor',
        'zusatz' => 'zusatz',
        'pflichtangabe' => 'pflichtangabe',
        'portion' => 'portion',
        'condition' => 'condition',
        'bio' => 'bio',
        'rezepturware' => 'rezepturware',
        'namens_vegan' => 'vegan',
        'namens_glutenfrei' => 'glutenfrei',
        'namens_laktosefrei' => 'laktosefrei',
        'herkunft_land' => 'herkunft_land',
        'namens_msc' => 'msc',
        'namens_asc' => 'asc',
        'angabe_status' => 'angabe_status',
        'angabe_grund' => 'angabe_grund',
        'commodity_group_code' => 'commodity_group_code',
        'sub_category' => 'sub_category',
        'piece_default_g' => null,
    ];

    /** Felder mit Wahrheitswert (true/false; bei rezepturware zusätzlich null = nicht entschieden). */
    private const BOOL = ['bio', 'rezepturware', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'namens_msc', 'namens_asc'];

    /** Plan-Felder → Naming-Eingabe (nur Namens-/Strukturfelder; piece_default_g bleibt draußen). @param array<string,mixed> $felder */
    public static function alsEingabe(array $felder): array
    {
        $in = [];
        foreach ($felder as $feld => $wert) {
            $schluessel = self::FELDER[$feld] ?? null;
            if ($schluessel !== null) {
                $in[$schluessel] = in_array($feld, self::BOOL, true) && $feld !== 'rezepturware' ? (bool) $wert : $wert;
            }
        }

        return $in;
    }

    /**
     * Werte prüfen (ohne Namensregeln — die prüft validateGpName). Leer = alles zulässig.
     *
     * @param  array<string,mixed>  $felder
     * @param  string  $wg  Warengruppe, in der das GP nach dem Plan steht (für sub_category)
     * @param  ?int  $teamId  Team für team-eigene Untergruppen (globale Liste gilt immer)
     * @return list<string>
     */
    public static function pruefe(array $felder, string $wg, ?int $teamId): array
    {
        $fehler = [];
        foreach ($felder as $feld => $wert) {
            if (! array_key_exists($feld, self::FELDER)) {
                $fehler[] = "Feld »{$feld}« nicht erlaubt (erlaubt: " . implode(', ', array_keys(self::FELDER)) . ')';

                continue;
            }
            if ($wert !== null && ! is_scalar($wert)) {
                $fehler[] = "Feld »{$feld}«: nur Text, Zahl, true/false oder null";

                continue;
            }
            if (in_array($feld, self::BOOL, true) && $wert !== null && ! is_bool($wert)) {
                $fehler[] = "Feld »{$feld}«: true/false erwartet";
            }
            if (in_array($feld, ['bio', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'namens_msc', 'namens_asc'], true) && $wert === null) {
                $fehler[] = "Feld »{$feld}«: true/false erwartet (null nicht erlaubt)";
            }
        }
        if (array_key_exists('condition', $felder) && trim((string) $felder['condition']) !== '') {
            $z = app(GpNamingService::class)->normalisiereZustand((string) $felder['condition']);
            if (! in_array($z, GpNamingService::ZUSTAND_VOCAB, true)) {
                $fehler[] = "condition »{$felder['condition']}« nicht im §9-Vokabular (" . implode('/', GpNamingService::ZUSTAND_VOCAB) . ')';
            }
        }
        if (array_key_exists('angabe_status', $felder)) {
            try {
                AngabeKennzeichen::status($felder['angabe_status']);
            } catch (\RuntimeException $e) {
                $fehler[] = $e->getMessage();
            }
        }
        if (array_key_exists('piece_default_g', $felder) && $felder['piece_default_g'] !== null
            && (! is_numeric($felder['piece_default_g']) || (float) $felder['piece_default_g'] <= 0)) {
            $fehler[] = 'piece_default_g: Zahl > 0 oder null erwartet';
        }
        if (array_key_exists('commodity_group_code', $felder)
            && ! DB::table('foodalchemist_lookup_commodity_groups')->where('code', (string) $felder['commodity_group_code'])->exists()) {
            $fehler[] = "Warengruppe »{$felder['commodity_group_code']}« existiert nicht";
        }
        if (array_key_exists('sub_category', $felder) && trim((string) $felder['sub_category']) !== '' && ! self::untergruppeVerwaltet($wg, (string) $felder['sub_category'], $teamId)) {
            $fehler[] = "Untergruppe »{$felder['sub_category']}« steht nicht in der verwalteten Liste der WG {$wg}";
        }

        return $fehler;
    }

    /** Steht die Untergruppe in der verwalteten Liste der WG (global oder team-eigen, nicht gelöscht)? */
    public static function untergruppeVerwaltet(string $wg, string $name, ?int $teamId): bool
    {
        return DB::table('foodalchemist_commodity_group_subcategories')
            ->where('commodity_group_code', $wg)->where('name', trim($name))->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->when($teamId !== null, fn ($t) => $t->orWhere('team_id', $teamId)))
            ->exists();
    }
}
