<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only Fachprotokoll des Produktions-Cockpits (Spec 35 K4). */
class FoodAlchemistProductionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'foodalchemist_production_events';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
}
