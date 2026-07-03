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
 * liegt HIER (recipes.vk_netto ist nur noch Anzeige-Spiegel der Standard-Form).
 * WaWi = Master (Recompute 206 Stufe 4 rechnet ek_portion/Auto-VK); FA-native
 * Anlage nur mit created_via-Marker (F12).
 */
class FoodAlchemistRecipeDarreichung extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_darreichungen';

    protected $guarded = ['id'];

    protected $casts = [
        'ist_standard' => 'bool',
        'menge_pro_einheit_g' => 'float',
        'anzahl_einheiten' => 'float',
        'ek_portion' => 'float',
        'vk_netto' => 'float',
        'vk_brutto' => 'float',
    ];

    public function recipe()
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function servierform()
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'servierform_id');
    }

    public function aufschlagsklasse()
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'aufschlagsklasse_id');
    }

    public function einheit()
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'einheit_vocab_id');
    }

    public function deltas()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichungDelta::class, 'darreichung_id');
    }
}
