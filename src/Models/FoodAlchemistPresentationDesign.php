<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 43 — Wiederverwendbares Präsentations-Design (Ausgabe des visuellen Struktur-
 * Builders). layout_json = geordnete, gestylte Blockliste; tokens_json = globale
 * Design-Tokens. Form-agnostisch, team-gescopt (globale/Root-Designs sichtbar in
 * Kind-Teams, schreibbar nur im eigenen Team). Applikation an einen Output über
 * dessen presentation_design = "design:{id}".
 */
class FoodAlchemistPresentationDesign extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_presentation_designs';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'layout_json' => 'array',
        'tokens_json' => 'array',
        'output_types' => 'array',
        'is_default' => 'boolean',
    ];
}
