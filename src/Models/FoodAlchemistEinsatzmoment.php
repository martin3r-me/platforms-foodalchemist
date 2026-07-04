<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Einsatzmoment-Vokabular (Concepter-Facette, Umbau-Spec Phase 4):
 * Frühstück/Lunch/Apéro/Dinner/… — mehrfach pro Concept, team-pflegbar in den
 * Einstellungen (Review F4).
 */
class FoodAlchemistEinsatzmoment extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_service_moments';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];

    public function concepts()
    {
        return $this->belongsToMany(
            FoodAlchemistConcept::class,
            'foodalchemist_concept_service_moments',
            'service_moment_id',
            'concept_id'
        );
    }
}
