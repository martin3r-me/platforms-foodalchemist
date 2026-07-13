<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use RuntimeException;

/**
 * R4.3 Phasen-Statusmaschine für Foodbook + Konzept: Kontext → Struktur →
 * Befüllung → Kalkulation → Freigabe. Ergänzt draft/aktiv, ersetzt sie nicht.
 *
 * Gate (R4.3-DoD): Übergang NACH 'freigabe' nur, wenn die Coverage (R4.2) keine
 * roten Ampeln zeigt — Override möglich, aber nur MIT Begründung und protokolliert
 * (ActivityLog-Eintrag zusätzlich zum automatischen Attribut-Log). Rückwärts-
 * Übergänge sind immer frei (Arbeit darf zurück in eine frühere Phase).
 * MCP setzt Phasen über setPhase(..., via: 'mcp') — 'freigabe' bleibt menschlich.
 */
class PhaseService
{
    public const PHASEN = ['kontext', 'struktur', 'befuellung', 'kalkulation', 'freigabe'];

    public const LABELS = [
        'kontext' => 'Kontext', 'struktur' => 'Struktur', 'befuellung' => 'Befüllung',
        'kalkulation' => 'Kalkulation', 'freigabe' => 'Freigabe',
    ];

    public function __construct(
        private PlanningFrameService $frames,
        private CoverageService $coverage,
    ) {}

    /**
     * Phase setzen (owner = foodbook|concept). Wirft bei ungültiger Phase, fremdem
     * Owner (D1) oder verletztem Freigabe-Gate ohne Override-Begründung.
     */
    public function setPhase(Team $team, string $ownerType, int $ownerId, string $phase, ?string $overrideNote = null, string $via = 'ui'): object
    {
        if (! in_array($phase, self::PHASEN, true)) {
            throw new RuntimeException('Ungültige Phase — erlaubt: ' . implode(' → ', self::PHASEN) . '.');
        }
        $owner = $this->frames->resolveOwner($team, $ownerType, $ownerId);
        if (! $owner->isOwnedBy($team)) {
            throw new RuntimeException('Geerbtes Objekt — Phase setzt nur das Besitzer-Team (D1).');
        }
        if ($phase === 'freigabe' && $via !== 'ui') {
            throw new RuntimeException('Freigabe bleibt menschlich — Phase „freigabe" wird nicht über MCP gesetzt.');
        }

        // Gate Kalkulation → Freigabe: keine roten Coverage-Ampeln (Override protokolliert).
        $daten = ['phase' => $phase];
        if ($phase === 'freigabe' && $this->coverage->hatRoteAmpeln($team, $ownerType, $ownerId)) {
            $note = $overrideNote !== null ? trim($overrideNote) : '';
            if ($note === '') {
                throw new RuntimeException('Freigabe-Gate: Coverage zeigt rote Ampeln (verletzt). Erst beheben — oder mit Override-Begründung freigeben.');
            }
            // Durabel am Objekt (Sandbox-ActivityLog ist Stub); zusätzlich ActivityLog, wo vorhanden.
            $daten['phase_override_note'] = $note;
            $daten['phase_override_at'] = now();
            if (function_exists('activity')) {
                activity()->performedOn($owner)
                    ->withProperties(['phase' => $phase, 'override_note' => $note, 'via' => $via])
                    ->log('phase_freigabe_override');
            }
        }

        $owner->update($daten);

        return $owner->refresh();
    }

    /** Nächste Phase in der Kette (für „Weiter"-Buttons); null am Ende. */
    public static function naechste(string $phase): ?string
    {
        $i = array_search($phase, self::PHASEN, true);

        return $i === false || $i >= count(self::PHASEN) - 1 ? null : self::PHASEN[$i + 1];
    }
}
