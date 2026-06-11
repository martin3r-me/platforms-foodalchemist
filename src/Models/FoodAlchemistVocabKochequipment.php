<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Koch-Equipment-Vokabular (D-5 §2.3, 40 Einträge) —
 * Equipment-Chips im Rezept-Editor; CRUD folgt mit V-20 (D-1).
 */
class FoodAlchemistVocabKochequipment extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_kochequipment';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'boolean'];
}
