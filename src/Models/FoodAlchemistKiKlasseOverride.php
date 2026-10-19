<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * @ai.description Spec 85: eingestellte KI-Klasse je Arbeitsschritt (global, nur Master-Admin). klasse NULL = Klasse aus dem Code.
 */
class FoodAlchemistKiKlasseOverride extends Model
{
    use LogsActivity;

    protected $table = 'foodalchemist_ki_klassen_override';

    protected $guarded = ['id'];
}
