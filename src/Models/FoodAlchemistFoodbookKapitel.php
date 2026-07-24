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

/**
 * @ai.description Foodbook-Kapitel (M11) — Makro-Struktur des Foodbooks als BAUM
 * (self-FK parent_id). Preis pro Person + Versand-Snapshot (status=sent friert
 * snapshot_json ein). Interne (titel) vs. Konsumenten-Felder (konsumententitel/claim).
 */
class FoodAlchemistFoodbookKapitel extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbook_chapters';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'price_per_person' => 'decimal:2',
        'snapshot_at' => 'datetime',
        'snapshot_json' => 'array',
    ];

    public function foodbook(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbook::class, 'foodbook_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookBlock::class, 'chapter_id')->orderBy('position');
    }

    /** Zielgruppen des Kapitels (Spec 19, M1) — 1–n, überschreiben Foodbook-Default in der Kaskade. */
    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistTargetGroup::class,
            'foodalchemist_chapter_target_groups',
            'chapter_id',
            'target_group_id'
        );
    }
}
