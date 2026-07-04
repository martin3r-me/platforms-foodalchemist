<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Fixkosten-Zeile (M-K6, Doc 16) — eine periodische Gemeinkosten-
 * Position (z. B. Miete, Spülpersonal, LKW/Logistik), zugeordnet zu einem GK-Block
 * (block_key). Speist die Ableitung des Zuschlag-% (Σ je Block ÷ Bezugsbasis).
 * team-eigen.
 */
class FoodAlchemistFixkosten extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_fixed_costs';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'betrag' => 'decimal:2',
    ];

    /** Betrag auf Monatsbasis normalisiert (jährlich ÷ 12). */
    public function monatsbetrag(): float
    {
        return $this->periode === 'jaehrlich' ? (float) $this->betrag / 12 : (float) $this->betrag;
    }
}
