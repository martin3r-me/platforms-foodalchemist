<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @ai.description Marker „Feld stammt aus dem Produktpass" (Quelle `produktpass_ki`) — Schutz vor dem Necta-Abgleich.
 */
class FoodAlchemistProduktpassFeld extends Model
{
    public const QUELLE = 'produktpass_ki';

    protected $table = 'foodalchemist_produktpass_felder';

    protected $guarded = ['id'];

    protected $casts = ['abgeleitet' => 'boolean'];

    public function produktpass(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProduktpass::class, 'produktpass_id');
    }
}
