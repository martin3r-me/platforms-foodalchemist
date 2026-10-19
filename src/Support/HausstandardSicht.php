<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\Arten\Schwelle;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;

/**
 * Spec 85 D-7 PR 2 · Sicht auf die übersteuerbaren Regeln eines Teams — EINE Darstellung für die Einstellungs-Sektion
 * „Hausstandards“ und `rules.GET ebene=team`: globaler Wert, wirksamer Wert, Herkunft (global / Oberteam / eigen), Untergrenze.
 */
final class HausstandardSicht
{
    /**
     * @return list<array{schluessel: string, titel: string, art: string, regelwerk: string, wert_global: string, wert_wirksam: string,
     *     herkunft: string, herkunft_team: ?string, eigen: bool, untergrenze: ?string, nur_strenger: bool, global_neuer: bool,
     *     params_global: array, params_wirksam: array}>
     */
    public static function zeilen(Team $team): array
    {
        $out = [];
        foreach (RegelBuch::UEBERSTEUERBAR as $s) {
            $global = RegelBuch::global($s);
            if ($global === null) {
                continue;   // ohne aktive globale Regel gibt es nichts zu übersteuern
            }
            $wirksam = RegelBuch::falls($s, $team) ?? $global;
            $gp = (array) $global->params;
            $herkunftTeam = $wirksam->team_id !== null ? Team::find((int) $wirksam->team_id)?->name : null;
            $out[] = [
                'schluessel' => $s, 'titel' => (string) $global->titel, 'art' => (string) $global->art, 'regelwerk' => (string) $global->regelwerk,
                'wert_global' => self::kurz($global), 'wert_wirksam' => self::kurz($wirksam),
                'herkunft' => RegelBuch::herkunft($wirksam), 'herkunft_team' => $herkunftTeam,
                'eigen' => (int) $wirksam->team_id === (int) $team->id,
                'untergrenze' => $global->art === 'schwelle' ? Schwelle::untergrenzeText($gp) : null,
                'nur_strenger' => $global->art === 'schwelle' && Schwelle::untergrenzeText($gp) === null,
                'global_neuer' => $wirksam->team_id !== null && $global->updated_at !== null && $wirksam->updated_at !== null
                    && $global->updated_at->gt($wirksam->updated_at),
                'params_global' => $gp, 'params_wirksam' => (array) $wirksam->params,
            ];
        }

        return $out;
    }

    /** Kurzform des Werts: Schwelle als „> 75 °C, 2 min“, Zuordnung als „Öl → Rapsöl: raffiniert, … (12 Einträge)“. */
    public static function kurz(FoodAlchemistRule $r): string
    {
        $p = (array) $r->params;
        if ($r->art === 'schwelle') {
            return Schwelle::text($p);
        }
        if ($r->art === 'zuordnung') {
            $e = (array) ($p['eintraege'] ?? []);
            $vorn = array_map(fn ($x) => ($x['begriff'] ?? '?') . ' → ' . ($x['ziel_name'] ?? '?'), array_slice($e, 0, 3));

            return implode(', ', $vorn) . (count($e) > 3 ? ', …' : '') . ' (' . count($e) . ' Einträge)';
        }

        return (string) json_encode($p, JSON_UNESCAPED_UNICODE);
    }
}
