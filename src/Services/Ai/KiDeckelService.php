<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Exceptions\KiBudgetErschoepftException;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;
use Platform\FoodAlchemist\Exceptions\KiDeckelProjektException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamKontingent;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\KiVerursacher;

/**
 * KI-Monatsdeckel je Mandant (Spec 77b-Budget + Entscheid Dominique 23.10.2026) und die EINE Sperr-Prüfung vor jedem KI-Aufruf.
 *
 * Wer zahlt, bestimmt dieselbe Auflösung wie das Kosten-Log ({@see KiVerursacher::aufloesen}): Deckel, Anzeige und Rechnung
 * zählen damit dieselben Zeilen. Verbrauch = eingefrorene Kosten (`kosten_usd × usd_eur`) der Zeilen des laufenden Monats mit
 * `abrechnung_team_id` = Mandant und `verursacher = mandant` — inklusive Fehlläufe (Anbieter-Tokens kosten).
 *
 * Wirksamer Deckel: „unbegrenzt“ am Kontingent → keiner; Betrag am Kontingent → dieser; sonst Mandant mit Status „projekt“ oder
 * ohne Mandanten-Zeile → `foodalchemist.ai.projekt_deckel_eur`; sonst (aktiv, beendet) keiner. Plattform-Läufe und das
 * Master-Team (verursacher plattform) sind nie gedeckelt. Embeddings stehen nicht im Log und zählen deshalb nicht mit.
 */
class KiDeckelService
{
    public const QUELLE_UNBEGRENZT = 'unbegrenzt';

    public const QUELLE_KONTINGENT = 'kontingent';

    public const QUELLE_PROJEKT = 'projekt_standard';

    public const QUELLE_OHNE = 'ohne';

    /** @return array{eur: ?float, quelle: string} */
    public function deckel(int $mandantId): array
    {
        $k = FoodAlchemistTeamKontingent::where('team_id', $mandantId)->first();
        if ($k !== null && Schema::hasColumn('foodalchemist_team_kontingente', 'ki_deckel_unbegrenzt') && (bool) $k->ki_deckel_unbegrenzt) {
            return ['eur' => null, 'quelle' => self::QUELLE_UNBEGRENZT];
        }
        if ($k?->ki_budget_eur_monat !== null) {
            return ['eur' => (float) $k->ki_budget_eur_monat, 'quelle' => self::QUELLE_KONTINGENT];
        }
        $status = FoodAlchemistMandant::where('team_id', $mandantId)->value('status');
        if ($status === null || $status === 'projekt') {
            return ['eur' => round((float) config('foodalchemist.ai.projekt_deckel_eur', 20), 2), 'quelle' => self::QUELLE_PROJEKT];
        }

        return ['eur' => null, 'quelle' => self::QUELLE_OHNE];
    }

    /** Eingefrorene KI-Kosten des Mandanten im laufenden Monat, in Euro. */
    public function verbrauchMonat(int $mandantId): float
    {
        if (! Schema::hasColumn('foodalchemist_ai_call_log', 'abrechnung_team_id')) {
            return 0.0;
        }

        return round((float) DB::table('foodalchemist_ai_call_log')
            ->where('abrechnung_team_id', $mandantId)->where('verursacher', KiVerursacher::MANDANT)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('kosten_usd')->whereNotNull('usd_eur')
            ->sum(DB::raw('kosten_usd * usd_eur')), 4);
    }

    /** Deckel erreicht? (für Anzeige und Prüfung) */
    public function erschoepft(int $mandantId): bool
    {
        $d = $this->deckel($mandantId);

        return $d['eur'] !== null && $this->verbrauchMonat($mandantId) >= $d['eur'];
    }

    /**
     * Die Sperr-Prüfung vor jedem KI-Aufruf: Kill-Switch des Teams, dann Monatsdeckel des zahlenden Mandanten.
     *
     * @param  array<string, mixed>  $verursacherOptionen  wie beim Log (verursacher_team_id | verursacher)
     *
     * @throws KiDeaktiviertException
     * @throws KiBudgetErschoepftException
     */
    public function pruefe(array $verursacherOptionen = []): void
    {
        $team = FaTeam::aktuell();
        if ($team !== null && ! app(TeamSettingsService::class)->kiAktiv($team)) {
            throw new KiDeaktiviertException();
        }
        $wer = KiVerursacher::aufloesen($verursacherOptionen);
        if ($wer['verursacher'] !== KiVerursacher::MANDANT || $wer['abrechnung_team_id'] === null) {
            return;
        }
        $d = $this->deckel((int) $wer['abrechnung_team_id']);
        if ($d['eur'] === null || $this->verbrauchMonat((int) $wer['abrechnung_team_id']) < $d['eur']) {
            return;
        }

        throw $d['quelle'] === self::QUELLE_PROJEKT ? new KiDeckelProjektException($d['eur']) : new KiBudgetErschoepftException();
    }
}
