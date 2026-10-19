<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Services\FaRechte;
use RuntimeException;

/**
 * Schreibweg für Regeln (Spec 81 Teil C/G): prüft Schema + Beispiele, zählt die Version hoch, legt die Fassung in
 * `foodalchemist_rule_versions` ab und leert das Regelbuch-Memo. Regeln sind global und gehören dem
 * Plattform-Administrator — dieselbe Ebene wie das kuratierte Wissen (Dominique 09.10.): schreiben dürfen nur
 * Plattform-Admins (`FaRechte::istPlattformAdmin`), kein Team-Admin. Ohne User (Migration/Seed) = Systempfad.
 *
 * Team-Abweichungen (Spec 85 D-7 PR 2): `speichereTeam`/`entferneTeam` — FA-Admin des Teams (Mensch, keine KI), nur Schlüssel aus
 * `RegelBuch::UEBERSTEUERBAR`, nur `params` + `notiz` vom Team; Art, Ziel, Wirkung, Titel, Dossier kommen von der globalen Regel.
 * Schwellen nur innerhalb der belegten Untergrenze (`Schwelle::zulaessig`). Geschrieben wird ausschließlich die eigene Team-Zeile.
 */
final class RegelService
{
    private const FELDER = ['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele', 'notiz', 'dossier_slug'];

    public function __construct(private RegelMotor $motor)
    {
    }

    /**
     * Neue Regel anlegen oder bestehende ändern (gleicher Schlüssel). Neue Regeln starten inaktiv, außer
     * `$aktiv` wird ausdrücklich gesetzt (Seeds, die bestehendes Verhalten umziehen).
     *
     * @throws RegelUngueltig
     */
    public function speichere(array $daten, ?Authenticatable $user = null, ?bool $aktiv = null, string $via = 'einstellungen'): FoodAlchemistRule
    {
        if ($user !== null && ! self::darf($user)) {
            throw new RuntimeException('Regeln pflegt nur der Plattform-Administrator.');
        }
        $userId = $user !== null ? (int) $user->getAuthIdentifier() : null;
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', (string) ($daten['schluessel'] ?? ''))->first()
            ?? new FoodAlchemistRule(['team_id' => null, 'aktiv' => false, 'version' => 0, 'created_via' => $via]);
        $regel->fill(array_intersect_key($daten, array_flip(self::FELDER)));
        $regel->wirkung ??= 'warnen';
        if ($aktiv !== null) {
            $regel->aktiv = $aktiv;
        }

        $fehler = $this->motor->validiere($regel);
        if ($fehler !== []) {
            throw new RegelUngueltig($fehler);
        }

        DB::transaction(function () use ($regel, $userId): void {
            $regel->version = (int) $regel->version + 1;
            $regel->save();
            FoodAlchemistRuleVersion::create([
                'rule_id' => $regel->id, 'version' => $regel->version, 'wirkung' => $regel->wirkung,
                'params' => $regel->params, 'beispiele' => $regel->beispiele, 'aktiv' => $regel->aktiv, 'user_id' => $userId,
            ]);
        });
        RegelBuch::vergessen();

        return $regel->fresh();
    }

