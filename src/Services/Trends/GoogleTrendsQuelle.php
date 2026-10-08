<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Platform\Core\Models\Team;

/** Spec 79 · Quelle für Google-Trends-Kurven (Deutschland, 12 Monate). Produktiv: DataForSEO; in Tests ein Fake. */
interface GoogleTrendsQuelle
{
    public function verfuegbar(): bool;

    /**
     * Suchinteresse 0–100 über die Zeit für einen Begriff.
     *
     * @return list<array{date_from:?string, date_to:?string, value:?int}>
     */
    public function interesse(Team $team, string $begriff): array;
}
