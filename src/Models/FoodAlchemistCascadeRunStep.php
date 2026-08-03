<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Ein Schritt eines Kaskaden-Laufs ({@see FoodAlchemistCascadeRun}): erzeugt genau
 * EIN Draft-Artefakt (`kind` ∈ concept|gericht|rezept|gp). Ein Rezept-/Gericht-Step umhüllt einen
 * {@see \Platform\FoodAlchemist\Jobs\GenerateRecipeJob} — dessen Cache-Run-ID steht in
 * `generator_run_id`, der Job meldet Ergebnis (`ref_id`) bzw. `error` an diesen Step zurück.
 *
 * `parent_step_id` bildet den Baum (concept → seine Gerichte → deren Rezepte/GPs). Team-lokal (D1).
 */
class FoodAlchemistCascadeRunStep extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_cascade_run_steps';

    protected $guarded = ['id'];

    /** Artefakt-Typ, den dieser Step erzeugt. */
    public const KINDS = ['concept', 'gericht', 'rezept', 'gp'];

    /** queued → running → done | failed | skipped (skipped = Reuse-Treffer, keine Generierung nötig). */
    public const STATUSES = ['queued', 'running', 'done', 'failed', 'skipped'];

    protected $casts = [
        'uuid' => 'string',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistCascadeRun::class, 'cascade_run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_step_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_step_id');
    }
}
