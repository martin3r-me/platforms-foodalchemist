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

    /**
     * Richtungs-Regler (Leitplanken), die am Planung-Go gesetzt und in den Kaskaden-
     * Fan-out vererbt werden. Whitelist gegen beliebiges JSON — die Keys spiegeln die
     * Modal-RICHTUNGEN + die Hooks/Prompt-Keys von RecipeGeneratorService::generiere.
     * Flow-Steuerung (planning_session_id/cascade_step_id) gehört NICHT hierher.
     */
    public const ALLOWED_GENERATION_PARAMS = [
        'convenience', 'frische', 'bio', 'bio_pref', 'bio_praeferenz', 'bestand', 'level', 'sektor',
        'diaet_hart', 'aroma', 'use_favorites_list', 'favorites_convenience_only',
        'occasion', 'serviceform', 'kompositions_stil', 'ziel_vk_eur',
        // KI-Bilder-Toggle (Preisfrage): steuert, ob die Anreicherung Schritt-Fotos + Produktfoto erzeugt.
        'ki_bilder',
        // Concept-Typ (#35): Menü (Gänge) vs. Buffet (Stationen) — steuert Slot-Typ + Positionen-Label.
        // Wie die übrigen menue_*-Achsen ein Concept-Concern (kein Per-Teller-Wert) → im Dish-Fan-out
        // gefiltert, persistiert aber am Concept für Concept-Generierung + Resume.
        'menue_typ',
        // Menü-Leitplanken (nur Concept-Scope, Etappe 2a): Anzahl Gänge/Positionen + Zielpreis-Korridor
        // je Person. Steuern die ZUSAMMENSTELLUNG des Menüs, NICHT die Rezept-Generierung — anders als
        // die übrigen Regler propagieren sie daher NICHT in den Gericht-/Basisrezept-Fan-out (der
        // Fan-out-Erbe filtert `menue_*` heraus, {@see PlanningCascadeService::sessionGenerationParams}).
        'menue_gaenge', 'menue_preis_min_pp', 'menue_preis_ziel_pp', 'menue_preis_max_pp',
        // Diät-Quoten (Etappe 2a, Teil 2): Portfolio-ANTEIL veganer/vegetarischer Positionen am Menü
        // (0–100 %). Unterscheidet sich bewusst vom harten Ausschluss `diaet_hart` (ganzes Menü muss X
        // sein) — die Quote ist eine weiche Zusammenstellungs-Vorgabe (»mind. X % der Gänge vegan«).
        'menue_quote_vegan_pct', 'menue_quote_vegetarisch_pct',
        // Portfolio-Balance (Etappe 2a, Rest Teil 2): Menü-Vielfalt (ausgewogen|fokussiert) — wie breit
        // das Menü über Proteine/Warengruppen/Garmethoden streut. Weiche Zusammenstellungs-Vorgabe.
        'menue_balance',
    ];

    protected $casts = [
        'uuid' => 'string',
        'generation_params' => 'array',
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
