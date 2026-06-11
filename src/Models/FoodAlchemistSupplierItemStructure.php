<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Strukturierte LA-Schicht (Kern-IP): kuratierte Klassifikation eines
 * Lieferantenartikels + GP-Zuordnung (GL-05). needs_review = Review-Queue (V-10).
 */
class FoodAlchemistSupplierItemStructure extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_supplier_item_structures';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ist_lebensmittel' => 'boolean',
        'ist_aroma_haupttraeger' => 'boolean',
        'ist_bio' => 'boolean',
        'ist_halal' => 'boolean',
        'ist_vegan' => 'boolean',
        'needs_review' => 'boolean',
        'hauptzutat_konfidenz' => 'decimal:3',
        'aroma_zutaten_konfidenz' => 'decimal:3',
        'verarbeitung_konfidenz' => 'decimal:3',
        'warengruppe_konfidenz' => 'decimal:3',
        'klassifiziert_am' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
