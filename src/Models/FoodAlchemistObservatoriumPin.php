<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** Team-Pinnwand im Observatorium: angepinnte Meldung (eingang_id) oder eigene Idee (titel/notiz/link). Nur das eigene Team. */
class FoodAlchemistObservatoriumPin extends Model
{
    // Trait-Vertrag (PolicyTest); gelesen/geschrieben wird trotzdem nur `where('team_id', eigenes Team)` — keine Vererbung der Pinnwand
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_observatorium_pinnwand';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string'];

    public function eingang(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrendEingang::class, 'eingang_id');
    }
}
