<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Foodbook-Block-Staffel (M11) — eine Preisstufe „ab N Personen →
 * €/Person" eines header_frei_preis-Blocks mit preis_basis='staffel'.
 */
class FoodAlchemistFoodbookBlockStaffel extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbook_block_staffel';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'min_personen' => 'integer',
        'preis' => 'decimal:2',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookBlock::class, 'block_id');
    }
}
