<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Gerüst-Slot (R4.1) — ein Gang/eine Buffet-Station/ein Kapitel als
 * SOLL-Position: Reihenfolge (Dramaturgie), target_count (Mengengerüst), Preis-Anker/
 * Spanne je Gericht, is_pflicht. chapter_id = optionaler Ist-Bezug aufs Foodbook-Kapitel.
 * dish_main_group_id (12·S3c) = die ROLLE des Slots als Fremdschlüssel: welche
 * Speisen-Hauptgruppe gehört hierher? NULL = nicht gebunden, dann nähert die
 * Label-Semantik sie an (`MenuCandidatePoolService::slotSemantik`).
 */
class FoodAlchemistPlanningFrameSlot extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    public const SLOT_TYPES = ['gang', 'station', 'kapitel'];

    protected $table = 'foodalchemist_planning_frame_slots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'target_count' => 'integer',
        'dish_main_group_id' => 'integer',
        'is_pflicht' => 'boolean',
        'price_anchor' => 'decimal:2',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
    ];

    public function frame(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPlanningFrame::class, 'frame_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FoodAlchemistPlanningFrameRule::class, 'slot_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }

    /**
     * 12·S3c — die persistierte Rolle des Slots. Dieselbe Beziehung, die auf der
     * Gericht-Seite `FoodAlchemistRecipe::speisenHauptgruppe()` heißt; ist sie hier
     * gesetzt, ist „passt das Gericht zum Slot?" ein ID-Vergleich statt eines
     * Label-Vergleichs.
     */
    public function dishMainGroup(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishMainGroup::class, 'dish_main_group_id');
    }
}
