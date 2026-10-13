<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * Spec 82 §9/§13 B3 + G — Verwaltungszeile je Mandant (Kunden-Hauptteam). Nur Verwaltungsdaten, nie Inhalte.
 * Ohne Zeile gilt aktiv + autark; geschrieben wird nur über {@see \Platform\FoodAlchemist\Services\MandantenService}.
 */
class FoodAlchemistMandant extends Model
{
    use LogsActivity;   // Spec 82 §13 C4: Plattform-Protokoll hängt an diesem Modell (PlattformProtokoll)

    public const STATUS = ['projekt', 'aktiv', 'beendet'];

    /** „beendet“ = Vollsperre ({@see \Platform\FoodAlchemist\Support\TeamSperre}). */
    public const STATUS_BEENDET = 'beendet';

    public const MODI = ['kuratiert', 'autark'];

    public const STATUS_STANDARD = 'aktiv';

    public const MODUS_STANDARD = 'autark';

    protected $table = 'foodalchemist_mandanten';

    protected $guarded = ['id'];

    protected $casts = [
        'modus_geaendert_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        // Der Freitext der Notiz bleibt aus dem activity-log heraus (nur Verwaltungswerte), auch bei den automatischen created/updated-Einträgen.
        // Per Konstruktor, weil das Trait `$ignoreAttributes` selbst deklariert und eine zweite Deklaration im Modell ein PHP-Fatal wäre.
        // Nur mit dem echten Paket: Das Sandbox-Stub-Trait kennt die Eigenschaft nicht, und eine Zuweisung würde Eloquent als Spalte `ignoreAttributes` einfügen.
        if (property_exists($this, 'ignoreAttributes')) {
            $this->ignoreAttributes = array_merge((array) $this->ignoreAttributes, ['notiz']);
        }
    }
}
