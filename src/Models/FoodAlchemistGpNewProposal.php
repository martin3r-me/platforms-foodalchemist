<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description BESCHAFFUNGS-WUNSCH (Sourcing-Backlog) — reframed 07·M4.
 * Entsteht, wenn eine Zutat WEDER einen Bestands-GP findet NOCH LA-First
 * gemintet werden kann (keine passende Lieferantenartikel). Das ist der einzige
 * echte Dead-End: es fehlt STAMMDATEN, kein Kurations-Klick. Ein Eintrag heißt
 * „diesen Artikel beschaffen/anlegen" (Auftrag an Einkauf/WaWi), NICHT „GP wartet
 * auf Freigabe". GPs entstehen ausschließlich über den LA-First-Mint
 * (LaFirstGpService) — nie durch Promotion eines unbelegten Wunsches.
 * status: offen (Wunsch offen) · uebernommen (Artikel beschafft) · verworfen.
 * Schreibwege über GpProposalService.
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
