<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Eventtyp-Vokabular (Concepter-Facette, Umbau-Spec Phase 4):
 * Konferenz/Gala/Sommerfest/Hochzeit/… — einfach pro Concept, team-pflegbar
 * in den Einstellungen (Review F5).
 */
class FoodAlchemistEventtyp extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_eventtypen';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];

    public function concepts()
    {
        return $this->hasMany(FoodAlchemistConcept::class, 'eventtyp_id');
    }
}
