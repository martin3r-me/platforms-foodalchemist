<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Slice F (publish-per-Betrieb): eine zusätzliche, BETRIEB-scopte Präsentation
 * eines Ausgabe-Dokuments (Foodbook/Speisekarte/Speiseplan) — eigener öffentlicher Token/Slug,
 * eingefrorener Snapshot mit den Preisen UND der Vorlage DIESES Betriebs, eigene Freigabe.
 * Additiv NEBEN der inline-Kopf-Präsentation (Concern HasPresentation). Auflösung: der
 * öffentliche Token/Slug wird von PresentationService::resolveByToken zusätzlich hier gesucht.
 */
class FoodAlchemistPresentation extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes, BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_presentations';

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
