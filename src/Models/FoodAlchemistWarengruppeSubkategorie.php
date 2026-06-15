<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Verwaltete Sub-Kategorie je Warengruppe (#371). Ergänzt das frühere
 * reine Freitext-Modell (`gps.sub_kategorie`) um eine anlegbare Werte-Liste. Team-skopiert,
 * eindeutig je (team, warengruppe_code, name).
 */
class FoodAlchemistWarengruppeSubkategorie extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_warengruppe_subkategorien';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
    ];
}
