<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Einheiten-Vokabular (g/kg/Stk/EL/…) mit Gramm-/ml-Defaults für die
 * Einheiten-Konvertierung der Kalkulation (GL-02/GL-11). Global (D1), Pflege Admin-Team (V-20).
 */
class FoodAlchemistVocabEinheit extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_einheiten';

    protected $fillable = [
        'uuid', 'team_id', 'slug', 'display_de', 'dimension',
        'default_in_g', 'default_in_ml', 'is_approximate', 'sort_order', 'note', 'is_inactive',
    ];

    protected $casts = [
        'uuid' => 'string',
        'default_in_g' => 'decimal:3',
        'default_in_ml' => 'decimal:3',
        'is_approximate' => 'boolean',
        'is_inactive' => 'boolean',
        'sort_order' => 'integer',
    ];
}
