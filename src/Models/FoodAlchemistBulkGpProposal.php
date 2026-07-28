<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * V-032 · Spec 22 H3c-2 — EIN Vorschlag eines Anreicherungs-Laufs am **Grundprodukt**.
 *
 * Zeile für Zeile derselbe Speicher wie {@see FoodAlchemistBulkProposal}; der Unterschied
 * besteht aus dem Fremdschlüssel (`gp_id` statt `recipe_id`) und der Accept-Logik, die
 * fachlich ein eigener Weg ist (Zustand · Tags · Allergene · Nährwerte, jeweils mit
 * eigener Override-First-Regel). Die Tabellen bleiben darum getrennt — zusammenlegen wäre
 * ein nullbarer Fremdschlüssel, der bei jedem Leser eine Fallunterscheidung erzwänge.
 *
 * Die volle Begründung des Umbaus (Cast-Formgleichheit, Team-Lesart, Activity-Log,
 * fehlende Relationen) steht am Rezept-Zwilling und gilt hier unverändert. Ein Unterschied
 * ist zu benennen: dieser Speicher war auch nach H3a noch **ganz** ohne Model, weil er
 * 2026-07-01 als Kopie des Rezept-Pfades entstanden ist.
 *
 * ⚠️ Der GP-Pfad bewertet einen Vorschlag als leer, wenn der Wert `null`, `''` **oder ein
 * leeres Array** ist; der Rezept-Pfad kennt den dritten Fall nicht. Dieselbe Frage, zwei
 * Antworten — bekannt als V-072, im Golden-Test eingefroren und hier bewusst nicht
 * angeglichen: die Vereinheitlichung ist eine Auswahl-Regel-Entscheidung und gehört in
 * einen beaufsichtigten Chunk, nicht in einen Model-Umbau.
 */
class FoodAlchemistBulkGpProposal extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_bulk_gp_proposals';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'value' => 'array',
        'confidence' => 'float',
        'status' => BulkProposalStatus::class,
        'run_id' => 'integer',
        'gp_id' => 'integer',
        'call_log_id' => 'integer',
    ];
}
