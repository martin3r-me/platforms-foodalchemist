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
        'quantity_per_unit_g' => 'float',
        'unit_count' => 'float',
        'anrichten_wert' => 'float',            // Spec 83 B3i: Einheit hängt an der Servierform
        'anrichten_pauschal_min' => 'float',    // Spec 83 B3l: Pauschale je Auftragszeile
        'ek_portion' => 'float',
        'sales_net' => 'float',
        'calculated_sales_net' => 'float',
        'sales_gross' => 'float',
        'price_calculated_at' => 'datetime',
        'price_override_at' => 'datetime',
        'price_override_expires_at' => 'datetime',
    ];

    public function recipe()
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    public function servingForm()
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'serving_form_id')->ohneTeamSicht('verweis');
    }

    /** @deprecated #486 deutscher Alias → servingForm() */
    public function servierform()
    {
        return $this->servingForm();
    }

    public function markupClass()
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'markup_class_id')->ohneTeamSicht('verweis');
    }

    /** @deprecated #486 deutscher Alias → markupClass() */
    public function aufschlagsklasse()
    {
        return $this->markupClass();
    }

    public function priceAudits()
    {
        return $this->hasMany(FoodAlchemistPriceChangeAudit::class, 'entity_id')
            ->where('entity_type', 'presentation')->latest();
    }

    public function unit()
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id')->ohneTeamSicht('verweis');
    }

    public function deltas()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichungDelta::class, 'presentation_id');
    }

    /** Gefäß der Form (Non-Food-GP, WG 16): definiert zusammen mit der Menge die Darreichung. Ersetzt das alte Default-Geschirr. */
    public function vessel()
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'vessel_gp_id')->ohneTeamSicht('verweis');
    }

    /** Zubehör & Verpackung dieser Form (Non-Food-GPs, Menge je Einheit) — wirkt nur auf den EK. */
    public function zubehoer()
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichungZubehoer::class, 'presentation_id')->orderBy('position')->orderBy('id');
    }
}
