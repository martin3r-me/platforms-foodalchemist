<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\AboService;

/**
 * Spec 84 B3 Regel B · Abo-Hüllen aller Mandanten nachts neu rechnen.
 *
 * Die Hülle enthält ältere Fassungen, solange etwas Eigenes des Mandanten auf sie verweist. Folge und Übernehmen rechnen sofort nach;
 * das Löschen einer verweisenden Zeile (Angebot, Speiseplan-Eintrag, …) nicht. Dann bliebe die alte Fassung zu lange sichtbar — harmlos
 * (eher zu viel als zu wenig), dieser Lauf räumt es nachts auf. Gemessen 17.10. (lokale Kopie, 21 Mandanten, 900 Fassungen,
 * 1.000 Verweise auf alte Fassungen): ≈ 3,1 s für alle, ≈ 150 ms je Mandant. Idempotent.
 */
class AboHuelleNachfuehrenCommand extends Command
{
    protected $signature = 'foodalchemist:abo-huelle-nachfuehren';

    protected $description = 'Spec 84 Regel B: Abo-Hüllen aller Mandanten neu rechnen (ältere Fassungen ohne Verweis fallen heraus).';

    public function handle(AboService $abos): int
    {
        $vorher = DB::table('foodalchemist_abo_objekte')->count();
        $start = microtime(true);
        $abos->neuBerechnenAlle();
        $this->info(sprintf('Abo-Hüllen neu gerechnet: %d → %d Zeilen in %d ms.', $vorher, DB::table('foodalchemist_abo_objekte')->count(),
            (int) round((microtime(true) - $start) * 1000)));

        return self::SUCCESS;
    }
}
