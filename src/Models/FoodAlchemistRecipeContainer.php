<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 51: welcher Behälter für welchen ZWECK — je Rezept eine Zeile pro
 * abfuellen | regenerieren | ausgabe | transport. Löst die zwei Skalar-Spalten am Rezept ab
 * (`container_warm_*` / `container_cold_*`), die nur eine Temperatur-Achse kannten und deshalb
 * nicht abbilden konnten, dass die Suppe erst in Eimer kommt und später ins GN.
 */
class FoodAlchemistRecipeContainer extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Prozess-Achse. Identisch mit `FoodAlchemistVocabContainer::ZWECKE`. */
    public const ZWECK_ABFUELLEN = 'abfuellen';

    public const ZWECK_REGENERIEREN = 'regenerieren';

    public const ZWECK_AUSGABE = 'ausgabe';

    public const ZWECK_TRANSPORT = 'transport';

    /** Was beim Wechsel auf ein anderes Format mitwächst. */
    public const SKALIERUNG_TIEFER = 'tiefer_fuellbar';

    public const SKALIERUNG_FLAECHE = 'hoehe_gebunden';

    public const SKALIERUNG_LAGEN = 'lagenware';

    protected $table = 'foodalchemist_recipe_containers';

    protected $guarded = ['id'];

    protected $casts = [
        'referenz_menge_kg' => 'float',
        'max_schichthoehe_mm' => 'float',
        'stueck_je_behaelter' => 'integer',
        'ai_confidence' => 'float',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabContainer::class, 'container_vocab_id');
    }

    /** Deutscher Alias (Modul-Konvention). */
    public function behaelter(): BelongsTo
    {
        return $this->container();
    }
}
