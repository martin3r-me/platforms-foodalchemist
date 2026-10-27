<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\GpAromaNachholen;

/**
 * 27.10.2026: Ein neues GP bekommt Kern-Anker und Geschmacksprofil — erst von einigen Geschwistern, sonst per KI-Job
 * ({@see GpAromaNachholen}). Fail-soft: eine Anlage scheitert nie am Aroma.
 */
class GpAromaObserver
{
    public function created(FoodAlchemistGp $gp): void
    {
        try {
            GpAromaNachholen::ausloesen($gp);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("GP-Aroma nach Anlage (GP {$gp->id}): {$e->getMessage()}");
        }
    }
}
