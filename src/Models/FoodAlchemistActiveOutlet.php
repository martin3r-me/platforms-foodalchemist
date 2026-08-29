<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durabler „aktiver Betrieb" je (User, Team) — Backing-Store für ActiveOutletContext, damit die
 * Betriebs-Brille auch cross-session / per MCP (outlets.SET_ACTIVE) gesetzt und gelesen werden kann.
 * Bewusst schlank: kein Team-Scope-Trait (der Zugriff ist immer user- UND team-gefiltert).
 */
class FoodAlchemistActiveOutlet extends Model
{
    protected $table = 'foodalchemist_active_outlets';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'team_id' => 'integer',
        'outlet_id' => 'integer',
    ];
}
