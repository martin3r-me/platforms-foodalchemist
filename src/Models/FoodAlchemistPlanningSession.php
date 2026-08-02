<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Planungs-/Kreativ-Session (Doppel-Diamant, Spec 08): der Container VOR dem
 * Grounding. Hält Trend-Herkunft (`source_knowledge_document_id`, loser Zeiger auf ein
 * knowledge_documents category='trend'), freien Brief + Analyse, und trägt die Skizzen der
 * Divergenz-Phase (bestehende `FoodAlchemistDishIdea`-Ebene als dritter Owner).
 *
 * **Invariante:** Die Session erdet NICHTS. Erst das „Go" erzeugt Basisrezept/Gericht/Concept
 * (immer status=draft). Kein PlanningFrame-Owner — der Frame entsteht am Concept beim Go.
 *
 * Team-lokal (D1: BelongsToTeamHierarchy liefert visibleToTeam/isOwnedBy).
 */
class FoodAlchemistPlanningSession extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_planning_sessions';

    protected $guarded = ['id'];

    /** Lebenszyklus: Divergenz (Skizzen sammeln) → Konvergenz (Go läuft) → erledigt. */
    public const STATUSES = ['divergenz', 'konvergenz', 'erledigt'];

    /** Kreativ-Modus (speist PairingInspirationService, spiegelt die E9-Modi). */
    public const CREATIVE_MODES = ['voll_kreativ', 'hybrid', 'datenbank'];

    protected $casts = [
        'uuid' => 'string',
    ];

    /** Skizzen dieser Session (dritter Owner der bestehenden Kreativ-Ebene). */
    public function ideas(): HasMany
    {
        return $this->hasMany(FoodAlchemistDishIdea::class, 'planning_session_id');
    }

    /** Paket-Gruppen dieser Session. */
    public function ideaGroups(): HasMany
    {
        return $this->hasMany(FoodAlchemistDishIdeaGroup::class, 'planning_session_id');
    }
}
