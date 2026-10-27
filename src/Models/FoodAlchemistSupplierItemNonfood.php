<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Non-Food-Urteil zu einem Lieferantenartikel: ist_nonfood (NULL unklar / 1 Non-Food / 0 Food), Herkunft (regel, ki, manuell),
 * Konfidenz, Unterkategorie der Warengruppe 16, Prüfstatus. Ohne Zeile gilt der Artikel als Food.
 */
class FoodAlchemistSupplierItemNonfood extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const QUELLE_REGEL = 'regel';

    public const QUELLE_KI = 'ki';

    public const QUELLE_MANUELL = 'manuell';

    protected $table = 'foodalchemist_supplier_item_nonfood';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ist_nonfood' => 'boolean',
        'pruefung_offen' => 'boolean',
        'konfidenz' => 'decimal:3',
        'ki_versucht_at' => 'datetime',
    ];

    /** Gespeicherter alter Unterkategorie-Name ohne Nummer liest sich als neuer (Übergang bis zum Datenlauf, 27.10.2026). */
    protected function subkategorie(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn (?string $wert) => \Platform\FoodAlchemist\Support\NonFoodRegeln::kanonisch($wert));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id')->ohneTeamSicht('verweis');
    }
}
