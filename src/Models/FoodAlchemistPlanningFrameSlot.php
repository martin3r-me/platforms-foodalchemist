<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Gerüst-Slot (R4.1) — ein Gang/eine Buffet-Station/ein Kapitel als
 * SOLL-Position: Reihenfolge (Dramaturgie), target_count (Mengengerüst), Preis-Anker/
 * Spanne je Gericht, is_pflicht. chapter_id = optionaler Ist-Bezug aufs Foodbook-Kapitel.
 */
class FoodAlchemistPlanningFrameSlot extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    public const SLOT_TYPES = ['gang', 'station', 'kapitel'];

    protected $table = 'foodalchemist_planning_frame_slots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'target_count' => 'integer',
        'is_pflicht' => 'boolean',
        'price_anchor' => 'decimal:2',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
    ];

    public function frame(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPlanningFrame::class, 'frame_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FoodAlchemistPlanningFrameRule::class, 'slot_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }
}
