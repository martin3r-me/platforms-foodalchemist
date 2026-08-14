<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Kaskaden-Lauf (Planungs-Kaskade, P0): EIN „Go" auf einer Planung. Der geteilte
 * Motor ({@see \Platform\FoodAlchemist\Services\PlanningCascadeService}) legt ihn an und fächert
 * ihn in {@see FoodAlchemistCascadeRunStep} auf (Baum concept → gericht → rezept/gp).
 *
 * `scope` bestimmt die Einstiegs-Tiefe (rezept ⊂ gericht ⊂ concept ⊂ vollkaskade); der Motor läuft
 * von dort abwärts. **Invariante:** erzeugt NUR Drafts — die Freigabe an eine Live-Ausgabe ist das
 * zweite Gate (Sammel-Review, P2). Team-lokal (D1).
 */
class FoodAlchemistCascadeRun extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_cascade_runs';

    protected $guarded = ['id'];

    /** Einstiegs-Tiefe der Kaskade (Rezept ⊂ Gericht ⊂ Concept ⊂ Ausgabe-Frame). */
    public const SCOPES = ['rezept', 'gericht', 'concept', 'vollkaskade'];

    /** Lebenszyklus: running (Steps rechnen) → review (fertig, Sammel-Review offen) → done | failed. */
    public const STATUSES = ['running', 'review', 'done', 'failed'];

    protected $casts = [
        'uuid' => 'string',
        'params' => 'array',
        'staged' => 'boolean',
    ];

    /** Steps dieses Laufs (concept/gericht/rezept/gp), Baum über parent_step_id. */
    public function steps(): HasMany
    {
        return $this->hasMany(FoodAlchemistCascadeRunStep::class, 'cascade_run_id');
    }
}
