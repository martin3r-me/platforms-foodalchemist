<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

/**
 * Spec 94b §5 · Aufteilung eines Preises pro Person nach Gruppen (Speisen Tag, Abend, Extras, je Leistungskategorie) — eine
 * Stelle für die interne Zusammenfassung (Reiter Kalkulation) und die Kundensicht („Eure Zusammenstellung“).
 *
 * Reihenfolge: Speisen Tag, Abend, Extras, dann die Kategorien in der Reihenfolge des Vokabulars. Gruppen mit 0 € fallen weg.
 * Rundung: jede Gruppe auf Cent, ein Rest (Cent-Rundung und Preisrundung des Konfigurators) geht in die größte Gruppe —
 * die Summe ist exakt der Preis pro Person. Reine Rechnung ohne Datenbank.
 */
final class KonfiguratorAufteilung
{
    public const SPEISEN = ['speisen_tag' => 'Speisen Tag', 'speisen_abend' => 'Speisen Abend', 'speisen_extras' => 'Speisen Extras', 'getraenke' => 'Getränke'];

    /**
     * @param  list<array{art:string, slug:?string, vk_pp:float, ek_pp:float}>  $gruppen  aus KonfiguratorPricingService::rechneFall
     * @param  float  $summe  Preis pro Person, auf den die Teile aufgehen (gerundeter Paketpreis)
     * @param  array<string, string>  $namen  Kategorie-Slug → Name, in Vokabular-Reihenfolge
     * @return list<array{art:string, slug:?string, name:string, vk_pp:float, ek_pp:float}>
     */
    public static function aufteilen(array $gruppen, float $summe, array $namen): array
    {
        $rang = array_flip(array_merge(array_keys(self::SPEISEN), array_map(fn ($s) => 'k:' . $s, array_keys($namen))));
        $zeilen = [];
        foreach ($gruppen as $g) {
            if (abs($g['vk_pp']) < 0.005 && abs($g['ek_pp']) < 0.005) {
                continue;
            }
            $schluessel = $g['art'] === 'kategorie' ? 'k:' . $g['slug'] : $g['art'];
            $zeilen[] = ['art' => $g['art'], 'slug' => $g['slug'], 'name' => $g['art'] === 'kategorie' ? ($namen[$g['slug']] ?? (string) $g['slug']) : (self::SPEISEN[$g['art']] ?? $g['art']),
                'vk_pp' => round($g['vk_pp'], 2), 'ek_pp' => round($g['ek_pp'], 2), 'rang' => $rang[$schluessel] ?? PHP_INT_MAX];
        }
        usort($zeilen, fn ($a, $b) => $a['rang'] <=> $b['rang']);
        if ($zeilen !== []) {
            $rest = round(round($summe, 2) - array_sum(array_column($zeilen, 'vk_pp')), 2);
            if (abs($rest) >= 0.005) {
                $groesste = array_keys(array_column($zeilen, 'vk_pp'), max(array_column($zeilen, 'vk_pp')))[0];
                $zeilen[$groesste]['vk_pp'] = round($zeilen[$groesste]['vk_pp'] + $rest, 2);
            }
        }

        return array_map(fn ($z) => array_diff_key($z, ['rang' => true]), array_values(array_filter($zeilen, fn ($z) => abs($z['vk_pp']) >= 0.005 || abs($z['ek_pp']) >= 0.005)));
    }
}
