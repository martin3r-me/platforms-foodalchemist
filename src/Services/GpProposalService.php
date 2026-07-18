<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGpNewProposal;

/**
 * 07·M4 (reframed): BESCHAFFUNGS-WÜNSCHE (Sourcing-Backlog) — kein GP-Write.
 * Ein Eintrag entsteht NUR, wenn eine Zutat weder einen Bestands-GP hat noch
 * LA-First gemintet werden konnte (keine passende LA) → „diesen Artikel
 * beschaffen", NICHT „GP kuratieren". GPs entstehen ausschließlich über den
 * LA-First-Mint (LaFirstGpService); dieser Service promotet NIE einen Wunsch
 * zum GP. Dedup über normalisierten Namen macht den MCP-POST idempotent.
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
            'main_ingredient_slug' => $data['main_ingredient_slug'] ?? null,
            'commodity_group' => $data['commodity_group'] ?? null,
            'condition' => $data['condition'] ?? null,
            'kontext' => $data['kontext'] ?? null,
            'source_kind' => $data['source_kind'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'reasoning' => $data['reasoning'] ?? null,
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
