<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 32 C3 — eine Ist-Verkaufsposition (Menge × Umsatz × Tag × Gericht).
 * Das Gegenstück zu {@see FoodAlchemistPurchaseTransaction} auf der Erlösseite. team-scoped;
 * `recipe_id` nullbar (noch nicht zugeordnet — bleibt zur Zuordnung liegen, wird nie verworfen).
 * Schreibweg über SalesImportService; `source_hash` = Dedup-Schlüssel.
 */
class FoodAlchemistSalesFact extends Model
{
    /**
     * Trait wegen des Trait-Vertrags (PolicyTest) — die Abfragen bleiben aber bewusst STRIKT
     * auf `team_id`, exakt wie beim Einkaufsjournal: ein Umsatz gehört dem Betrieb, der ihn
     * gemacht hat. Ein Kind-Team hat die Zahlen des Eltern-Teams nicht zu sehen. Wer hier auf
     * `visibleToTeam()` umstellt, ändert die Sichtbarkeit — das ist eine Entscheidung, kein Refactor.
     */
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_sales_facts';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_sold' => 'decimal:3',
        'revenue_net' => 'decimal:2',
        'match_confidence' => 'decimal:2',
        'sold_at' => 'date',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }
}
