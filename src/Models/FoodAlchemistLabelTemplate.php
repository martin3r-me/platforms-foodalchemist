<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Etikett-Vorlage (Spec 70): Format (A4-Bogen 24/40, Rolle 62 mm, Dymo 54×25), Typ
 * intern|verkauf (verkauf erzwingt LMIV-Pflichtfelder), Felder mit Datums-Modus (vorbelegt | leer zum
 * Handschreiben), Gestaltung über Betrieb (Logo) und Design. Pflichtfelder sind nicht abwählbar.
 */
class FoodAlchemistLabelTemplate extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_label_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'felder' => 'array',
        'datum_gross' => 'boolean',
        'zeige_logo' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOutlet::class, 'outlet_id');
    }
}
