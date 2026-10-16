<?php

namespace Platform\FoodAlchemist\Jobs\Middleware;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Support\FaTeam;

/**
 * Spec 84 A-3 · Ein Job läuft im Team seines Auftrags. Das Job-Feld `teamId` (Auftragsteam) gewinnt; FaTeam, KI-Riegel,
 * Call-Log und Abrechnung sehen es — egal, welches Team der angemeldete Nutzer inzwischen in der UI eingestellt hat
 * und ob der Worker noch einen alten Auth::login trägt.
 *
 * Seit A-1 trägt jeder Job zusätzlich das Versand-Team im Payload. Weichen beide ab, wurde der Job ohne FaTeam::imTeam für ein
 * anderes Team versandt — das ist der Fehler, den A-4 schließt. Bis dahin: Warnung ins Log, Auftragsteam gewinnt (kein Abbruch,
 * damit kein laufender Massenlauf stehen bleibt). Nach A-4 wird daraus eine Ausnahme.
 */
final class ImTeamKontext
{
    public function __construct(private readonly ?int $teamId) {}

    public function handle(object $job, callable $next): mixed
    {
        if ($this->teamId === null) {
            return $next($job);
        }
        $versand = Context::hasHidden(FaTeam::SCHLUESSEL) ? Context::getHidden(FaTeam::SCHLUESSEL) : null;
        // afterCommit-Jobs bauen ihren Payload erst beim Commit (außerhalb jedes imTeam-Rahmens) — dort ist die Abweichung erwartet
        if ($versand !== null && (int) $versand !== $this->teamId && ! ($job->afterCommit ?? false)) {
            Log::warning('Spec 84: Job-Team weicht vom Versand-Team ab (Versand ohne FaTeam::imTeam?)', [
                'job' => $job::class, 'auftragsteam' => $this->teamId, 'versandteam' => (int) $versand,
            ]);
        }

        return FaTeam::imTeam($this->teamId, fn () => $next($job));
    }
}
