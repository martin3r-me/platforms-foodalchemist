<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept-Slot-Staffel (B1) — eine Preisstufe „ab N Personen → €/Person"
 * eines header_preis-Slots mit preis_basis='staffel'. Spiegel von FoodbookBlockStaffel.
 */
class FoodAlchemistConceptSlotStaffel extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_concept_slot_staffel';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'min_personen' => 'integer',
        'preis' => 'decimal:2',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConceptSlot::class, 'slot_id');
    }
}
