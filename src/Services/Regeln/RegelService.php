<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * Schreibweg für Regeln (Spec 81 Teil C/G): prüft Schema + Beispiele, zählt die Version hoch, legt die Fassung in
 * `foodalchemist_rule_versions` ab und leert das Regelbuch-Memo. Globale Regeln schreibt nur das Master-Team
 * (`TeamScope::mayWrite`); ohne Team (Migration/Seed) ist es der Systempfad.
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
    public function speichere(array $daten, ?Team $team = null, ?int $userId = null, ?bool $aktiv = null, string $via = 'einstellungen'): FoodAlchemistRule
    {
        if ($team !== null && ! TeamScope::mayWrite(null, $team)) {
            throw new RuntimeException('Globale Regeln pflegt nur das Master-Team.');
        }
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

    /** Aktivieren/Deaktivieren ist eine eigene Aktion (Kuration) und ebenfalls eine neue Fassung. */
    public function setzeAktiv(int $regelId, bool $aktiv, ?Team $team = null, ?int $userId = null): FoodAlchemistRule
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->findOrFail($regelId);

        return $this->speichere($regel->only(self::FELDER), $team, $userId, $aktiv);
    }

    /** Eine frühere Fassung wieder herstellen (als neue Version). */
    public function zurueckAuf(int $regelId, int $version, ?Team $team = null, ?int $userId = null): FoodAlchemistRule
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->findOrFail($regelId);
        $alt = FoodAlchemistRuleVersion::where('rule_id', $regelId)->where('version', $version)->firstOrFail();

        return $this->speichere([...$regel->only(self::FELDER), 'wirkung' => $alt->wirkung, 'params' => $alt->params,
            'beispiele' => $alt->beispiele], $team, $userId, $alt->aktiv);
    }
}
