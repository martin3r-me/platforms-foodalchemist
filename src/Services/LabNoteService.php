<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;

/**
 * R6.11 · S3 (E4) — FA-Lab-Journal: team-eigene Senke für Hypothesen-/Widerspruchs-
 * Ergebnisse (und freie Notizen). Ehrliche Evidenz-Stufe bleibt Pflicht (Default T3 =
 * Hypothese, nie Fakt). Vault-Write ist headless nicht verfügbar → schlanke FA-Tabelle.
 */
class LabNoteService
{
    private const TIERS = ['T0', 'T1', 'T2', 'T3'];

    /** Notiz anlegen (gehört dem anlegenden Team, D1). */
    public function create(Team $team, array $input, ?int $authorId = null): FoodAlchemistLabNote
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('Lab-Notiz braucht einen Titel.');
        }
        $tier = in_array($input['evidence_tier'] ?? null, self::TIERS, true) ? $input['evidence_tier'] : 'T3';

        return FoodAlchemistLabNote::create([
            'team_id' => $team->id,
            'title' => $title,
            'body' => ($input['body'] ?? '') ?: null,
            'evidence_tier' => $tier,
            'source_ref' => ($input['source_ref'] ?? '') ?: null,
            'created_via' => (string) ($input['created_via'] ?? 'manual'),
            'author_id' => $authorId,
        ]);
    }

    /** @return Collection<int, FoodAlchemistLabNote> team-sichtbare Notizen, neueste zuerst. */
    public function forTeam(Team $team, int $limit = 100): Collection
    {
        return FoodAlchemistLabNote::visibleToTeam($team)
            ->orderByDesc('id')->limit(max(1, min(500, $limit)))->get();
    }
}
