<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zielgruppen-Vokabular (Spec 19 „Foodbook-Leitstelle", M1).
 * Eigenes Vokabular (Entscheidung 4) — z.B. Tagungsgast/Bankett-Gast/Mitarbeiter/VIP-Gala.
 * Ein Foodbook wählt 1–n Default-Zielgruppen, ein Kapitel 1–n; beim Kapitel-Go wird das
 * aufgelöste Set aufs Konzept gestempelt (Pivot statt Legacy-Freitext concepts.target_group,
 * Entscheidung 6). team-pflegbar über die Einstellungen (E3.3).
 */
class FoodAlchemistTargetGroup extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_target_groups';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];

    /** Foodbooks, die diese Zielgruppe als Default führen. */
    public function foodbooks(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistFoodbook::class,
            'foodalchemist_foodbook_target_groups',
            'target_group_id',
            'foodbook_id'
        );
    }

    /** Kapitel, die diese Zielgruppe führen. */
    public function chapters(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistFoodbookKapitel::class,
            'foodalchemist_chapter_target_groups',
            'target_group_id',
            'chapter_id'
        );
    }

    /** Konzepte, auf die diese Zielgruppe (beim Kapitel-Go) gestempelt wurde. */
    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistConcept::class,
            'foodalchemist_concept_target_groups',
            'target_group_id',
            'concept_id'
        );
    }
}
