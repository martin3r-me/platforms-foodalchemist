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
    /**
     * ERLAUBTE WERTE der geschlossenen Leitplanken-Vokabulare.
     *
     * `ALLOWED_GENERATION_PARAMS` prüft nur die KEYS. Ein falscher WERT (»Gala« statt
     * `dinner`) lief bis hierher stumm durch — und damit ins Leere: das Achsen-Mapping
     * (config `ai.knowledge_axis_map`) löst `occasion`/`sektor` deterministisch auf und
     * findet für einen unbekannten Wert nichts. Der Prompt bekäme dann weder eine
     * Fehlermeldung noch das Playbook.
     *
     * Das wird wichtig, sobald Leitplanken nicht mehr nur aus Dropdowns kommen, sondern
     * aus Freitext/Sprache extrahiert werden — dort kann ein Modell Werte erfinden.
     *
     * Bewusst NICHT gelistet und damit ungeprüft, weil im Code jeweils MEHRERE Wertesätze
     * existieren und keiner erkennbar der gültige ist:
     *   · `frische` — `fresh_first|frozen_first|preserved_first` (RecipesGenerateTool) vs.
     *     `frisch|tk|konserviert` (Resolver/Matcher).
     *   · `sektor` — `betriebsgastronomie|catering|restaurant|care|schule_kita`
     *     (RecipesGenerateTool:76) vs. `business|care|crew|event_privat|kita_schule|restaurant`
     *     (RecipeEignungPutTool:22). Das Achsen-Mapping nutzt den ersten Satz; ein Wert aus
     *     dem zweiten läuft dort ins Leere, aber ihn hier zu VERWERFEN wäre schlimmer —
     *     `PlanungSessionMcpTest` schreibt legitim `sektor=fine_dining`.
     *   · `kompositions_stil` — Vokabular unklar (`ausgewogen`, `gewagt`, evtl. mehr).
     *
     * Unter-prüfen ist hier richtig: eine Validierung, die Legitimes verwirft, ist schlimmer
     * als keine. Die Vokabular-Widersprüche selbst sind ein eigener Aufräum-Schritt —
     * solange sie bestehen, darf hier nichts hart geprüft werden.
     *
     * `level` ist dagegen geprüft: zwei unabhängige Quellen (Leitplanken-UI und
     * RecipeEignungPutTool:21) nennen denselben Satz.
     *
     * Quellen der Werte: Leitplanken-UI (`livewire/planung/partials/leitplanken.blade.php`,
     * `Livewire/Planung/Index.php`) und `Tools/RecipesGenerateTool.php`. Die Listen liegen
     * dort mehrfach — diese Konstante ist ab jetzt die Referenz; die UI-Kopien
     * zusammenzuführen ist ein eigener Schritt.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_GENERATION_VALUES = [
        'convenience' => ['from_scratch', 'teil_convenience', 'voll_convenience'],
        'level' => ['haute_cuisine', 'gehoben', 'klassisch'],
        'bio_praeferenz' => ['konventionell', 'bio', 'egal'],
        'bestand' => ['hybrid', 'komplett_neu', 'nur_bestand'],
        'occasion' => ['fruehstueck', 'lunch', 'konferenz', 'empfang', 'dinner', 'late_night'],
        'serviceform' => ['tellerservice', 'buffet', 'flying', 'stehempfang', 'boxed'],
        // Mehrfachauswahl — jeder Eintrag wird einzeln geprüft.
        'diaet_hart' => ['vegan', 'vegetarisch', 'glutenfrei', 'laktosefrei', 'halal', 'low_carb'],
    ];

    public const ALLOWED_GENERATION_PARAMS = [
        'convenience', 'frische', 'frische_erlaubt', 'bio', 'bio_pref', 'bio_praeferenz', 'bestand', 'level', 'sektor',
        'diaet_hart', 'allergen_nogo', 'aroma', 'aroma_kueche', 'pax', 'ziel_portion_g', 'saison', 'ziel_we_pct',
        'use_favorites_list', 'favorites_convenience_only',
        'occasion', 'serviceform', 'kompositions_stil', 'ziel_vk_eur',
        // KI-Bilder-Toggle (Preisfrage): steuert, ob die Anreicherung Schritt-Fotos + Produktfoto erzeugt.
        'ki_bilder',
        // Anreicherungs-Tiefe bei der Freigabe (Default an): steuert, ob die Voll-Anreicherung die schwere
        // Text-Coverage schreibt (Step-by-Step, Sensorik, Equipment, Prozess-/Aromaanker, Pairings, Eignung).
        // false = „leichte" Anreicherung (nur Text-Lücken + Kohärenz + Wirtschaftlichkeit). Der GP-Mint
        // (EK-Vollständigkeit) läuft bei der Freigabe UNABHÄNGIG davon immer, damit die Kalkulation stimmt.
        'complete_coverage',
        // Speisekarte-Füllung: 'gerichte' (Default — je Rubrik N einzelne VK-Gerichte, gericht_ref) oder
        // 'concepte' (je Rubrik 1 Concept/Fix-Menü, menue_ref = altes Verhalten). Nur im speisekarte-Zweig wirksam.
        'speisekarte_fuellung',
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
