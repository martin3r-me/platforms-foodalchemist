<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zentraler Canvas (Markenkern/Brief je Ebene): canvas_type ∈
 * food_dna|foodbook|concept|angebot, owner polymorph. Felder = canvas_entries,
 * Template in CanvasService::TEMPLATES.
 */
class FoodAlchemistCanvas extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_canvases';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string'];

    public function entries(): HasMany
    {
        return $this->hasMany(FoodAlchemistCanvasEntry::class, 'canvas_id')->orderBy('position');
    }
}
