<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamMemberFlag;

/**
 * Spec 61 §5 · Eine Stelle für alle Rechte-Fragen im Food Alchemist.
 *
 * Die Rolle kommt aus den Team-Einstellungen der Plattform (`team_user.role`, Core `StandardRole`) —
 * Entscheidung Dominique 2026-10-08: dort werden Bearbeiten/Lesen ohnehin gepflegt, das FA führt
 * keine zweite Rollenliste. Abbildung (höchste Rolle über Team + Eltern-Teams gewinnt, Rollen
 * vererben sich nach unten, nie seitwärts):
 *   Plattform-Admin (`platform-shell.admins`) · owner · admin → FA-Admin
 *   member  → Kuratieren (+ Freigeben, wenn das FA-Häkchen „darf Rechnungen freigeben" gesetzt ist)
 *   viewer  → Lesen
 *   kein Mitglied → Lesen
 * KI-Benutzer (`users.type = ai_user`) bekommen höchstens Kuratieren — freigeben bleibt beim Menschen.
 *
 * Geprüft wird in der Service-Schicht, nicht in der Oberfläche — dieselbe Regel für Livewire, MCP
 * und Jobs. Core wird nur gelesen, nie geschrieben (Spec 65 `istTeamAdmin` liest dieselbe Rolle).
 * Stand 2026-10-08 durchgesetzt im Wareneingang (Spec 75).
 */
class FaRechte
{
    public const PLATTFORM_ROLLEN = ['owner' => 'Inhaber', 'admin' => 'Admin', 'member' => 'Mitglied', 'viewer' => 'Betrachter'];

    private const RANG = ['viewer' => 1, 'member' => 2, 'admin' => 3, 'owner' => 4];

    public function rolle(?User $user, Team $team): FaRolle
    {
        if ($user === null) {
            return FaRolle::Lesen;
        }
        if (! $user->isAiUser() && $this->istPlattformAdmin($user)) {
            return FaRolle::Admin;
        }
        $kette = $this->teamKette($team);
        $plattform = $this->plattformRolle($user, $kette);
        $rolle = match ($plattform) {
            'owner', 'admin' => FaRolle::Admin,
            'member' => $this->darfFreigebenHaken($user, $kette) ? FaRolle::Freigeben : FaRolle::Kuratieren,
            default => FaRolle::Lesen,
        };
        if ($user->isAiUser() && $rolle->rang() > FaRolle::Kuratieren->rang()) {
            return FaRolle::Kuratieren;
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

    /**
     * Spec 77a: Prüfung für Services ohne User-Parameter — prüft den angemeldeten Benutzer (Web und MCP:
     * Core setzt dort `auth()->setUser`). Ohne angemeldeten Benutzer (Queue, Kommando, System) keine Prüfung.
     *
     * @throws FaRechtFehltException
     */
    public function pruefeAngemeldet(Team $team, FaRolle $mindestens, string $wofuer): void
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user instanceof User) {
            $this->pruefe($user, $team, $mindestens, $wofuer);
        }
    }

    /** Wie `pruefe`, mit User-ID (Services bekommen meist nur `?int $userId`). */
    public function pruefeId(?int $userId, Team $team, FaRolle $mindestens, string $wofuer): void
    {
        $this->pruefe($userId !== null ? User::find($userId) : null, $team, $mindestens, $wofuer);
    }

    /**
     * Mitglieder des Teams: Plattform-Rolle, daraus abgeleitete FA-Rolle, Freigabe-Häkchen.
     *
     * @return list<array{user_id:int, name:string, email:string, plattform_rolle:?string, plattform_rolle_label:string, rolle:string, rolle_label:string, darf_freigeben:bool, haken_moeglich:bool, ki:bool}>
     */
    public function mitglieder(Team $team): array
    {
        $kette = $this->teamKette($team);
        $users = User::query()->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->where('team_user.team_id', $team->id)->select('users.*')->orderBy('users.name')->get();

        return $users->map(function (User $u) use ($team, $kette) {
            $plattform = $this->plattformRolle($u, $kette);
            $rolle = $this->rolle($u, $team);

            return [
                'user_id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'plattform_rolle' => $plattform,
                'plattform_rolle_label' => self::PLATTFORM_ROLLEN[$plattform] ?? '—',
                'rolle' => $rolle->value,
                'rolle_label' => $rolle->label(),
                'darf_freigeben' => $rolle->mindestens(FaRolle::Freigeben),
                // Häkchen hat nur bei Mitgliedern Wirkung: Admins dürfen immer, Betrachter und KI nie.
                'haken_moeglich' => $plattform === 'member' && ! $u->isAiUser(),
                'ki' => $u->isAiUser(),
            ];
        })->values()->all();
    }

    /** „Darf Rechnungen freigeben" für ein Mitglied setzen — nur FA-Admin. */
    public function setzeFreigabe(Team $team, ?User $actor, int $userId, bool $darf): void
    {
        $this->pruefe($actor, $team, FaRolle::Admin, 'Freigaberecht vergeben');
        $ziel = User::find($userId);
        if ($ziel === null || ! DB::table('team_user')->where('team_id', $team->id)->where('user_id', $userId)->exists()) {
            throw new \RuntimeException('Diese Person ist kein Mitglied des Teams.');
        }
        if ($darf && $ziel->isAiUser()) {
            throw new \RuntimeException('Ein KI-Benutzer darf keine Rechnungen freigeben — freigeben bleibt beim Menschen.');
        }
        $plattform = $this->plattformRolle($ziel, $this->teamKette($team));
        if ($darf && $plattform !== 'member') {
            throw new \RuntimeException(in_array($plattform, ['owner', 'admin'], true)
                ? 'Inhaber und Admins dürfen ohnehin freigeben.'
                : 'Betrachter dürfen nur lesen. Erst in den Team-Einstellungen zum Mitglied machen.');
        }
        FoodAlchemistTeamMemberFlag::updateOrCreate(['team_id' => $team->id, 'user_id' => $userId],
            ['can_approve_invoices' => $darf, 'set_by' => $actor?->id]);
    }

    /** Höchste Plattform-Rolle des Users im Team oder einem Eltern-Team. */
    private function plattformRolle(User $user, array $kette): ?string
    {
        $rollen = DB::table('team_user')->whereIn('team_id', $kette)->where('user_id', $user->id)->pluck('role')->all();
        $best = null;
        foreach ($rollen as $r) {
            if (isset(self::RANG[$r]) && ($best === null || self::RANG[$r] > self::RANG[$best])) {
                $best = $r;
            }
        }

        return $best;
    }

    private function darfFreigebenHaken(User $user, array $kette): bool
    {
        return FoodAlchemistTeamMemberFlag::whereIn('team_id', $kette)->where('user_id', $user->id)->where('can_approve_invoices', true)->exists();
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
