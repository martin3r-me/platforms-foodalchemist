<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Marken-Kürzel (GP-Regelwerk v3.7.1 §5 Ausnahme 3): wie die Marke in der Artikelbezeichnung steht (kuerzel, klein ohne Punkt) und wie sie
 * in der GP-Klammer geschrieben wird (marke). supplier_id NULL = gilt für alle Lieferanten. Global, Pflege nur Plattform-Admin/Master-Admin.
 * Satellit ohne Team-Hierarchie (PolicyTest): Regel-Daten, kein Team-Katalog; Audit + weiches Löschen.
 */
class FoodAlchemistSupplierMarke extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    public const TYP_EIGENMARKE = 'eigenmarke';

    public const TYP_HERSTELLERMARKE = 'herstellermarke';

    public const POSITION_ANFANG = 'anfang';

    public const POSITION_ENDE = 'ende';

    protected $table = 'foodalchemist_supplier_marken';

    protected $casts = ['vorrang' => 'integer'];

    protected $guarded = ['id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }
}
