<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceChangeAudit;

class PriceAuditService
{
    /** @param array<string,mixed> $metadata */
    public function record(
        Model $entity,
        string $type,
        ?float $oldCalculated,
        ?float $newCalculated,
        ?float $oldEffective,
        ?float $newEffective,
        ?string $source = null,
        array $metadata = [],
    ): void {
        if ($this->same($oldCalculated, $newCalculated) && $this->same($oldEffective, $newEffective)) {
            return;
        }

        FoodAlchemistPriceChangeAudit::create([
            'team_id' => $entity->team_id,
            'entity_type' => $type,
            'entity_id' => $entity->getKey(),
            'old_calculated_net' => $oldCalculated,
            'new_calculated_net' => $newCalculated,
            'old_effective_net' => $oldEffective,
            'new_effective_net' => $newEffective,
            'price_mode' => $entity->price_mode,
            'source' => $source,
            'reason' => $entity->price_override_reason,
            'user_id' => $entity->price_override_user_id,
            'metadata' => $metadata,
        ]);
    }

    private function same(?float $left, ?float $right): bool
    {
        return $left === null && $right === null
            || ($left !== null && $right !== null && abs($left - $right) < 0.005);
    }
}
