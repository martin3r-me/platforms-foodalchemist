<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 82 §3 / Schritt 4d — eine Zeile einer Preisliste samt Abgleich-Ergebnis.
 * Bewusst ohne LogsActivity: Massendaten, der Lauf ist der Audit-Anker (wie {@see FoodAlchemistPrice}).
 */
class FoodAlchemistPriceImportLine extends Model
{
    use HasUuidV7, SoftDeletes;

    public const STATUS_TREFFER = 'treffer';

    public const STATUS_OHNE_TREFFER = 'ohne_treffer';

    public const STATUS_MEHRDEUTIG = 'mehrdeutig';

    public const STATUS_LIEFERANT_UNBEKANNT = 'lieferant_unbekannt';

    public const STATUS_FEHLER = 'fehler';

    public const STATUS_DOPPELT = 'doppelt';

    /** Nach dem Anwenden (Schreib-Schicht): */
    public const STATUS_ANGEWENDET = 'angewendet';

    public const STATUS_UNVERAENDERT = 'unveraendert';

    /** gültig ab liegt in der Zukunft: wird erst bei einem späteren Anwenden geschrieben */
    public const STATUS_ZUKUENFTIG = 'zukuenftig';

    protected $table = 'foodalchemist_price_import_lines';

    protected $guarded = ['id'];

    /** Stammdaten-Läufe (art=stammdaten): die Zeile ist geprüft, das Ergebnis steht in `aktion`. */
    public const STATUS_GEPRUEFT = 'geprueft';

    /** Prüfer hat die Zeile verworfen (1b): nichts wurde geschrieben. */
    public const STATUS_VERWORFEN = 'verworfen';

    public const ENTSCHEIDUNG_OFFEN = 'offen';
    public const ENTSCHEIDUNG_UEBERNEHMEN = 'uebernehmen';
    public const ENTSCHEIDUNG_VERWERFEN = 'verwerfen';

    public const AKTION_NEU = 'neu';
    public const AKTION_AENDERT = 'aendert';
    public const AKTION_UNVERAENDERT = 'unveraendert';
    public const AKTION_KONFLIKT = 'konflikt';
    public const AKTION_FEHLER = 'fehler';

    protected $casts = ['price' => 'decimal:4', 'valid_from' => 'date', 'daten' => 'array', 'diff' => 'array', 'hinweise' => 'array', 'allergene_bestaetigt' => 'boolean'];
}
