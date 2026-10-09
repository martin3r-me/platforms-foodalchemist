<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @ai.description Gespeicherte Fassung einer Regel (Spec 81 C2). Zurückrollen = eine alte Fassung erneut speichern.
 */
class FoodAlchemistRuleVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'foodalchemist_rule_versions';

    protected $fillable = ['rule_id', 'version', 'wirkung', 'params', 'beispiele', 'aktiv', 'user_id'];

    protected $casts = [
        'params' => 'array',
        'beispiele' => 'array',
        'aktiv' => 'boolean',
        'version' => 'integer',
    ];
}
