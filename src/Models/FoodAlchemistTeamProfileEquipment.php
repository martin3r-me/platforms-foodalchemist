<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 82 §6 — Equipment-Angabe im Teamprofil: vorhanden | nicht_vorhanden; keine Zeile = unbekannt. Hart gelöscht (unique je Team+Equipment), nicht soft.
 */
class FoodAlchemistTeamProfileEquipment extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $table = 'foodalchemist_team_profile_equipment';

    protected $guarded = ['id'];

    protected $casts = ['anzahl' => 'integer'];
}
