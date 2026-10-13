<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spec 82 §9/§13 B3 + G — Verwaltungszeile je Mandant (Kunden-Hauptteam). Nur Verwaltungsdaten, nie Inhalte.
 * Ohne Zeile gilt aktiv + autark; geschrieben wird nur über {@see \Platform\FoodAlchemist\Services\MandantenService}.
 */
class FoodAlchemistMandant extends Model
{
    public const STATUS = ['projekt', 'aktiv', 'beendet'];

    public const MODI = ['kuratiert', 'autark'];

    public const STATUS_STANDARD = 'aktiv';

    public const MODUS_STANDARD = 'autark';

    protected $table = 'foodalchemist_mandanten';

    protected $guarded = ['id'];

    protected $casts = [
        'modus_geaendert_at' => 'datetime',
    ];
}
