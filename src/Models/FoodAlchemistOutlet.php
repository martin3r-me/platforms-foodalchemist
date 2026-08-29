<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Betrieb/Standort (Spec 19 „Foodbook-Leitstelle", E3.6 — als Outlet-Tag
 * gestartet). Team-eigenes Vokabular mit optionaler Farbe, keine Seeds.
 *
 * **Rolle gewachsen (Spec 33 P2):** ursprünglich ausdrücklich NUR ein optionaler Tag am
 * Foodbook-Kapitel. Mit der Portfolio-Steuerung trägt der Betrieb die **Betriebsbrille** —
 * die Achse, auf der „wer fährt gerade was" beantwortet wird. Alle drei Ausgabeformen haben
 * jetzt ein `outlet_id` am Kopf. Am Kapitel bleibt der Tag, was er war; in der Übersicht
 * zählt der Kopf.
 *
 * Weiterhin KEINE Hierarchie (Region → Betrieb → Küche) und nicht Teil von
 * `leitplanken()`/der Dimensions-Kaskade. Pflege: Einstellungen → Betriebe & Standorte.
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

    /** Ebene 2: Kalkulations-Override dieses Betriebs (eine Zeile, alle Felder nullable). */
    public function settings(): HasOne
    {
        return $this->hasOne(FoodAlchemistOutletSetting::class, 'outlet_id');
    }
}
