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

    /** Nur im Audit tatsächlich verwendete Quellen; keine heutige DB-Auflösung. */
    public function selectedDocuments(array $files): array
    {
        $selected = array_fill_keys($files, true);
        $found = [];
        foreach ($this->snapshot['profiles'] ?? [] as $keys) {
            foreach ($keys as $roles) {
                foreach ($roles as $rows) {
                    foreach ($rows as $row) {
                        $file = $row['slug'].'@v'.$row['version'];
                        if (isset($selected[$file])) $found[$file] = ['file' => $file, 'text' => $row['content_md']];
                    }
                }
            }
        }
        return array_values($found);
    }

    public function missing(?string $key): array
    {
        return array_values(array_filter($this->snapshot['missing'] ?? [],
            static fn (array $row) => $key === null || $row['scope_key'] === $key));
    }
}
