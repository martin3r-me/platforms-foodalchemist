<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KiExternSchrankeException;

/**
 * #1002 (P2a): Job-Rumpf im externen Modus (Sammel-Transaktion). Dieselbe Mechanik wie die Tool-Hülle (Support/KiUmleitung), nur
 * für Jobs mit Wiederholungs-Nutzlast: wartet der Rumpf auf eine Antwort, Rollback, Aufträge danach anlegen, `ki_auftraege.ANTWORT`
 * startet den Job neu. Antworten werden erst mit einem vollständigen Lauf verbraucht (der Verbrauchs-UPDATE steht in der Transaktion).
 */
class KiExternLauf
{
    public const FERTIG = 'fertig';

    public const WARTET = 'wartet';

    public const LAEUFT_BEREITS = 'laeuft_bereits';

    /**
     * @param  callable(): mixed  $arbeit  der Job-Rumpf
     * @return string FERTIG | WARTET | LAEUFT_BEREITS
     */
    public function fuehreAus(KiExternWiederholbar $job, callable $arbeit): string
    {
        if (! $job->externAktiv()) {
            $arbeit();                                              // Plattformweg: bytegleich, keine Transaktion, kein Sammel-Modus

            return self::FERTIG;
        }
        $teamId = $job->externTeamId();
        $key = $job->externSchluessel();
        // Ein laufender Lauf je Ziel: ein neuer Start (Versuch 0) findet einen wartenden/angestoßenen Lauf vor → kein zweiter
        if ($job->externVersuch() === 0 && app(KiAuftragService::class)->laufAktiv($teamId, $key)) {
            return self::LAEUFT_BEREITS;
        }

        $modus = app(KiExternModus::class);
        $db = DB::connection();
        $niveau = $db->transactionLevel();
        $db->beginTransaction();
        $fehler = null;
        try {
            $modus->withinSammelnd($arbeit, [
                'klasse' => $job::class, 'nutzlast' => $job->externNutzlast(), 'versuch' => $job->externVersuch(), 'lauf_key' => $key,
            ]);
        } catch (\Throwable $e) {
            $fehler = $e;
        }
        $merker = $modus->entnimm();

        if ($merker['wartet'] === []) {
            if ($fehler !== null) {
                $db->rollBack($niveau);

                throw $fehler;
            }
            $db->commit();

            return self::FERTIG;
        }

        $db->rollBack($niveau);
        $max = (int) config('foodalchemist.ai.extern_max_wiederholungen', 30);
        if ($job->externVersuch() >= $max) {
            throw new KiExternSchrankeException("Externer Lauf [{$key}] hat die Wiederholungs-Schranke ({$max}) erreicht — der Fingerabdruck der KI-Aufträge ist instabil "
                .'(ki_auftraege-Zähler „doppelt“ prüfen) oder der Lauf hat mehr KI-Glieder als erwartet.');
        }
        app(KiAuftragService::class)->legeAn($merker['vorgemerkt']);

        return self::WARTET;
    }
}
