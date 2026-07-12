<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Servierform-Vokabular (Umbau-Spec Darreichungen, Phase 3) —
 * Scharnier zwischen Gericht-Darreichung und (Phase 4) Konzept-Dimension:
 * teller/buffet/flying/fingerfood/station/… + 'unbestimmt' als Review-Queue.
 * WaWi-Master, Spiegel via ImportSliceCommand.
 */
class FoodAlchemistServierform extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_serving_forms';

    protected $guarded = ['id'];

    protected $casts = [
        'is_inactive' => 'bool',
    ];

    public function presentations()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichung::class, 'serving_form_id');
    }

    /** @deprecated #486 deutscher Alias → presentations() */
    public function darreichungen()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichung::class, 'serving_form_id');
    }
}
