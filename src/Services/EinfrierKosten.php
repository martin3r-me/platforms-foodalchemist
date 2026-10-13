<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §12 B3 · Kosten und VK eines Gerichts für ein Team, in der festen Form, die die Einfrierung speichert. Eine Naht zur Kostenschicht
 * ({@see KostenCacheService}, {@see TeamVkService}, Spec 82 Schritt 3), damit die Einfrierung ihr Format nicht von deren Rückgabe abhängig macht und Tests
 * die Kosten festlegen können. Ohne vollständigen EK ist der VK NULL, nie 0 (Auflage 4).
 */
class EinfrierKosten
{
    /**
     * @return array{ek_summe: ?float, ek_per_kg: ?float, n_priced: ?int, n_total: ?int, vollstaendig: bool, grund: ?string, fehlende: list<array<string,mixed>>, vk: ?float, berechnet_at: ?string}
     */
    public function fuer(FoodAlchemistRecipe $gericht, Team $team): array
    {
        $k = app(KostenCacheService::class)->fuer($gericht, $team);
        $vk = app(TeamVkService::class)->fuer($gericht, $team);

        return [
            'ek_summe' => $k['ek_summe'], 'ek_per_kg' => $k['ek_per_kg'], 'n_priced' => $k['n_priced'], 'n_total' => $k['n_total'],
            'vollstaendig' => (bool) $k['vollstaendig'], 'grund' => $k['grund'], 'fehlende' => $k['fehlende'],
            'vk' => $k['vollstaendig'] ? $vk['vk'] : null,   // ohne vollständigen EK nie ein VK (Auflage 4)
            'berechnet_at' => $k['berechnet_at'],
        ];
    }
}
