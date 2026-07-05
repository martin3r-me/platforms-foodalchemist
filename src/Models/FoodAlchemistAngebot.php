<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Angebot (#380) — die kundengebundene Instanz im Kunden-Modul
 * „Angebote": eine individuelle Anfrage (Anlass/Pax/Budget/Datum/Diät) wird zu
 * einem maßgeschneiderten Angebot. Brief-getrieben, eigenständig neben Foodbook
 * (Portfolio). Verknüpft optional eine CRM-Firma/-Kontakt (MVP: nur verlinken).
 *
 * Die Menü-Substanz wird im Concepter gebaut: angebots-lokale Entwürfe sind
 * Concepts/Pakete mit `offer_id = dieses Angebot` (spekulativ, „mal eben
 * schnell"); zusätzlich können standardisierte Concepter-Artefakte referenziert
 * werden (Positions-Ebene folgt beim Composer). team-eigen.
 */
class FoodAlchemistAngebot extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_offers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => AngebotStatus::class,
        'personen' => 'integer',
        'budget' => 'decimal:2',
        'total_price' => 'decimal:2',
        'event_date' => 'date',
        'valid_until' => 'date',
    ];

    /** Verknüpfte CRM-Firma (optional, MVP: nur verlinken — kein Rücksync). */
    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmCompany::class, 'crm_company_id');
    }

    /** Verknüpfter CRM-Kontakt (optional). */
    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmContact::class, 'crm_contact_id');
    }

    /** Angebots-lokale (spekulative) Concept-Entwürfe dieses Angebots. */
    public function concepts(): HasMany
    {
        return $this->hasMany(FoodAlchemistConcept::class, 'offer_id');
    }

    /** Angebots-lokale (spekulative) Paket-Entwürfe dieses Angebots. */
    public function pakete(): HasMany
    {
        return $this->hasMany(FoodAlchemistPaket::class, 'offer_id');
    }

    /** #380 DoD-5: zusätzlich referenzierte STANDARDISIERTE Katalog-Concepts (geteilt, nicht besessen). */
    public function referenzierteConcepts(): BelongsToMany
    {
        return $this->belongsToMany(FoodAlchemistConcept::class, 'foodalchemist_offer_concept', 'offer_id', 'concept_id')
            ->withPivot('position')->withTimestamps()
            ->orderBy('foodalchemist_offer_concept.position');
    }

    /** Noch nicht final entschiedene Angebote (nicht angenommen/abgelehnt). */
    public function scopeOffen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            AngebotStatus::Angenommen->value,
            AngebotStatus::Abgelehnt->value,
        ]);
    }
}
