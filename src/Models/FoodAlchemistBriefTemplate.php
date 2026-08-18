<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Schnellstart-Vorlage (Brief-Template) — benannter Startpunkt für die Planung-Erzeugung:
 * Brief + Kreativ-Modus + kompletter Leitplanken-Snapshot (`payload.regler`), je Scope. team_id NULL =
 * kuratierte Global-Vorlage (read-only); team-eigene sind editierbar. Sichtbarkeit D1 (Global ∪ eigene Kette).
 */
class FoodAlchemistBriefTemplate extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_brief_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'payload' => 'array',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Kuratierte Global-Vorlage (BHG-Default, für Kunden read-only)? */
    public function istGlobal(): bool
    {
        return $this->team_id === null;
    }
}
