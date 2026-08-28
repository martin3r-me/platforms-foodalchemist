<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Schicht 3 — ein persistierter Konformitäts-Befund (Erzeuger: {@see \Platform\FoodAlchemist\Services\ConformanceService}).
 *
 * Bewusst artefakt-AGNOSTISCH (artifact_type + artifact_id) statt recipe-FK — derselbe
 * Critic legt Rezepte, VK, GP und LA ab. Und bewusst OHNE geerbte Team-Sichtbarkeit:
 * ein Befund ist das Urteil EINES Teams über ein Artefakt (wie {@see FoodAlchemistRecipeFinding}).
 */
class FoodAlchemistConformanceFinding extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    /** offen = Hinweis-Kandidat; verworfen bleibt verworfen; verschwunden = zuletzt nicht mehr gemeldet. */
    public const STATUS = ['offen', 'verworfen', 'verschwunden'];

    public const SCHWEREGRADE = ['hart', 'weich'];

    protected $table = 'foodalchemist_conformance_findings';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'confidence' => 'float',
        'seen_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'decided_at' => 'datetime',
    ];
}
