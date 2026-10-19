<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 85 FAV-1: Favorit eines Teams (Rang) bzw. Ausschluss eines vom Hauptteam übernommenen Favoriten.
 * Wirksame Liste über Support\FavoritenOverlay, Schreibwege über FavoriteGpService.
 */
class FoodAlchemistTeamFavorit extends Model
{
    use HasUuidV7, LogsActivity;

    protected $table = 'foodalchemist_team_favoriten';

    protected $guarded = ['id'];

    protected $casts = ['ausgeschlossen' => 'boolean', 'rang' => 'integer'];

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id')->ohneTeamSicht('verweis');
    }
}
