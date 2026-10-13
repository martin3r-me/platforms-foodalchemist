<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistPlattformEinstellung;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Spec 82 §13 B4 — Master-Team festlegen (nur Plattform-Admin).
 *
 * Ein Wechsel ändert die Sichtbarkeit aller Abos: erlaubt nur ohne Abos, sonst mit Wirkungs-Vorschau und ausdrücklicher
 * Bestätigung. Ohne Eintrag in der Tabelle gilt die ENV FOODALCHEMIST_MASTER_TEAM_ID (Fallback). Jede Änderung wird protokolliert.
 */
class MasterTeamService
{
    use PlattformAdminPruefung;

    /** Für lesende Aufrufer (MCP, Oberfläche): wirft FaRechtFehltException, wenn nicht Plattform-Admin. */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    public function __construct(private readonly AboAuskunft $abos, private readonly PlattformProtokoll $protokoll) {}

    /** @return array{team_id:?int, name:?string, quelle:string, env_team_id:?int} */
    public function aktuell(): array
    {
        $id = MasterTeam::id();

        return ['team_id' => $id, 'name' => $id !== null ? Team::whereKey($id)->value('name') : null, 'quelle' => MasterTeam::quelle(), 'env_team_id' => MasterTeam::ausEnv()];
    }

    /**
     * Was ein Wechsel auf `$neuesTeamId` (null = Eintrag entfernen, ENV gilt) bewirken würde.
     *
     * @return array{von:?int, nach:?int, abos:list<array<string,mixed>>, bestaetigung_noetig:bool, aendert_sich:bool}
     */
    public function vorschau(?int $neuesTeamId): array
    {
        $von = MasterTeam::id();
        $nach = $neuesTeamId ?? MasterTeam::ausEnv();
        $abos = $this->abos->abosAmMaster($von);

        return ['von' => $von, 'nach' => $nach, 'abos' => $abos, 'bestaetigung_noetig' => $abos !== [] && $von !== $nach, 'aendert_sich' => $von !== $nach];
    }

    /** `$neuesTeam` null = den gesetzten Wert entfernen (zurück auf ENV). */
    public function setze(?Team $neuesTeam, ?User $user, bool $bestaetigt = false): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Master-Team festlegen');
        $vorschau = $this->vorschau($neuesTeam?->id);
        if ($vorschau['bestaetigung_noetig'] && ! $bestaetigt) {
            throw new \RuntimeException(sprintf('Der Wechsel ändert die Sichtbarkeit von %d Abo(s). Bitte die Vorschau prüfen und ausdrücklich bestätigen.', count($vorschau['abos'])));
        }
        if (! $vorschau['aendert_sich'] && $neuesTeam !== null && MasterTeam::quelle() === 'einstellung') {
            return $vorschau;   // schon so gesetzt
        }

        DB::transaction(function () use ($neuesTeam, $admin) {
            if ($neuesTeam === null) {
                FoodAlchemistPlattformEinstellung::where('schluessel', FoodAlchemistPlattformEinstellung::MASTER_TEAM)->delete();
            } else {
                FoodAlchemistPlattformEinstellung::updateOrCreate(
                    ['schluessel' => FoodAlchemistPlattformEinstellung::MASTER_TEAM],
                    ['wert' => (string) $neuesTeam->id, 'gesetzt_von' => $admin->id],
                );
            }
        });
        MasterTeam::flush();
        StammPaket::flush();
        $this->protokoll->schreibe('master_team_gesetzt', ['von' => $vorschau['von'], 'nach' => MasterTeam::id(), 'abos_betroffen' => count($vorschau['abos']), 'bestaetigt' => $bestaetigt], $admin);

        return $vorschau;
    }
}
