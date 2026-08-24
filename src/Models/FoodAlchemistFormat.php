<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Format — Marken-/Themen-Container EINE Ebene über dem Concept
 * (z. B. „CHEFS.CORNER – WORLD ON A PLATE"). Bündelt mehrere Zusammenstellungen
 * (Concepts = Editionen/Themenevents) und trägt die Marketing-Identität (Name,
 * Story, Herkunft, Bildwelt). KEIN eigener Preis — nur read-only Min–Max-Range
 * über die `price_per_person_cache` der Editionen (kein Recompute-Trigger).
 * `origin` = eigen|gruppe|kunde (Kunden-IP-Guard: kunde nie fremd wiederverwenden).
 * team-eigen.
 */
class FoodAlchemistFormat extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_formats';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
    ];

    /** Editionen (Concepts) dieses Formats, in Reihenfolge. */
    public function editions(): HasMany
    {
        return $this->hasMany(FoodAlchemistConcept::class, 'format_id')->orderBy('format_position');
    }

    // Format-Umbau F1: Concept-Dimensionen (Facetten) am Format — spiegelt FoodAlchemistConcept.
    public function servingForm(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'serving_form_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistEventtyp::class, 'event_type_id');
    }

    public function serviceMoments(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistEinsatzmoment::class,
            'foodalchemist_format_service_moments',
            'format_id',
            'service_moment_id'
        );
    }

    public function seasons(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistSaison::class,
            'foodalchemist_format_seasons',
            'format_id',
            'season_id'
        );
    }

    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistTargetGroup::class,
            'foodalchemist_format_target_groups',
            'format_id',
            'target_group_id'
        );
    }

    /** Marketing-Bildwelt (Galerie), in Reihenfolge. */
    public function images(): HasMany
    {
        return $this->hasMany(FoodAlchemistFormatImage::class, 'format_id')->orderBy('sort_order');
    }

    /** Marketing-Hero (max. 1 je Format). */
    public function heroImage(): HasOne
    {
        return $this->hasOne(FoodAlchemistFormatImage::class, 'format_id')->where('is_hero', true);
    }

    public function heroUrl(): ?string
    {
        return $this->heroImage?->url();
    }

    public function heroDataUri(): ?string
    {
        return $this->heroImage?->dataUri();
    }

    /**
     * Read-only Preis-Range (€/Person) über die Editionen — reine Reduktion der
     * gespeicherten Caches, KEINE eigene Formel, KEIN Recompute. Editionen sind
     * Alternativen (Showcase), daher Range statt Summe.
     *
     * @return array{min: ?float, max: ?float}
     */
    public function priceRange(): array
    {
        $werte = $this->editions
            ->map(fn ($e) => $e->price_per_person_cache !== null ? (float) $e->price_per_person_cache : null)
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->values();

        return [
            'min' => $werte->isEmpty() ? null : round((float) $werte->min(), 2),
            'max' => $werte->isEmpty() ? null : round((float) $werte->max(), 2),
        ];
    }
}
