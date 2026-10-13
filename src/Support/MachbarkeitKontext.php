<?php

namespace Platform\FoodAlchemist\Support;

use Carbon\CarbonInterface;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;

/**
 * Spec 82 §12 E — Ausführungskontext des Machbarkeits-Checks: Ziel-Team, berechtigter Benutzer, optional Termin und
 * Gästezahl. Dasselbe Foodbook kann für LAGO oder eine andere Küche geprüft werden. Gästezahl und Termin werden
 * mitgeführt, aber erst vom KontextResolver (Schritt 6) ausgewertet (Fläche gegen Pax); der Check nutzt sie heute nicht.
 */
final class MachbarkeitKontext
{
    public function __construct(
        public readonly Team $ziel,
        public readonly ?User $user = null,
        public readonly ?int $gaeste = null,
        public readonly ?CarbonInterface $termin = null,
    ) {}
}
