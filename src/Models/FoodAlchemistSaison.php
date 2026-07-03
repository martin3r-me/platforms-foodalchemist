<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Saison-Vokabular (Concepter-Facette, Umbau-Spec Phase 4):
 * Frühling/Sommer/Herbst/Winter/ganzjährig — mehrfach pro Concept,
 * team-pflegbar in den Einstellungen (F6: ersetzt Saison-Missbrauch des
 * alten Kategorien-Baums).
 */
class FoodAlchemistSaison extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_saisons';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];

    public function concepts()
    {
        return $this->belongsToMany(
            FoodAlchemistConcept::class,
            'foodalchemist_concept_saisons',
            'saison_id',
            'concept_id'
        );
    }
}
