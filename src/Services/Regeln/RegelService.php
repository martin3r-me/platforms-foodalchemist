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
