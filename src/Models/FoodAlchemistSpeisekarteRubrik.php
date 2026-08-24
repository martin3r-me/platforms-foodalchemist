<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speisekarten-Rubrik — Gliederungsknoten der Karte (Baum, self-FK
 * parent_id). Z. B. Vorspeisen · Hauptgänge · Desserts; Hauptgänge → Fleisch/Fisch/
 * Vegetarisch als Unter-Rubrik. `art` steuert das Layout (speisen|getraenke|menue…).
 */
class FoodAlchemistSpeisekarteRubrik extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_card_sections';

    protected $guarded = ['id'];

    /** Rubrik-Art (Layout-Hinweis fürs Druck-Dokument). */
    public const ARTEN = ['speisen', 'getraenke', 'menue', 'dessert', 'sonstiges'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'snapshot_at' => 'datetime',
        'snapshot_json' => 'array',
    ];

    public function menuCard(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeisekarte::class, 'menu_card_id');
    }

    /** Deutscher Alias → menuCard(). */
    public function speisekarte(): BelongsTo
    {
        return $this->menuCard();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // Kaskade 2026-08-24: format() entfernt — die spezielle Format-Rubrik-Mechanik entfällt
    // (Format wird künftig wie ein Concept gebucht, live-referenziert, F5). Spalte `format_id`
    // bleibt vorerst schlafend (kein Schema-Drop).

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeisekartePosition::class, 'section_id')->orderBy('position');
    }

    /** Deutscher Alias → items(). */
    public function positionen(): HasMany
    {
        return $this->items();
    }
}
