<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spec 82 §13 D — Monatspreis eines Gebühren-Bausteins, append-only mit „gültig ab“. Bewusst ohne team_id:
 * die Preise gelten für die ganze Plattform. Geschrieben nur über {@see \Platform\FoodAlchemist\Services\PlanPreisService}.
 */
class FoodAlchemistPlanPreis extends Model
{
    /** baustein → [Beschriftung, billable_type, Platzhalter-Startpreis €/Monat] */
    public const BAUSTEINE = [
        'basis' => ['Basis je Mandant (2 User inklusive)', 'flat_fee', '79.00'],
        'user' => ['Jeder weitere User', 'per_item', '19.00'],
        'unterteam' => ['Unter-Team', 'per_item', '50.00'],
        'stamm_paket' => ['Stamm-Paket', 'flat_fee', '149.00'],
        'master_abo' => ['Master-Abo (alle Sammlungen)', 'flat_fee', '199.00'],
    ];

    /** billable_model-Schlüssel in team_billable_usages. */
    public static function modell(string $baustein): string
    {
        return 'foodalchemist.'.$baustein;
    }

    protected $table = 'foodalchemist_plan_preise';

    protected $guarded = ['id'];

    protected $casts = [
        'betrag_monat' => 'decimal:2',
        'gueltig_ab' => 'date',
        'gueltig_bis' => 'date',
    ];
}
