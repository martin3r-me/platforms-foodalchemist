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
 * @ai.description Spec 94 · Leistung im Konfigurator: Kopie einer Vorlage (oder frei angelegt) mit Mengenregel, EK und VK.
 * Ohne Konzept = Grundausstattung; mit Konzept = Zusatz, optional als Ersatz einer Grundausstattungs-Leistung.
 */
class FoodAlchemistKonfiguratorLeistung extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes, HatLeistungsRegel;

    public const GILT_FUER = ['lunch', 'dinner', 'beide'];

    protected $table = 'foodalchemist_konfigurator_leistungen';

    protected $guarded = ['id'];

    protected $casts = [
        'vorlage_stand_at' => 'datetime',
    ];

    public function konfigurator(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfigurator::class, 'konfigurator_id');
    }

    public function konzept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfiguratorKonzept::class, 'konfigurator_konzept_id');
    }

    public function vorlage(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistLeistungVorlage::class, 'vorlage_id');
    }

    public function istGrundausstattung(): bool
    {
        return $this->konfigurator_konzept_id === null;
    }
}
