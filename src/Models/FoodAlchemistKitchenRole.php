<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Küchen-Rolle mit Kostensatz (Stufe 3 P3.1). Küchenchef / Koch / Hilfskoch …
 * — eine ROLLE, kein Mensch. Trägt einen €/Std-Satz; die Posten-Besetzung (Anzahl je Rolle)
 * leitet daraus Kapazität und Produktionskosten ab.
 *
 * ⚠️ Keine Personen: kein `user_id`, keine Schichten/Verfügbarkeiten, keine Aggregation je
 * Person. Nur Rollen-/Posten-Ebene (siehe Migrations-Docblock). Team-eigen, geerbte Rollen
 * sind Vorlagen (`visibleToTeam`), editierbar nur im eigenen Team.
 */
class FoodAlchemistKitchenRole extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_kitchen_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'stundensatz_eur' => 'decimal:2',
        'sort_order' => 'integer',
        'is_inactive' => 'boolean',
    ];
}
