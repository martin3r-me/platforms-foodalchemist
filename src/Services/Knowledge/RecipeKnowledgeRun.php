<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Collection;

/** Unveränderlicher Kanonstand eines Basisrezept-Laufs, über Queue-Grenzen speicherbar. */
final class RecipeKnowledgeRun
{
    public function __construct(
        public readonly string $id,
        public readonly int $teamId,
        public readonly string $snapshotHash,
        private readonly array $snapshot,
    ) {}

    public function documents(string $scope, string $key, string $role): Collection
    {
        return collect($this->snapshot['profiles'][$scope][$key][$role] ?? [])
            ->map(static fn (array $row) => (object) $row);
    }

    public function missing(?string $key): array
    {
        return array_values(array_filter($this->snapshot['missing'] ?? [],
            static fn (array $row) => $key === null || $row['scope_key'] === $key));
    }
}
