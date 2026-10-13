<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\PlanPreisService;

/**
 * Spec 82 §13 D — Platzhalter-Startpreise (Basis 79, User 19, Unter-Team 50, Paket 149, Abo 199) für Bausteine ohne Preis.
 * Bewusst ein Befehl und keine Migration: ein Deploy soll keine Beträge setzen. Idempotent, fasst vorhandene Preise nie an.
 */
class PlanPreiseStartwerteCommand extends Command
{
    protected $signature = 'foodalchemist:plan-preise-startwerte';

    protected $description = 'Legt Platzhalter-Startpreise der Gebühren-Bausteine an (nur wo noch kein Preis steht)';

    public function handle(PlanPreisService $service): int
    {
        $angelegt = $service->startwerteAnlegen();
        $this->info($angelegt === [] ? 'Alle Bausteine haben schon einen Preis, nichts geändert.' : 'Startwerte angelegt für: '.implode(', ', $angelegt).' (Platzhalter, in der Verwaltung anpassen).');

        return self::SUCCESS;
    }
}
