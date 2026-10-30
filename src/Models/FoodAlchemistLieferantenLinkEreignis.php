<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Protokollzeile eines Lieferanten-Links (append-only: erzeugt, geöffnet, Upload, abgelehnt, widerrufen). Selbst das Protokoll, deshalb ohne LogsActivity.
 */
class FoodAlchemistLieferantenLinkEreignis extends Model
{
    use HasUuidV7, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_lieferanten_link_ereignisse';

    protected $guarded = ['id'];

    protected $casts = ['detail' => 'array'];
}
