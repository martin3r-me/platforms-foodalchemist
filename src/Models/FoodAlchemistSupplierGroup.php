<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Lieferanten-Gruppe (Taxonomie): Lebensmittel-Großhandel, Frische & Fachhandel, Getränke, Non-Food & Verbrauch, Verleih & Eventausstattung, Sonstiges.
 * Basis-Katalog; ein Lieferant hat genau eine Hauptgruppe (`suppliers.group_id`).
 */
class FoodAlchemistSupplierGroup extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_supplier_groups';

    protected $fillable = ['uuid', 'team_id', 'code', 'name', 'sort_order', 'is_inactive'];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
        'is_inactive' => 'boolean',
    ];

    public function suppliers(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplier::class, 'group_id');
    }

    /** Spec 82 §13 I: Basis-Katalog — global lesen alle, schreiben nur der Master. */
    public static function istBasisKatalog(): bool
    {
        return true;
    }
}
