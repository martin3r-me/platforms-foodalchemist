<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Services\Produktpass\ProduktdatenQuelle;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\KiVerursacher;

/**
 * Web-Recherche zum Lieferantenartikel im Hintergrund (Kurator 19.10.: synchron starb der Request am FPM-Timeout). Interaktive Schlange
 * wie das Produktpass-Lesen; Fehler fängt {@see ProduktpassService::rechercheAusfuehren()} (Status `fehler` mit Meldung), EIN Versuch.
 */
class ProduktpassRechercheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $produktpassId, public string $quelle)
    {
    }

    public function handle(ProduktpassService $svc): void
    {
        $pass = FoodAlchemistProduktpass::find($this->produktpassId);
        if ($pass === null) {
            return;
        }
        $quelle = null;
        foreach (app()->tagged('produktdaten.quellen') as $q) {
            if ($q instanceof ProduktdatenQuelle && $q->name() === $this->quelle) {
                $quelle = $q;
            }
        }
        if ($quelle === null) {
            $pass->update(['status' => 'fehler', 'fehler' => 'Die Websuche ist nicht eingerichtet.']);

            return;
        }
        KiVerursacher::imKontext($pass->team_id !== null ? (int) $pass->team_id : KiVerursacher::KONTEXT_PLATTFORM, fn () => $svc->rechercheAusfuehren($pass, $quelle));
    }

    /** Abbruch von außen (Timeout, Worker weg): der Pass bleibt nicht ewig auf „liest“. */
    public function failed(\Throwable $e): void
    {
        FoodAlchemistProduktpass::whereKey($this->produktpassId)->where('status', 'liest')
            ->update(['status' => 'fehler', 'fehler' => 'Die Web-Recherche wurde abgebrochen (Zeitlimit).']);
    }
}
