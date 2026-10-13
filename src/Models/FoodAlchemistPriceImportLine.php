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

    protected $casts = ['price' => 'decimal:4', 'valid_from' => 'date'];
}
