<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 82 §12 A1: Abo = Sammlung des Master-Teams, freigegeben an einen Mandanten (Kunden-Hauptteam).
 * Bewusst ohne BelongsToTeamHierarchy: die Zeile gehört zwei Teams (Master und Mandant), gelesen wird sie nur über
 * den AboService. Status aktiv | gekuendigt | zurueckgezogen.
 */
class FoodAlchemistAbo extends Model
{
    use HasUuidV7, LogsActivity;   // Vergabe/Kündigung ist Kuration → activity-log (§13 C4)

    public const AKTIV = 'aktiv';

    public const GEKUENDIGT = 'gekuendigt';

    public const ZURUECKGEZOGEN = 'zurueckgezogen';

    protected $table = 'foodalchemist_abos';

    protected $guarded = ['id'];

    protected $casts = [
        'seit' => 'datetime',
        'beendet_at' => 'datetime',
    ];
}
