<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Gerüst-Regel (R4.1) — messbare Soll-Regel je Frame (slot_id NULL)
 * oder je Slot. rule_type: diet_quota (ref_key=diet_form, operator+value_num+unit) ·
 * season_coverage (ref_id=season) · nogo_ingredient (value_text=Term, severity) ·
 * nogo_allergen (ref_key=EU-14-Allergen-Key, severity) · allergen_line (value_text).
 */
class FoodAlchemistPlanningFrameRule extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    public const RULE_TYPES = ['diet_quota', 'season_coverage', 'nogo_ingredient', 'nogo_allergen', 'allergen_line'];

    /** Kanonisches diet_form-Vokabular (Spiegel dish_classes.diet_form). */
    public const DIET_FORMS = ['fleisch', 'fisch', 'vegi', 'vegan', 'neutral', 'allergie'];

    public const OPERATORS = ['min', 'max', 'exact'];

    public const UNITS = ['count', 'percent'];

    protected $table = 'foodalchemist_planning_frame_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'value_num' => 'decimal:2',
        'meta' => 'array',
    ];

    public function frame(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPlanningFrame::class, 'frame_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPlanningFrameSlot::class, 'slot_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSaison::class, 'ref_id');
    }
}
