<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spec 82 §12 A3: Opt-in eines Mandanten (Oberteam) ins Stamm-Paket.
 * Eine Zeile = Paket an. Unter-Teams erben über die Team-Kette (StammPaket::istFreigeschaltet).
 */
class FoodAlchemistTeamStammOptin extends Model
{
    protected $table = 'foodalchemist_team_stamm_optins';

    protected $guarded = ['id'];

    protected $casts = [
        'opted_in_at' => 'datetime',
    ];
}
