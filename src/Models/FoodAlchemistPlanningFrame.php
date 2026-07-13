<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Planungs-Gerüst (R4.1) — das SOLL eines Foodbooks/Konzepts als
 * strukturierte Daten: Preisarchitektur p. P. am Kopf, Dramaturgie/Mengengerüst in
 * slots, Quoten + Kunden-Politik in rules. Owner polymorph (foodbook|concept, unique
 * je Owner). Messlatte für R4.2-Coverage + Prompt-Material für R6.1 Brief→Konzept.
 */
class FoodAlchemistPlanningFrame extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const OWNER_TYPES = ['foodbook', 'concept'];

    protected $table = 'foodalchemist_planning_frames';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'target_price_pp' => 'decimal:2',
        'price_min_pp' => 'decimal:2',
        'price_max_pp' => 'decimal:2',
    ];

    public function slots(): HasMany
    {
        return $this->hasMany(FoodAlchemistPlanningFrameSlot::class, 'frame_id')->orderBy('position');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FoodAlchemistPlanningFrameRule::class, 'frame_id');
    }
}
