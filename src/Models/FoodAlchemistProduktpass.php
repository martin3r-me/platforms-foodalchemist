<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Produktpass-Version eines Lieferantenartikels: Datei (Core-ContextFile) + was die KI gelesen hat
 * (`extrakt`) + Stand der Übernahme. Allergene bleiben Vorschlag bis zur Bestätigung.
 */
class FoodAlchemistProduktpass extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const STATUS = ['hochgeladen', 'liest', 'gelesen', 'fehler', 'verworfen'];

    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** Dateigröße-Obergrenze in KB (wie die übrigen Belege, MCP-Hilfe DateiAusArgumenten). */
    public const MAX_KB = 15360;

    protected $table = 'foodalchemist_produktpaesse';

    protected $guarded = ['id'];

    protected $casts = [
        'extrakt' => 'array',
        'uebernommen' => 'array',
        'gelesen_at' => 'datetime',
        'uebernommen_at' => 'datetime',
        'allergene_bestaetigt_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function felder(): HasMany
    {
        return $this->hasMany(FoodAlchemistProduktpassFeld::class, 'produktpass_id');
    }
}
