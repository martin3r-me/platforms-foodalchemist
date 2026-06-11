<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description M3-11: Bulk-Match-Vorschlag LA→GP (GL-04 v1) — tentative Queue;
 * Übernehmen schreibt structure.gp_id + triggert Lead-Neuwahl (GL-03 T3).
 * Schreibwege über MatchService.
 */
class FoodAlchemistMatchProposal extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_match_proposals';

    protected $guarded = ['id'];

    protected $casts = ['score' => 'decimal:4'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
