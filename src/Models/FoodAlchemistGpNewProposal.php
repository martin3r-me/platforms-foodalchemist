<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description NEW-GP-Vorschlag aus dem LLM-/MCP-Pfad (Phase-0-Staging).
 * Entsteht, wenn Zutat-Matching keinen GP findet. Wird NICHT zum GP —
 * die Kuration läuft in der WaWi (LA-First), der fertige GP kommt per
 * Einbahn-Sync zurück. Schreibwege über GpProposalService.
 */
class FoodAlchemistGpNewProposal extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_gp_new_proposals';

    protected $guarded = ['id'];

    protected $casts = [
        'match_snapshot' => 'array',
    ];

    public const STATUS_OFFEN = 'offen';
    public const STATUS_UEBERNOMMEN = 'uebernommen';
    public const STATUS_VERWORFEN = 'verworfen';
}
