<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Preisklasse: relative Abweichung vom dynamischen Unternehmens-Basissatz.
 * Die alten Rohaufschlagsfelder sind nur noch Migrationsquelle.
 */
class FoodAlchemistMarkupClass extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_markup_classes';

    protected $guarded = ['id'];

    protected $casts = [
        'class_factor_pct' => 'decimal:3',
        'rounding_decimals' => 'integer',
        'pricing_v2_migrated_at' => 'datetime',
        'is_inactive' => 'boolean',
    ];

    /** Spec 82 §13 I: Basis-Katalog — global lesen alle, schreiben nur der Master (siehe BelongsToTeamHierarchy::isOwnedBy). */
    public static function istBasisKatalog(): bool
    {
        return true;
    }
}
