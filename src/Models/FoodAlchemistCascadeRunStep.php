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

    /**
     * geplant → queued → running → done → (freigegeben | verworfen); failed/skipped terminal.
     * `geplant` = Sub-Rezept ist benannt, aber noch NICHT erzeugt — es wartet auf die Freigabe der
     * Stufe darüber (gestufte Kaskade); es hängt kein Job daran, die Zeile ist der sichtbare Platz-
     * halter der Basisrezepte-Stufe. `done` = Draft erzeugt, wartet auf Entscheidung (Gate 2);
     * `freigegeben` = Artefakt live (Rezept approved / Concept active); `verworfen` = Draft
     * soft-deleted; `skipped` = Reuse-Treffer (Bestands-Artefakt übernommen, nichts zu erzeugen).
     */
    public const STATUSES = ['geplant', 'queued', 'running', 'done', 'freigegeben', 'verworfen', 'failed', 'skipped'];

    protected $casts = [
        'uuid' => 'string',
        'depth' => 'integer',
        'context_snapshot' => 'array',
        'deferred' => 'array',
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
