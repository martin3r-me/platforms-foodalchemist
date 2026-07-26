<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Rausch-Guard-Policy je Signal-Typ (Spec 21 · E2): `threshold`
 * (ab wann eine aggregierte Zustands-Zeile statt n Einzel-Alarmen), `accepted_until`
 * (Lage bewusst akzeptiert bis …), `muted` (Typ interessiert nicht — unterdrückt als
 * einziger Regler auch das Drift-Signal), `note` (Begründung, wird angezeigt).
 * Menschliche Entscheidung — kein Detektor schreibt hier.
 *
 * Vererbung wie beim Katalog: ein Kind-Team sieht die Policy des Eltern-Teams
 * (visibleToTeam) und kann sie mit einer eigenen Zeile überstimmen; geschrieben
 * wird immer ins eigene Team (isOwnedBy).
 */
class FoodAlchemistSignalPolicy extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_signal_policies';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'threshold' => 'integer',
        'accepted_until' => 'date',
        'muted' => 'boolean',
    ];

    /**
     * Akzeptanz-Frist gesetzt und noch nicht abgelaufen. Der Stichtag zählt **mit**
     * („akzeptiert bis 31.08." heißt inklusive 31.08.) — deshalb endOfDay statt isPast
     * auf der auf 00:00 gecasteten Datums-Spalte.
     */
    public function akzeptanzGueltig(): bool
    {
        return $this->accepted_until !== null && $this->accepted_until->copy()->endOfDay()->isFuture();
    }

    /** Akzeptanz-Frist gesetzt, aber verstrichen — die Lage wird wieder zum Alarm. */
    public function akzeptanzAbgelaufen(): bool
    {
        return $this->accepted_until !== null && ! $this->akzeptanzGueltig();
    }
}
