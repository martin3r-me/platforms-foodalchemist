<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;

/**
 * Produktpass lesen (PDF/Foto → Extrakt → direkte Felder). Läuft auf der INTERAKTIVEN Schlange (Rückfall default,
 * {@see \Platform\FoodAlchemist\Support\Warteschlange::interaktiv()}): ein Mensch wartet auf das Ergebnis, der Job darf nicht hinter Massenläufen stehen. Fehler fängt {@see ProduktpassService::lesen()}
 * selbst (Status `fehler` mit Meldung), der Job bricht deshalb nie mit einer Ausnahme ab.
 */
class ProduktpassLesenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $produktpassId)
    {
    }

    public function handle(ProduktpassService $svc): void
    {
        $pass = FoodAlchemistProduktpass::find($this->produktpassId);
        if ($pass === null || in_array($pass->status, ['verworfen', 'gelesen'], true)) {
            return;   // gelöscht/verworfen oder schon gelesen (doppelter Dispatch) → nichts tun
        }
        $svc->lesen($pass);
    }
}
