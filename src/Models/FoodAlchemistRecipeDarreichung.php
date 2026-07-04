<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Darreichung eines VK-Gerichts (Umbau-Spec Phase 3): dünne
 * Varianten-Zeile am Kerngericht — Servierform, Grammatur, Behälter/Regeneration,
 * EK/VK je Form. Genau eine Standard-Darreichung pro Gericht. Preis-Wahrheit
 * liegt HIER (recipes.sales_net ist nur noch Anzeige-Spiegel der Standard-Form).
 * WaWi = Master (Recompute 206 Stufe 4 rechnet ek_portion/Auto-VK); FA-native
 * Anlage nur mit created_via-Marker (F12).
 */
class FoodAlchemistRecipeDarreichung extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_presentations';

    protected $guarded = ['id'];

    protected $casts = [
        'is_standard' => 'bool',
        'quantity_pro_unit_g' => 'float',
        'unit_count' => 'float',
        'ek_portion' => 'float',
        'sales_net' => 'float',
        'sales_gross' => 'float',
    ];

    public function recipe()
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function servierform()
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'serving_form_id');
    }

    public function aufschlagsklasse()
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'markup_class_id');
    }

    public function unit()
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    public function deltas()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichungDelta::class, 'presentation_id');
    }

    /** Default-Geschirr der Form — der Concepter schlägt es am Slot vor. */
    public function geschirrItem()
    {
        return $this->belongsTo(FoodAlchemistGeschirrItem::class, 'tableware_item_id');
    }
}
