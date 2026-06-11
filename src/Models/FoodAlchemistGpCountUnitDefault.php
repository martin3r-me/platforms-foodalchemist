<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Stückgewicht je GP×Einheit (GL-02 T1 Zeile 3 — Knoblauch
 * „Zehe" 5 g vs. „Knolle" 40 g); ergänzt gps.stk_default_g.
 */
class FoodAlchemistGpCountUnitDefault extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_gp_count_unit_defaults';

    protected $guarded = ['id'];

    protected $casts = ['default_g' => 'decimal:2', 'is_primary' => 'boolean', 'ai_confidence' => 'decimal:3'];
}
