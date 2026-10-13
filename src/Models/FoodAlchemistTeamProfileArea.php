<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 82 §6 — Fläche/Raum im Teamprofil (Name, max. Pax, Saison).
 */
class FoodAlchemistTeamProfileArea extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $table = 'foodalchemist_team_profile_areas';

    protected $guarded = ['id'];

    protected $casts = ['max_pax' => 'integer', 'saison_von' => 'integer', 'saison_bis' => 'integer', 'sort_order' => 'integer'];
}
