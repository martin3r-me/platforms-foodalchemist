<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * Spec 82 §13 C3 — ein Datenexport (ZIP) eines Mandanten. Verwaltungsdaten: Zustand, Größe, Prüfsumme, Manifest (Zählung).
 * Den Inhalt trägt nur die Datei im privaten Storage. Geschrieben nur über {@see \Platform\FoodAlchemist\Services\MandantExportService}.
 */
class FoodAlchemistMandantExport extends Model
{
    use LogsActivity;

    public const STATUS_LAEUFT = 'laeuft';

    public const STATUS_FERTIG = 'fertig';

    public const STATUS_FEHLER = 'fehler';

    protected $table = 'foodalchemist_mandant_exporte';

    protected $guarded = ['id'];

    protected $casts = [
        'manifest' => 'array',
        'fertig_at' => 'datetime',
        'zuletzt_geladen_at' => 'datetime',
    ];
}
