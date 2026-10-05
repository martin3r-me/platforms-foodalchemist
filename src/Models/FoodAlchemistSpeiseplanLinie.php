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
 * @ai.description Speiseplan-Menü-Linie (M14-03) — Kantinen-/Kita-Achse je Speiseplan
 * (z. B. Menü 1, Vegetarisch, Vital, Dessert). Farbe für die Matrix-Zeile, ist_vegetarisch
 * fürs GV-Tagescheck. Pro Speiseplan frei definierbar (sort_order). team-eigen.
 */
class FoodAlchemistSpeiseplanLinie extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plan_lines';

    protected $guarded = ['id'];

    /** Spec 57 · Paket 2: Rolle der Linie an der Ausgabe. Hauptgänge zählen die Gäste des Tages. */
    public const ROLLEN = [
        'suppe' => 'Suppe',
        'hauptgang' => 'Hauptgang',
        'salat' => 'Salat',
        'beilage' => 'Beilage',
        'dessert' => 'Dessert',
        'sonstiges' => 'Sonstiges',
    ];

    /** Spec 57 · Paket 2 (E1): `auto` = VK des Gerichts, `manuell` = Linienpreis netto. */
    public const PREIS_MODI = ['auto', 'manuell'];

    protected $casts = [
        'uuid' => 'string',
        'is_vegetarian' => 'boolean',
        'sort_order' => 'integer',
        'price_value' => 'float',
        'target_wes_min_pct' => 'float',
        'target_wes_max_pct' => 'float',
        'default_pax' => 'integer',
        'is_standing' => 'boolean',
    ];

    /** Linie gilt für die Mahlzeit, wenn sie keiner festen Mahlzeit zugeordnet ist oder genau dieser. */
    public function giltFuerMahlzeit(string $mahlzeit): bool
    {
        return $this->meal === null || $this->meal === '' || $this->meal === $mahlzeit;
    }

    public function istHauptgang(): bool
    {
        return $this->role === 'hauptgang';
    }

    /** Manueller Linienpreis (netto), wenn gesetzt — sonst null (dann gilt der VK des Inhalts). */
    public function manuellerPreis(): ?float
    {
        return $this->price_mode === 'manuell' && $this->price_value !== null && (float) $this->price_value > 0
            ? (float) $this->price_value
            : null;
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplan::class, 'menu_plan_id');
    }

    /** @deprecated #486 deutscher Alias → mealPlan() */
    public function speiseplan(): BelongsTo
    {
        return $this->mealPlan();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanEintrag::class, 'line_id');
    }

    /** @deprecated #486 deutscher Alias → entries() */
    public function eintraege(): HasMany
    {
        return $this->entries();
    }
}
