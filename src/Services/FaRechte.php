<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamMemberRole;

/**
 * Spec 61 §5 · Eine Stelle für alle Rechte-Fragen im Food Alchemist.
 *
 * Geprüft wird in der Service-Schicht, nicht in der Oberfläche — damit gilt dieselbe Regel für
 * Livewire, MCP und Jobs. Ermittlung der Rolle (höchste gewinnt):
 *  1. Plattform-Admin (E-Mail in `platform-shell.admins`, falls die Host-App das liefert) → FA-Admin
 *  2. Inhaber/Admin (`team_user.role`) im Team oder einem Eltern-Team → FA-Admin
 *  3. gespeicherte FA-Rolle im Team oder einem Eltern-Team (vererbt sich nach unten, nie seitwärts)
 *  4. sonst Lesen
 * KI-Benutzer (`users.type = ai_user`) bekommen höchstens Kuratieren — die KI kann nie mehr als
 * ein Mensch freigeben (Spec 61 §5.3).
 *
 * Stand 2026-10-08: durchgesetzt im Wareneingang (Spec 75). Die übrigen Bereiche der
 * Rechte-Matrix (Spec 61 §3) hängen sich hier an, sobald sie umgestellt werden.
 */
class FaRechte
{
    public function rolle(?User $user, Team $team): FaRolle
    {
        if ($user === null) {
            return FaRolle::Lesen;
        }
        if ($this->istPlattformAdmin($user)) {
            return FaRolle::Admin;
        }
        $kette = $this->teamKette($team);
        if (! $user->isAiUser() && DB::table('team_user')->whereIn('team_id', $kette)->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])->exists()) {
            return FaRolle::Admin;
        }

        $rolle = FoodAlchemistTeamMemberRole::whereIn('team_id', $kette)->where('user_id', $user->id)->get()
            ->reduce(fn (FaRolle $max, $r) => FaRolle::max($max, $r->rolle), FaRolle::Lesen);

        if ($user->isAiUser()) {
            return $rolle->mindestens(FaRolle::Kuratieren) ? FaRolle::Kuratieren : $rolle;
        }

        return $rolle;
    }

    public function darf(?User $user, Team $team, FaRolle $mindestens): bool
    {
        return $user !== null && $this->rolle($user, $team)->mindestens($mindestens);
    }

    /** @throws FaRechtFehltException */
    public function pruefe(?User $user, Team $team, FaRolle $mindestens, string $wofuer): void
    {
        if ($user === null) {
            throw new FaRechtFehltException($mindestens, null, $wofuer);
        }
        $rolle = $this->rolle($user, $team);
        if (! $rolle->mindestens($mindestens)) {
            throw new FaRechtFehltException($mindestens, $rolle, $wofuer);
        }
    }

    /** Wie `pruefe`, mit User-ID (Services bekommen meist nur `?int $userId`). */
    public function pruefeId(?int $userId, Team $team, FaRolle $mindestens, string $wofuer): void
    {
        $this->pruefe($userId !== null ? User::find($userId) : null, $team, $mindestens, $wofuer);
    }

    /**
     * Mitglieder des Teams mit wirksamer FA-Rolle und woher sie kommt.
     *
     * @return list<array{user_id:int, name:string, email:string, team_rolle:?string, rolle:string, rolle_label:string, eigene_rolle:?string, quelle:string, aenderbar:bool, ki:bool}>
     */
    public function mitglieder(Team $team): array
    {
        $kette = $this->teamKette($team);
        $eigene = FoodAlchemistTeamMemberRole::where('team_id', $team->id)->get()->keyBy('user_id');
        $users = User::query()->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->where('team_user.team_id', $team->id)
            ->select('users.*', 'team_user.role as team_rolle')
            ->orderBy('users.name')->get();

        return $users->map(function (User $u) use ($team, $kette, $eigene) {
            $wirksam = $this->rolle($u, $team);
            $teamAdmin = ! $u->isAiUser() && DB::table('team_user')->whereIn('team_id', $kette)->where('user_id', $u->id)
                ->whereIn('role', ['owner', 'admin'])->exists();
            $quelle = match (true) {
                $this->istPlattformAdmin($u) => 'plattform',
                $teamAdmin => 'team_admin',
                $eigene->has($u->id) => 'eigen',
                $wirksam !== FaRolle::Lesen => 'geerbt',
                default => 'standard',
            };

            return [
                'user_id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'team_rolle' => $u->team_rolle,
                'rolle' => $wirksam->value,
                'rolle_label' => $wirksam->label(),
                'eigene_rolle' => $eigene->get($u->id)?->rolle?->value,
                'quelle' => $quelle,
                'aenderbar' => ! in_array($quelle, ['plattform', 'team_admin'], true),
                'ki' => $u->isAiUser(),
            ];
        })->values()->all();
    }

    /**
     * FA-Rolle eines Mitglieds setzen. Nur FA-Admins; Inhaber/Admins sind immer FA-Admin und
     * lassen sich hier nicht herabstufen; KI-Benutzer höchstens Kuratieren.
     */
    public function setzeRolle(Team $team, ?User $actor, int $userId, FaRolle $rolle): FoodAlchemistTeamMemberRole
    {
        $this->pruefe($actor, $team, FaRolle::Admin, 'Rollen vergeben');
        $ziel = User::find($userId);
        if ($ziel === null || ! DB::table('team_user')->where('team_id', $team->id)->where('user_id', $userId)->exists()) {
            throw new \RuntimeException('Diese Person ist kein Mitglied des Teams.');
        }
        if ($this->istPlattformAdmin($ziel) || (! $ziel->isAiUser() && DB::table('team_user')->whereIn('team_id', $this->teamKette($team))
            ->where('user_id', $userId)->whereIn('role', ['owner', 'admin'])->exists())) {
            throw new \RuntimeException('Inhaber und Admins des Teams sind immer FA-Admin. Ändern geht nur über die Team-Rolle in der Verwaltung.');
        }
        if ($ziel->isAiUser() && $rolle->rang() > FaRolle::Kuratieren->rang()) {
            throw new \RuntimeException('Ein KI-Benutzer bekommt höchstens die Rolle „Kuratieren“ — freigeben bleibt beim Menschen.');
        }

        return FoodAlchemistTeamMemberRole::updateOrCreate(
            ['team_id' => $team->id, 'user_id' => $userId],
            ['rolle' => $rolle, 'set_by' => $actor?->id],
        );
    }

    /** @return list<int> eigenes Team zuerst, dann alle Eltern bis zur Wurzel */
    private function teamKette(Team $team): array
    {
        $ids = [(int) $team->id];
        $parentId = $team->parent_team_id;
        $schutz = 0;
        while ($parentId !== null && $schutz++ < 20) {
            $ids[] = (int) $parentId;
            $parentId = Team::whereKey($parentId)->value('parent_team_id');
        }

        return $ids;
    }

    private function istPlattformAdmin(User $user): bool
    {
        $admins = (array) config('platform-shell.admins', []);

        return $admins !== [] && in_array(strtolower((string) $user->email), $admins, true);
    }
}
