<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasPresentation;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 94 · Konfigurator: veröffentlichbares Paket aus Hauptkonzepten, Add-ons, Leistungen und Pax-Paketen
 * mit All-in-Preis je Concept und Paket. Rechnen nur im KonfiguratorPricingService.
 */
class FoodAlchemistKonfigurator extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes, HasPresentation;

    public const STATUS = ['entwurf', 'live', 'archiv'];

    public const RUNDUNGEN = [0.01, 0.10, 0.50, 1.00];

    /** Stufe 5b: Reihenfolge der Schritte im Kundenlink (Standard: Tageszeit zuerst). */
    public const REIHENFOLGEN = ['tageszeit_zuerst', 'konzept_zuerst'];

    protected $table = 'foodalchemist_konfiguratoren';

    protected $guarded = ['id'];

    protected $casts = [
        'pax_vorgabe' => 'integer',
        'stunden_lunch' => 'decimal:2',
        'stunden_dinner' => 'decimal:2',
        'stunden_day_night' => 'decimal:2',
        'tageszeit_aktiv' => 'boolean',
        'kombi_rabatt_pct' => 'decimal:2',
        'paket_toleranz_pct' => 'decimal:2',
        'rundung' => 'decimal:2',
        'gueltig_bis' => 'date',
        'outlet_id' => 'integer',
    ];

    /** Spec 94: Betrieb, dessen Logo ohne eigenes Logo gilt (Rückfall wie beim Foodbook-Kopf). */
    public function outlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOutlet::class, 'outlet_id');
    }

    /**
     * Logo, das im Kundenlink gilt: das eigene, sonst das des Betriebs (eine Stelle für Snapshot und Editor-Vorschau).
     *
     * @return array{context_file_id:?int, path:?string, aus_betrieb:bool}
     */
    public function wirksamesLogo(): array
    {
        if ($this->logo_path || $this->logo_context_file_id) {
            return ['context_file_id' => $this->logo_context_file_id, 'path' => $this->logo_path, 'aus_betrieb' => false];
        }
        $o = $this->outlet_id ? $this->outlet : null;
        if ($o !== null && ($o->logo_path || $o->logo_context_file_id)) {
            return ['context_file_id' => $o->logo_context_file_id, 'path' => $o->logo_path, 'aus_betrieb' => true];
        }

        return ['context_file_id' => null, 'path' => null, 'aus_betrieb' => false];
    }

    public function konzepte(): HasMany
    {
        return $this->hasMany(FoodAlchemistKonfiguratorKonzept::class, 'konfigurator_id')->orderBy('position')->orderBy('id');
    }

    public function leistungen(): HasMany
    {
        return $this->hasMany(FoodAlchemistKonfiguratorLeistung::class, 'konfigurator_id')->orderBy('position')->orderBy('id');
    }

    public function pakete(): HasMany
    {
        return $this->hasMany(FoodAlchemistKonfiguratorPaket::class, 'konfigurator_id')->orderBy('von_pax');
    }
}
