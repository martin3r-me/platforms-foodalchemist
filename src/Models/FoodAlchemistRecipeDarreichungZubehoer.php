<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zubehör & Verpackung einer Darreichung (z. B. Schraubglas zur Form „im Schraubglas“): Non-Food-Grundprodukt (WG 16),
 * Menge je Einheit der Darreichung. Fließt in den EK der Darreichung, nie in Allergene, Nährwerte, Profil oder Yield.
 */
class FoodAlchemistRecipeDarreichungZubehoer extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_presentation_zubehoer';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'float',
        'position' => 'integer',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
