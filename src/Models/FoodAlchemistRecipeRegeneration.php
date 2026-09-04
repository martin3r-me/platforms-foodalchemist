<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description V-19 (M6-01, D-6 §2.3): Multi-Komponenten-Regeneration —
 * ein Programm je Komponente (Label, Gerät, °C, min, Kerntemp), zeilenbasierte
 * KI-Lineage. UI/Services lesen NUR diese Tabelle, nie die Alt-Skalarspalten.
 */
class FoodAlchemistRecipeRegeneration extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_regenerations';

    protected $guarded = ['id'];

    /** Regenerations-Gerät (Kombidämpfer, Bain Marie …) — für Editor UND Druck. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabRegenerationDevice::class, 'device_vocab_id');
    }

    /** Deutscher Alias (Modul-Konvention). */
    public function geraet(): BelongsTo
    {
        return $this->device();
    }
}
