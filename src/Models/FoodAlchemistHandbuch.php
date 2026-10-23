<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 86: eingefrorenes, öffentlich lesbares Handbuch (Standards zum Nachschlagen)
 * zu einem Ausgabe-Dokument (Foodbook/Speisekarte/Speiseplan/Angebot). Eigener Token + Ablauf + Widerruf,
 * unabhängig vom Kundenlink der Präsentation. Der Snapshot enthält NIE Preise oder Interna.
 */
class FoodAlchemistHandbuch extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes, BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_handbuecher';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'snapshot_json' => 'array',
        'settings_json' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Öffentlich erreichbar = freigegeben + (kein Ablauf ODER Ablauf in der Zukunft). */
    public function istLive(): bool
    {
        return (bool) $this->enabled
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
