<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Regel als Daten (Spec 81): eine mechanische Regelwerk-Regel, die der Regel-Motor beim Anlegen,
 * Prüfen und Matching ausführt. `art` wählt die Motor-Klasse, `params` trägt die Werte, `schluessel` ist der feste
 * Name für den Code. Neue Regeln starten inaktiv.
 */
class FoodAlchemistRule extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ARTEN = ['vokabular', 'ersetzung', 'pflichtangabe', 'verbot', 'zuordnung', 'schwelle'];

    public const WIRKUNGEN = ['korrigieren', 'warnen', 'blockieren'];

    protected $table = 'foodalchemist_rules';

    protected $fillable = [
        'uuid', 'team_id', 'schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
        'params', 'beispiele', 'aktiv', 'version', 'notiz', 'dossier_slug', 'created_via',
    ];

    protected $casts = [
        'uuid' => 'string',
        'params' => 'array',
        'beispiele' => 'array',
        'aktiv' => 'boolean',
        'version' => 'integer',
    ];
}
