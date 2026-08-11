<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeStatus;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeZuordnung;

/**
 * @ai.description Foodbook (M11) — Angebots-/Menü-Mappe, komponiert Concepts +
 * Gerichte zu einem versendbaren Kunden-Dokument. Kunde + Pax (Gästezahl, F-12)
 * leben HIER (D-CON-5), nicht am person-unabhängigen Concept. team-eigen.
 */
class FoodAlchemistFoodbook extends Model
{
    use HasUuidV7, HatAusgabeStatus, HatAusgabeZuordnung, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbooks';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'jahr' => 'integer',
        'gueltig_von' => 'date',      // Spec 33 P1
        'gueltig_bis' => 'date',
        'personen' => 'integer',
        'target_food_cost_pct' => 'decimal:2',
        'food_cost_tolerance_pp' => 'decimal:2',
        'preview_snapshot_json' => 'array',
        'preview_snapshot_at' => 'datetime',
    ];

    /**
     * ALLE Kapitel (flach, jede Ebene) nach position — NICHT nur Top-Kapitel.
     * Den n-tiefen Baum bauen Service/UI/Coverage aus `parent_id` (Rollup: Kapitel-Scope
     * = Kapitel + alle Nachfahren, Spec 19).
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookKapitel::class, 'foodbook_id')->orderBy('position');
    }

    /** @deprecated #486 deutscher Alias → chapters() */
    public function kapitel(): HasMany
    {
        return $this->chapters();
    }

    /** Schreibstil-Override je Kunde/Foodbook (M10R-1, §10.8). */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
    }

    /** @deprecated #486 deutscher Alias → writingStyle() */
    public function schreibstil(): BelongsTo
    {
        return $this->writingStyle();
    }

    /** Default-Zielgruppen (Spec 19, M1) — 1–n, kaskadieren als Foodbook-Boden. */
    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistTargetGroup::class,
            'foodalchemist_foodbook_target_groups',
            'foodbook_id',
            'target_group_id'
        );
    }

    /** Default-Eventtyp (Spec 19, M2) — frame-weiter Fallback, Kapitel erben. */
    public function defaultEventType(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistEventtyp::class, 'default_event_type_id');
    }

    /** Default-Servierform (Spec 19, M2) — Scharnier zur Darreichungs-Auflösung (DarreichungResolver::fuerBlock). */
    public function defaultServingForm(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'default_serving_form_id');
    }

    /** Einsatzmomente / Tagesablauf des Foodbooks (Spec 19, M2) — 1–n. */
    public function serviceMoments(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistEinsatzmoment::class,
            'foodalchemist_foodbook_service_moments',
            'foodbook_id',
            'service_moment_id'
        );
    }
}
