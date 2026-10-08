<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Druckprotokoll eines Etiketts (Spec 76): Quelle (Rezept, Grundprodukt, Stellplatz, Charge), Vorlage,
 * Anzahl, Eingaben (Datum, Lagerart, Menge …) und die gedruckten Kerndaten. Sammeldruck = gleiche `gruppe`.
 */
class FoodAlchemistLabelPrint extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_label_prints';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'eingabe' => 'array', 'verbrauchen_bis' => 'date', 'anzahl' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }
}
