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
 * @ai.description Foodbook (M11) — Angebots-/Menü-Mappe, komponiert Concepts +
 * Gerichte zu einem versendbaren Kunden-Dokument. Kunde + Pax (Gästezahl, F-12)
 * leben HIER (D-CON-5), nicht am person-unabhängigen Concept. team-eigen.
 */
class FoodAlchemistFoodbook extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbooks';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'jahr' => 'integer',
        'personen' => 'integer',
    ];

    /** Top-Kapitel (parent_id NULL), Baum baut der Service/die UI. */
    public function kapitel(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookKapitel::class, 'foodbook_id')->orderBy('position');
    }

    /** Schreibstil-Override je Kunde/Foodbook (M10R-1, §10.8). */
    public function schreibstil(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'schreibstil_id');
    }
}
