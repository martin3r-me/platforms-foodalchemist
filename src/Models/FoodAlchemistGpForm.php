<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #9 (Dominique 2026-08-28): eine „Form" eines GP mit Gramm-Gewicht (Stück/Scheibe/Würfel …).
 * form_slug ist ein Einheiten-Vokabular-Slug einer Zähl-Einheit → direkt als Rezept-Einheit +
 * für die EK-Umrechnung nutzbar. source=manual|ki (GL-07). Team-Sichtbarkeit läuft über das GP.
 */
class FoodAlchemistGpForm extends Model
{
    protected $table = 'foodalchemist_gp_forms';

    protected $guarded = ['id'];

    protected $casts = [
        'gramm' => 'decimal:2',
    ];

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
