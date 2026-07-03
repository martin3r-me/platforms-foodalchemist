<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGpNewProposal;

/**
 * Phase 0: NEW-GP-Vorschläge (Staging-only). Kein GP-Write — die Queue wird
 * in der WaWi-LA-First-Kuration abgearbeitet. Dedup über normalisierten Namen,
 * damit wiederholte LLM-Calls keine Dubletten stapeln (macht den MCP-POST
 * effektiv idempotent).
 */
class GpProposalService
{
    /**
     * @return array{proposal: FoodAlchemistGpNewProposal, created: bool}
     */
    public function propose(Team $team, array $data, ?int $userId = null): array
    {
        $normalized = $this->normalize($data['name']);

        $vorhanden = FoodAlchemistGpNewProposal::where('team_id', $team->id)
            ->where('name_normalized', $normalized)
            ->where('status', FoodAlchemistGpNewProposal::STATUS_OFFEN)
            ->first();
        if ($vorhanden !== null) {
            return ['proposal' => $vorhanden, 'created' => false];
        }

        $proposal = FoodAlchemistGpNewProposal::create([
            'team_id' => $team->id,
            'name' => trim($data['name']),
            'name_normalized' => $normalized,
            'hauptzutat_slug' => $data['hauptzutat_slug'] ?? null,
            'warengruppe' => $data['warengruppe'] ?? null,
            'zustand' => $data['zustand'] ?? null,
            'kontext' => $data['kontext'] ?? null,
            'quelle_kind' => $data['quelle_kind'] ?? null,
            'quelle_id' => $data['quelle_id'] ?? null,
            'begruendung' => $data['begruendung'] ?? null,
            'match_snapshot' => $data['match_snapshot'] ?? null,
            'status' => FoodAlchemistGpNewProposal::STATUS_OFFEN,
            'created_by_user_id' => $userId,
        ]);

        return ['proposal' => $proposal, 'created' => true];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FoodAlchemistGpNewProposal> */
    public function open(Team $team, int $limit = 50)
    {
        return FoodAlchemistGpNewProposal::where('team_id', $team->id)
            ->where('status', FoodAlchemistGpNewProposal::STATUS_OFFEN)
            ->orderByDesc('created_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
