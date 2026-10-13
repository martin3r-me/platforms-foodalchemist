<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;

/**
 * Ein Unter-Team anlegen und für FoodAlchemist freischalten — eine Stelle für das Onboarding-Kommando und die Lizenz-Übernahme
 * (Spec 82 §9). Das Oberteam eines Mandanten ist immer ein vorhandenes Host-Team; das Modul legt nur Unter-Teams darunter an.
 * Läuft in der Transaktion des Aufrufers (kein eigenes Commit).
 */
class TeamAnlegenService
{
    /**
     * Kind-Team in der Hierarchie (Kette aufwärts = Katalog sofort sichtbar, D1), Owner als Admin, Modul freigeschaltet.
     *
     * @throws \RuntimeException wenn das Modul in der modules-Tabelle fehlt
     */
    public function unterTeam(Team $parent, string $name, ?int $userId = null): Team
    {
        $modul = DB::table('modules')->where('key', 'foodalchemist')->first();
        if ($modul === null) {
            throw new \RuntimeException('Modul foodalchemist ist nicht registriert (modules-Tabelle) — App einmal booten.');
        }
        $userId ??= (int) $parent->user_id;

        $team = Team::create(['name' => $name, 'user_id' => $userId, 'personal_team' => false, 'parent_team_id' => $parent->id]);
        DB::table('team_user')->insertOrIgnore([
            'team_id' => $team->id, 'user_id' => $userId, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('modulables')->insertOrIgnore([
            'module_id' => $modul->id, 'modulable_type' => get_class($team), 'modulable_id' => $team->id,
            'enabled' => 1, 'team_id' => $team->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $team;
    }
}
