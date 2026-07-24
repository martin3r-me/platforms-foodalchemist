<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Outlet-Vokabular (Spec 19 „Foodbook-Leitstelle", E3.6 — M5-Hälfte).
 * Ausdrücklich NUR ein optionaler Tag (Entscheidung 4): Ausgabestelle eines Kapitels
 * (Restaurant/Bankett/Bar …) mit optionaler Farbe. KEINE primäre Planungs-Ebene und
 * NICHT Teil von `leitplanken()`/der Dimensions-Kaskade. FA-nativ, team-eigen,
 * team-pflegbar über die Einstellungen. Keine Seeds — rein team-definiert.
 */
class FoodAlchemistOutlet extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_outlets';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];

    /** Kapitel, die mit diesem Outlet getaggt sind (loser Tag, keine FK). */
    public function chapters(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookKapitel::class, 'outlet_id');
    }
}
