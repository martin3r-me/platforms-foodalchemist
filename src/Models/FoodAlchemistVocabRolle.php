<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Rollen-Vokabular (M10-01, D-CON-2) — freie, team-erweiterbare
 * Liste der Slot-/Baustein-Rollen (Vorspeise · Grill-Hauptgang · Dessert …).
 * Dient als Autocomplete/Pflege-Quelle; die Rolle selbst wird als String an
 * Slot/Baustein gehalten (kein harter FK — „frei").
 */
class FoodAlchemistVocabRolle extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_rollen';

    protected $guarded = ['id'];

    protected $casts = [
        'is_inactive' => 'boolean',
        'sort_order' => 'integer',
    ];
}
