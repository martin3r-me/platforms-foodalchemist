<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Regenerations-Geräte-Vokabular (M6-01, 9 Einträge: Kombidämpfer,
 * Bain Marie, Induktion …). Bis 2026-09-04 gab es dafür kein Model — Editor und
 * Einstellungen lasen die Tabelle per `DB::table()`. Für den Druck brauchte die
 * V-19-Zeile aber den Geräte-NAMEN über eine Relation, sonst hätte jede
 * Report-Node einen eigenen Lookup gebraucht (die Node-Funktion ist rekursiv).
 */
class FoodAlchemistVocabRegenerationDevice extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_regeneration_devices';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'boolean'];
}
