<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §12 B3 · Kosten und VK eines Gerichts für ein Team, in der festen Form, die die Einfrierung speichert. Eine Naht zur Kostenschicht
 * (KostenCacheService / TeamVkService, Spec 82 Schritt 3), damit die Einfrierung ihr Format nicht von deren Rückgabe abhängig macht und Tests
 * die Kosten festlegen können. Ohne vollständigen EK ist der VK NULL, nie 0 (Auflage 4).
 */
class EinfrierKosten
{
    /**
     * @return array{ek_summe: ?float, ek_per_kg: ?float, n_priced: ?int, n_total: ?int, vollstaendig: bool, grund: ?string, fehlende: list<array<string,mixed>>, vk: ?float, berechnet_at: ?string}
     */
    public function fuer(FoodAlchemistRecipe $gericht, Team $team): array
    {
        // Platzhalter bis die Kostenschicht (Schritt 3) auf main ist: ohne Kostenschicht gibt es für ein Team keinen belastbaren EK.
        return ['ek_summe' => null, 'ek_per_kg' => null, 'n_priced' => null, 'n_total' => null, 'vollstaendig' => false,
            'grund' => 'kostenschicht_fehlt', 'fehlende' => [], 'vk' => null, 'berechnet_at' => null];
    }
}
