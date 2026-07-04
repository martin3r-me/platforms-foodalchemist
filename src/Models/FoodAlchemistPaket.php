<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Paket (M10-01, D-CON-1) — bepreistes Bündel MEHRERER Gerichte,
 * das eine Rolle füllt (Baukasten für den Verkäufer; z. B. „Salad Wall"). Trägt
 * einen GESPEICHERTEN Per-Person-Preis (preis_pro_person) + EK + W%, damit ein
 * Tausch im Concept nur die Differenz rechnet — kein Kaskaden-Recompute. Preis
 * kommt auto aus den Gerichten (MargeService/GL-11) oder manuell; preis_stale
 * markiert eine nötige Neuberechnung (GP-Preis-Änderung, GL-02-Muster).
 * team-eigen (BelongsToTeamHierarchy: sichtbar Kette aufwärts, editierbar = Besitzer).
 */
class FoodAlchemistPaket extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_packages';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'preis_pro_person' => 'decimal:2',
        'ek_pro_person' => 'decimal:4',
        'wareneinsatz_prozent' => 'decimal:2',
        'preis_berechnet_am' => 'datetime',
        'preis_stale' => 'boolean',
        'is_inactive' => 'boolean',
        // M10R-1: Aggregat-Caches (Nährwerte/Person, Arbeitszeit)
        'work_time_min_cache' => 'integer',
        'naehrwerte_cache' => 'array',
    ];

    /** Die Gerichte (VK-Rezepte) in diesem Paket. */
    public function gerichte(): HasMany
    {
        return $this->hasMany(FoodAlchemistPaketGericht::class, 'package_id')->orderBy('position');
    }

    /** Slots, die diesen Paket referenzieren (für „wo verwendet?" / Löschschutz). */
    public function slots(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSlot::class, 'package_id');
    }

    /**
     * #380 — Angebot, dem dieses Paket als angebots-lokaler Entwurf gehört.
     * NULL = standardisiert (im Concepter-Katalog). „Promote" = Flip auf NULL.
     */
    public function angebot(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistAngebot::class, 'offer_id');
    }

    /** #380 — Standardisierter Katalog (offer_id NULL). */
    public function scopeStandardisiert(Builder $q): Builder
    {
        return $q->whereNull('offer_id');
    }

    /** #380 — Angebots-lokaler (spekulativer) Entwurf. */
    public function scopeAngebotsLokal(Builder $q): Builder
    {
        return $q->whereNotNull('offer_id');
    }
}