    /**
     * Hausstandard des Teams setzen (Abweichung von der globalen Regel). Idempotent je (Team, Schlüssel), jede Änderung = neue Version.
     *
     * @throws RegelUngueltig  nicht übersteuerbar, Schema verletzt oder jenseits der Untergrenze (Meldung nennt Grenze + Quelle)
     * @throws \Platform\FoodAlchemist\Exceptions\FaRechtFehltException
     */
    public function speichereTeam(\Platform\Core\Models\Team $team, string $schluessel, array $params, ?string $notiz, ?Authenticatable $user, string $via = 'einstellungen'): FoodAlchemistRule
    {
        $this->pruefeTeamRecht($team, $user);
        $global = $this->globaleUebersteuerbare($schluessel);
        unset($params['untergrenze'], $params['untergrenze_dauer'], $params['untergrenze_quelle']);   // Untergrenzen pflegt nur der Master
        $params = array_merge((array) $global->params, $params);
        foreach (['untergrenze', 'untergrenze_dauer', 'untergrenze_quelle'] as $f) {
            unset($params[$f]);
        }

        $regel = FoodAlchemistRule::ohneTeamSicht('regelpflege')->withTrashed()->where('team_id', $team->id)->where('schluessel', $schluessel)->first()
            ?? new FoodAlchemistRule(['team_id' => $team->id, 'version' => 0, 'created_via' => $via]);
        $regel->fill($global->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'dossier_slug']));
        $regel->forceFill(['team_id' => $team->id, 'params' => $params, 'beispiele' => null, 'notiz' => $notiz, 'aktiv' => true, 'deleted_at' => null]);

        $fehler = $this->motor->validiere($regel);
        if ($fehler === [] && $regel->art === 'schwelle' && ! (new Arten\Schwelle)->zulaessig($global, $regel)) {
            $g = (array) $global->params;
            $fehler[] = ($grenze = Arten\Schwelle::untergrenzeText($g)) !== null
                ? "Jenseits der Untergrenze {$grenze} — so locker darf der Hausstandard nicht sein."
                : 'Für diesen Wert ist nur strenger erlaubt (Hausstandard ' . Arten\Schwelle::text($g) . '; keine belegte Untergrenze).';

            throw new RegelUngueltig($fehler, 'UNTERGRENZE');
        }
        if ($fehler !== []) {
            throw new RegelUngueltig($fehler);
        }

        $userId = $user !== null ? (int) $user->getAuthIdentifier() : null;
        DB::transaction(function () use ($regel, $userId): void {
            $regel->version = (int) $regel->version + 1;
            $regel->save();
            FoodAlchemistRuleVersion::create([
                'rule_id' => $regel->id, 'version' => $regel->version, 'wirkung' => $regel->wirkung,
                'params' => $regel->params, 'beispiele' => null, 'aktiv' => true, 'user_id' => $userId,
            ]);
        });
        RegelBuch::vergessen();

        return $regel->fresh();
    }

    /** Hausstandard des Teams entfernen — ab sofort gilt wieder Oberteam bzw. global. Ohne eigene Abweichung: nichts zu tun. */
    public function entferneTeam(\Platform\Core\Models\Team $team, string $schluessel, ?Authenticatable $user): bool
    {
        $this->pruefeTeamRecht($team, $user);
        $regel = FoodAlchemistRule::ohneTeamSicht('regelpflege')->where('team_id', $team->id)->where('schluessel', $schluessel)->first();
        if ($regel === null) {
            return false;
        }
        $userId = $user !== null ? (int) $user->getAuthIdentifier() : null;
        DB::transaction(function () use ($regel, $userId): void {
            $regel->version = (int) $regel->version + 1;
            $regel->aktiv = false;
            $regel->save();
            FoodAlchemistRuleVersion::create(['rule_id' => $regel->id, 'version' => $regel->version, 'wirkung' => $regel->wirkung,
                'params' => $regel->params, 'beispiele' => null, 'aktiv' => false, 'user_id' => $userId]);
            $regel->delete();
        });
        RegelBuch::vergessen();

        return true;
    }

    /** Darf dieser User den Hausstandard dieses Teams pflegen? FA-Admin des Teams, Mensch (KI liest und schlägt vor, speichert nie). */
    public static function darfTeam(?Authenticatable $user, \Platform\Core\Models\Team $team): bool
    {
        return $user instanceof \Platform\Core\Models\User && ! $user->isAiUser()
            && app(FaRechte::class)->rolle($user, $team)->mindestens(\Platform\FoodAlchemist\Enums\FaRolle::Admin);
    }

    private function pruefeTeamRecht(\Platform\Core\Models\Team $team, ?Authenticatable $user): void
    {
        if ($user instanceof \Platform\Core\Models\User && $user->isAiUser()) {
            throw new RuntimeException('Hausstandards speichert nur ein Mensch (FA-Admin) — die KI kann einen Wert vorschlagen.');
        }
        app(FaRechte::class)->pruefe($user instanceof \Platform\Core\Models\User ? $user : null, $team,
            \Platform\FoodAlchemist\Enums\FaRolle::Admin, 'Hausstandard festlegen');
    }

    private function globaleUebersteuerbare(string $schluessel): FoodAlchemistRule
    {
        if (! in_array($schluessel, RegelBuch::UEBERSTEUERBAR, true)) {
            throw new RegelUngueltig(["Regel «{$schluessel}» ist nicht übersteuerbar — sie gilt für alle gleich."], 'NICHT_UEBERSTEUERBAR');
        }
        $global = FoodAlchemistRule::ohneTeamSicht('regelpflege')->whereNull('team_id')->where('schluessel', $schluessel)->where('aktiv', true)->first();
        if ($global === null) {
            throw new RegelUngueltig(["Zu «{$schluessel}» gibt es keine aktive globale Regel."], 'NICHT_UEBERSTEUERBAR');
        }

        return $global;
    }

    /** Darf dieser User Regeln pflegen? Plattform-Admin, kein KI-User. */
    public static function darf(?Authenticatable $user): bool
    {
        return $user instanceof \Platform\Core\Models\User && ! $user->isAiUser() && app(FaRechte::class)->istPlattformAdmin($user);
    }

    /** Aktivieren/Deaktivieren ist eine eigene Aktion (Kuration) und ebenfalls eine neue Fassung. */
    public function setzeAktiv(int $regelId, bool $aktiv, ?Authenticatable $user = null): FoodAlchemistRule
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->findOrFail($regelId);

        return $this->speichere($regel->only(self::FELDER), $user, $aktiv);
    }

    /** Eine frühere Fassung wieder herstellen (als neue Version). */
    public function zurueckAuf(int $regelId, int $version, ?Authenticatable $user = null): FoodAlchemistRule
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->findOrFail($regelId);
        $alt = FoodAlchemistRuleVersion::where('rule_id', $regelId)->where('version', $version)->firstOrFail();

        return $this->speichere([...$regel->only(self::FELDER), 'wirkung' => $alt->wirkung, 'params' => $alt->params,
            'beispiele' => $alt->beispiele], $user, $alt->aktiv);
    }
}
