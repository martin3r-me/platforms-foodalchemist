<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 94b · Kategorie einer Leistung (Personal, Equipment, Logistik … und eigene je Team). Global (team_id NULL) oder
 * team-eigen, Vererbung nach unten; geerbte schaltet ein Team über `foodalchemist_leistungskategorie_team_aus` für sich aus.
 * Vorlagen und Leistungen tragen den Slug (`kategorie`). Pflege: LeistungsKategorieService.
 */
class FoodAlchemistVocabLeistungskategorie extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_leistungskategorien';

    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_inactive' => 'boolean',
    ];
}
