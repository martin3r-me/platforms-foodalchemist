<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamBereich;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamKontingent;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamMemberFlag;
use Platform\FoodAlchemist\Models\FoodAlchemistUserBereichSperre;
use Platform\FoodAlchemist\Support\FaBereiche;

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

    // ── Spec 77b · Bereiche und Kontingente ────────────────────────────────

    /** Haupt-Team (Wurzel der Kette) — dort hängen Buchung und Kontingente. */
    public function hauptTeam(Team $team): Team
    {
        return $this->kundenHauptTeam($team);
    }

    /**
     * Kundengrenze: oberstes Team der Kette — aber nie über ein konfiguriertes Master-Team
     * (`foodalchemist.master_team_id`) hinaus. Hängt ein Kunde unter dem Master, endet die Kette am Team direkt
     * darunter; der Master selbst ist sein eigenes Haupt-Team. Ohne Master = oberstes Team (wie bisher).
     * Gemeinsame Grenze für Kontingente, Bereiche, Standorte (Spec 77) und Inspirationen (Spec 79).
     */
    public function kundenHauptTeam(Team $team): Team
    {
        $kette = $this->teamKette($team);
        $master = config('foodalchemist.master_team_id');
        $pos = $master !== null && $master !== '' ? array_search((int) $master, $kette, true) : false;
        $id = $pos === false ? end($kette) : ($pos === 0 ? $kette[0] : $kette[$pos - 1]);

        return (int) $id === (int) $team->id ? $team : (Team::find($id) ?? $team);
    }

    /** Liegt das Team (oder ein Vorfahr) jenseits der Kundengrenze, also ist es das Master-Team selbst? */
    public function istMasterTeam(Team $team): bool
    {
        $master = config('foodalchemist.master_team_id');

        return $master !== null && $master !== '' && (int) $master === (int) $team->id;
    }

    /** Ist der Bereich für das Team freigeschaltet? Abgeschaltet im Team ODER einem Eltern-Team = aus. Keine Zeile = an. */
    public function bereichAktiv(Team $team, string $bereich): bool
    {
        return ! FoodAlchemistTeamBereich::whereIn('team_id', $this->teamKette($team))
            ->where('bereich', $bereich)->where('aktiv', false)->exists();
    }

    /** Darf der User den Bereich im Team nutzen? Team gebucht ∧ User nicht eingeschränkt. Plattform-Admin immer. */
    public function darfBereich(?User $user, Team $team, ?string $bereich): bool
    {
        if ($bereich === null) {
            return true;
        }
        if ($user !== null && ! $user->isAiUser() && $this->istPlattformAdmin($user)) {
            return true;
        }
        if (! $this->bereichAktiv($team, $bereich)) {
            return false;
        }

        return $user === null || ! FoodAlchemistUserBereichSperre::whereIn('team_id', $this->teamKette($team))
            ->where('user_id', $user->id)->where('bereich', $bereich)->exists();
    }

    /** @return array<string,bool> Bereich => an/aus für das Team (Buchung, ohne User-Sicht) */
    public function teamBereiche(Team $team): array
    {
        $aus = FoodAlchemistTeamBereich::whereIn('team_id', $this->teamKette($team))->where('aktiv', false)->pluck('bereich')->all();

        return array_map(fn ($b) => ! in_array($b, $aus, true), array_combine(array_keys(FaBereiche::KATALOG), array_keys(FaBereiche::KATALOG)));
    }

    /** @return list<string> abgeschaltete Bereiche eines Users im Team (eigene Einschränkungen) */
    public function userSperren(Team $team, int $userId): array
    {
        return FoodAlchemistUserBereichSperre::where('team_id', $team->id)->where('user_id', $userId)->pluck('bereich')->all();
    }

    /** Bereich für ein Team an/aus — nur Plattform-Admin (Freischalten = Abrechnung, Spec 77 F2). */
    public function setzeTeamBereich(Team $team, ?User $actor, string $bereich, bool $aktiv): void
    {
        if ($actor === null || ! $this->istPlattformAdmin($actor)) {
            throw new FaRechtFehltException(FaRolle::Admin, $actor !== null ? $this->rolle($actor, $team) : null, 'Bereiche freischalten (nur Plattform-Admin)');
        }
        if (! FaBereiche::istBereich($bereich)) {
            throw new \RuntimeException('Unbekannter Bereich: '.$bereich.'.');
        }
        FoodAlchemistTeamBereich::updateOrCreate(['team_id' => $team->id, 'bereich' => $bereich], ['aktiv' => $aktiv, 'set_by' => $actor->id]);
    }

    /** Bereich für einen User im Team ein-/ausschränken — FA-Admin des Teams. Nur einschränken, nie über das Team hinaus. */
    public function setzeUserSperre(Team $team, ?User $actor, int $userId, string $bereich, bool $gesperrt): void
    {
        $this->pruefe($actor, $team, FaRolle::Admin, 'Bereiche für Mitglieder einschränken');
        if (! FaBereiche::istBereich($bereich)) {
            throw new \RuntimeException('Unbekannter Bereich: '.$bereich.'.');
        }
        $ziel = User::find($userId);
        if ($ziel === null || ! DB::table('team_user')->where('team_id', $team->id)->where('user_id', $userId)->exists()) {
            throw new \RuntimeException('Diese Person ist kein Mitglied des Teams.');
        }
        if ($gesperrt && in_array($this->plattformRolle($ziel, $this->teamKette($team)), ['owner', 'admin'], true)) {
            throw new \RuntimeException('Inhaber und Admins lassen sich nicht einschränken — erst die Rolle in den Team-Einstellungen ändern.');
        }
        if ($gesperrt) {
            FoodAlchemistUserBereichSperre::withTrashed()->updateOrCreate(['team_id' => $team->id, 'user_id' => $userId, 'bereich' => $bereich],
                ['set_by' => $actor?->id, 'deleted_at' => null]);
        } else {
            FoodAlchemistUserBereichSperre::where('team_id', $team->id)->where('user_id', $userId)->where('bereich', $bereich)->forceDelete();
        }
    }

    /** @return array{max_standorte:?int, max_user:?int, ki_budget_eur_monat:?float} Kontingente des Haupt-Teams */
    public function kontingente(Team $team): array
    {
        $k = FoodAlchemistTeamKontingent::where('team_id', $this->hauptTeam($team)->id)->first();

        return [
            'max_standorte' => $k?->max_standorte,
            'max_user' => $k?->max_user,
            'ki_budget_eur_monat' => $k?->ki_budget_eur_monat !== null ? (float) $k->ki_budget_eur_monat : null,
        ];
    }

    /** Kontingente am Haupt-Team setzen — nur Plattform-Admin. null = unbegrenzt. */
    public function setzeKontingente(Team $team, ?User $actor, array $werte): void
    {
        if ($actor === null || ! $this->istPlattformAdmin($actor)) {
            throw new FaRechtFehltException(FaRolle::Admin, $actor !== null ? $this->rolle($actor, $team) : null, 'Kontingente setzen (nur Plattform-Admin)');
        }
        $daten = [];
        foreach (['max_standorte', 'max_user', 'ki_budget_eur_monat'] as $f) {
            if (array_key_exists($f, $werte)) {
                $v = $werte[$f];
                $v = $v === '' || $v === null ? null : (is_string($v) ? str_replace(',', '.', $v) : $v);
                if ($v !== null && (! is_numeric($v) || (float) $v < 0)) {
                    throw new \RuntimeException("Kontingent {$f} muss eine Zahl ≥ 0 sein.");
                }
                $daten[$f] = $v === null ? null : ($f === 'ki_budget_eur_monat' ? round((float) $v, 2) : (int) $v);
            }
        }
        FoodAlchemistTeamKontingent::updateOrCreate(['team_id' => $this->hauptTeam($team)->id], $daten + ['set_by' => $actor->id]);
    }

    /** Nutzung je Kontingent (für Anzeige und Prüfung). @return array{standorte:int, user:int, ki_eur_monat:float} */
    public function kontingentNutzung(Team $team): array
    {
        $haupt = $this->hauptTeam($team);
        $alle = \Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob::teamUndNachfahren((int) $haupt->id);

        return [
            'standorte' => count($alle) - 1,
            'user' => (int) DB::table('team_user')->whereIn('team_id', $alle)->distinct()->count('user_id'),
            'ki_eur_monat' => $this->kiKostenMonat($alle),
        ];
    }

    /**
     * Wirft, wenn ein Kontingent mit dem nächsten Schritt überschritten würde. `$art`: standorte | user.
     * Für die Plattform-Verwaltung (Team anlegen, Mitglied aufnehmen) — klare Meldung statt stillem Abschneiden.
     */
    public function pruefeKontingent(Team $team, string $art): void
    {
        $k = $this->kontingente($team);
        $n = $this->kontingentNutzung($team);
        $grenze = $art === 'standorte' ? $k['max_standorte'] : $k['max_user'];
        $ist = $art === 'standorte' ? $n['standorte'] : $n['user'];
        if ($grenze !== null && $ist >= $grenze) {
            throw new \RuntimeException($art === 'standorte'
                ? "Kontingent erreicht: {$grenze} Standorte (Unter-Teams) gebucht."
                : "Kontingent erreicht: {$grenze} Benutzer gebucht.");
        }
    }

    /** KI-Budget des Haupt-Teams für den laufenden Monat erschöpft? (Euro, Spec 77 F1) */
    public function kiBudgetErschoepft(Team $team): bool
    {
        $budget = $this->kontingente($team)['ki_budget_eur_monat'];
        if ($budget === null) {
            return false;
        }

        return $this->kontingentNutzung($team)['ki_eur_monat'] >= $budget;
    }

    /** @param list<int> $teamIds */
    private function kiKostenMonat(array $teamIds): float
    {
        $cached = \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'tokens_cached') ? 'SUM(COALESCE(tokens_cached,0))' : '0';
        $zeilen = DB::table('foodalchemist_ai_call_log')->whereIn('team_id', $teamIds)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('feature, tier, model, COUNT(*) AS calls, SUM(COALESCE(tokens_in,0)) AS t_in, '.$cached.' AS t_cached, SUM(COALESCE(tokens_out,0)) AS t_out')
            ->groupBy('feature', 'tier', 'model')->get();
        $rechner = app(\Platform\FoodAlchemist\Services\Ai\AiCostCalculator::class);

        return round((float) $zeilen->sum(fn ($z) => (float) ($rechner->displayCost($rechner->costUsd($z)) ?? 0)), 2);
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
    public function teamKette(Team $team): array
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

    public function istPlattformAdmin(User $user): bool
    {
        $admins = (array) config('platform-shell.admins', []);

        return $admins !== [] && in_array(strtolower((string) $user->email), $admins, true);
    }
}
