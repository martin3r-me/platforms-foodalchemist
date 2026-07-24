<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Kreativ-Skizze der Foodbook-Leitstelle (Spec 19, M4). Frei geschrieben ODER
 * Bestand-Ref (`sales_recipe_id` = echtes VK-Gericht), Einzel ODER Teil eines Paket-`group_id`.
 * **Invariante: erzeugt NIE Rezepte/GPs/Konzepte — erst das Kapitel-Go (E7.3) materialisiert.**
 * Bis dahin `status='entwurf'`. Das Original bleibt in `source_meta` erhalten (kein stiller
 * Kreativitätsverlust). `generation_status`/`generated_recipe_id` steuern die L7/L8-Freitext-Queue.
 *
 * Content-Spalten englisch (title/description — Schema-Konvention), Enum-Werte deutsch
 * (analog `pricing_mode`); alle Klassifikations-Strings per Const gedeckelt (Vokabular-Pflicht).
 */
class FoodAlchemistDishIdea extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_dish_ideas';

    protected $guarded = ['id'];

    /** Ziel-Materialisierungsform (Spec: ziel_form). paket ⇒ group_id gesetzt. */
    public const TARGET_FORMS = ['einzel', 'paket'];

    /** Lebenszyklus der Skizze. Nur entwurf|verworfen sind über IdeenService setzbar (E6.2). */
    public const STATUSES = ['entwurf', 'verworfen', 'freigegeben'];

    /** Freitext-Queue-Stand (L7/L8). null = keine KI-Erstellung angefragt. */
    public const GENERATION_STATUSES = ['queued', 'erstellt', 'fehlgeschlagen'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'source_meta' => 'array',
        'materialized_ref' => 'array',
        'materialized_at' => 'datetime',
    ];

    /** Owner-Kapitel (XOR mit concept). */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }

    /** Owner-Konzept (XOR mit chapter). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** Paket-Gruppe, falls target_form=paket. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishIdeaGroup::class, 'group_id');
    }

    /** Referenziertes Bestand-VK-Gericht (loser Zeiger, kein Cascade). */
    public function salesRecipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    /** Bei Freitext-Go erzeugtes Rezept (loser Zeiger, kein Cascade). */
    public function generatedRecipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'generated_recipe_id');
    }
}
