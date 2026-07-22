<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 18 — Produktionszeile PRO REZEPT (nicht pro Ziel). Inhalt = ein
 * Eintrag aus PlanungsblattService::produktionsblattFuerZiele()['rezepte']. Snapshot-
 * Felder frieren beim Übergang planned→in_progress ein (`note` bleibt darüber hinaus
 * über jeden Recompute hinweg stehen, da manuell gepflegt).
 */
class FoodAlchemistProductionOrderLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_production_order_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_basisrezept' => 'boolean',
        'tiefe' => 'integer',
        'ansaetze' => 'decimal:3',
        'benoetigt_ansaetze' => 'decimal:3',
        'portionen' => 'integer',
        'basis_yield_kg' => 'decimal:3',
        'produzierte_menge_kg' => 'decimal:3',
        'arbeitszeit_min' => 'integer',
        'darreichung' => 'array',
        'zutaten' => 'array',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProductionOrder::class, 'production_order_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }
}
