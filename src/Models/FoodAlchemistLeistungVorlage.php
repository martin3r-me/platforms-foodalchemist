<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\HatLeistungsRegel;

/**
 * @ai.description Spec 94 · Leistungs-Vorlage (Personal, Equipment, Logistik, Sonstiges) mit Mengenregel, EK und VK.
 * Wird in Konfiguratoren kopiert. Vererbung über die Team-Kette: das Oberteam pflegt, Unterteams lesen und ergänzen eigene.
 */
class FoodAlchemistLeistungVorlage extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes, HatLeistungsRegel;

    protected $table = 'foodalchemist_leistung_vorlagen';

    protected $guarded = ['id'];

    protected $casts = [
        'aktiv' => 'boolean',
    ];

    public function markupClass(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'markup_class_id');
    }

    public function bezugLeistung(): BelongsTo
    {
        return $this->belongsTo(self::class, 'bezug_leistung_id');
    }
}
