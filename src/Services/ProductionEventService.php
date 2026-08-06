<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionEvent;

class ProductionEventService
{
    public function record(int $teamId, int $orderId, string $type, array $data = []): FoodAlchemistProductionEvent
    {
        return FoodAlchemistProductionEvent::create([
            'team_id' => $teamId,
            'order_id' => $orderId,
            'line_id' => $data['line_id'] ?? null,
            'event_type' => $type,
            'from_state' => $data['from_state'] ?? null,
            'to_state' => $data['to_state'] ?? null,
            'reason_code' => $data['reason_code'] ?? null,
            'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 2000) : null,
            'actor_id' => Auth::id(),
            'payload' => $data['payload'] ?? null,
            'created_at' => now(),
        ]);
    }
}
