<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * Spec 82 §13 C3 — ein Lauf „Mandant beenden“ (Schritte + Fortschritt). Reine Verwaltungsdaten, nie Inhalte.
 * Geschrieben nur über {@see \Platform\FoodAlchemist\Services\MandantBeendenService}.
 */
class FoodAlchemistMandantBeendenLauf extends Model
{
    use LogsActivity;   // Spec 82 §13 C4: das Plattform-Protokoll hängt an diesem Modell (PlattformProtokoll)

    public const STATUS_OFFEN = 'offen';

    public const STATUS_ABGESCHLOSSEN = 'abgeschlossen';

    public const STATUS_ABGEBROCHEN = 'abgebrochen';

    protected $table = 'foodalchemist_mandant_beenden_laeufe';

    protected $guarded = ['id'];

    protected $casts = [
        'schritte' => 'array',
        'fortschritt' => 'array',
        'abgeschlossen_at' => 'datetime',
    ];
}
