<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept (M10-01) — die verkäufliche Komposition über mehrere
 * Rollen-Slots (z. B. „Grill-Buffet" = Vorspeise + Hauptgang + Dessert). Jeder
 * Slot ist mit einem Paket oder einem festen Gericht gefüllt; der Concept-Preis
 * = Σ der Slot-Preise. `is_template` markiert ein gespeichertes Slot-Gerüst
 * (Vorlage = Kopie-Quelle, Fork beim Start — D-CON-7). team-eigen.
 */
class FoodAlchemistConcept extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_concepts';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_template' => 'boolean',
        'price_per_person_cache' => 'decimal:2',
        'price_per_person_manual' => 'decimal:2',   // manueller Concept-VK (preis_modus=manuell)
        // M10R-1: VK-Parität + KI-Brief + Aggregat-Caches
        'target_price_per_person' => 'decimal:2',
        'ek_per_person_cache' => 'decimal:4',
        'work_time_min_cache' => 'integer',
        'ai_confidence' => 'decimal:3',
        'nutrition_cache' => 'array',
    ];

    public function scopeVorlagen(Builder $q): Builder
    {
        return $q->where('is_template', true);
    }

    public function scopeEchte(Builder $q): Builder
    {
        return $q->where('is_template', false);
    }

    /**
     * #380 — Angebot, dem dieses Concept als angebots-lokaler Entwurf gehört.
     * NULL = standardisiert (im Concepter-Katalog). „Promote/live gehen" = Flip
     * auf NULL (vom Angebot lösen).
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistAngebot::class, 'offer_id');
    }

    /** @deprecated #486 deutscher Alias → offer() */
    public function angebot(): BelongsTo
    {
        return $this->offer();
    }

    /** #380 — Standardisierter Katalog (offer_id NULL). Concepter-Browser MUSS hierauf filtern. */
    public function scopeStandardisiert(Builder $q): Builder
    {
        return $q->whereNull('offer_id');
    }

    /** #380 — Angebots-lokaler (spekulativer) Entwurf. */
    public function scopeAngebotsLokal(Builder $q): Builder
    {
        return $q->whereNotNull('offer_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSlot::class, 'concept_id')->orderBy('position');
    }

    /** Vorlage, aus der dieses Concept geforkt wurde (Lineage, optional). */
    public function templateSource(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'template_source_id');
    }

    /** @deprecated #486 deutscher Alias → templateSource() */
    public function vorlageQuelle(): BelongsTo
    {
        return $this->templateSource();
    }

    /** Organisatorische Kategorie (M10c-B, Baum). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConceptCategory::class, 'category_id');
    }

    /** Schreibstil am Concept (M10R-1, §10.8) — Foodbook kann ihn überschreiben. */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
    }

    /** @deprecated #486 deutscher Alias → writingStyle() */
    public function schreibstil(): BelongsTo
    {
        return $this->writingStyle();
    }

    // ── Facetten-Dimensionen (Umbau-Spec Phase 4) ────────────────────────

    /** Servierform (einfach) — Scharnier zur Darreichungs-Auflösung der Slots. */
    public function servingForm(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'serving_form_id');
    }

    /** @deprecated #486 deutscher Alias → servingForm() */
    public function servierform(): BelongsTo
    {
        return $this->servingForm();
    }

    /** Eventtyp (einfach). */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistEventtyp::class, 'event_type_id');
    }

    /** @deprecated #486 deutscher Alias → eventType() */
    public function eventtyp(): BelongsTo
    {
        return $this->eventType();
    }

    /** Einsatzmomente (mehrfach): Frühstück/Lunch/Apéro/…. */
    public function serviceMoments()
    {
        return $this->belongsToMany(
            FoodAlchemistEinsatzmoment::class,
            'foodalchemist_concept_service_moments',
            'concept_id',
            'service_moment_id'
        );
    }

    /** @deprecated #486 deutscher Alias → serviceMoments() */
    public function einsatzmomente()
    {
        return $this->serviceMoments();
    }

    /** Saisons (mehrfach). */
    public function seasons()
    {
        return $this->belongsToMany(
            FoodAlchemistSaison::class,
            'foodalchemist_concept_seasons',
            'concept_id',
            'season_id'
        );
    }

    /** @deprecated #486 deutscher Alias → seasons() */
    public function saisons()
    {
        return $this->seasons();
    }

    /** Zielgruppen-Stempel (Spec 19, Entscheidung 6) — gesetzt beim Kapitel-Go. */
    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistTargetGroup::class,
            'foodalchemist_concept_target_groups',
            'concept_id',
            'target_group_id'
        );
    }

    /** Mehrwertige Sektor-Eignung (M10R-1, §10.8 — VK-Parität). */
    public function sectorSuitabilities(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSektorEignung::class, 'concept_id');
    }

    /** @deprecated #486 deutscher Alias → sectorSuitabilities() */
    public function sektorEignungen(): HasMany
    {
        return $this->sectorSuitabilities();
    }
}
