<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speiseplan-Prüf-Chip (Spec 59) — zentral je Team gepflegtes Kriterium
 * („Vegan“, „Schwein“, „Suppe“), gegen das ein Speiseplan seine Wochen-Vorgaben prüft
 * (mind./höchstens). `kriterien` = ODER-Liste aus Ernährungsform ({art:diaet,key}) und
 * Hauptgruppe ({art:hauptgruppe,id}); ein Gericht zählt, wenn eine Bedingung zutrifft.
 *
 * Team-eigen mit Eltern-Vererbung (D1): Kind-Teams sehen die Chips des Eltern-Teams lesend
 * und dürfen sie in ihren Plänen verwenden, bearbeiten nur die eigenen.
 *
 * Lösch-Schutz wie bei Posten (V-06): nur stilllegen. Pläne referenzieren den Chip per id im
 * `vorgaben`-JSON (kein FK) — ein gelöschter Chip ließe die Vorgabe still verschwinden.
 * Ein stillgelegter Chip wird in bestehenden Vorgaben weiter ausgewertet, ist aber für neue
 * Vorgaben nicht mehr wählbar. SoftDeletes trägt er trotzdem — Modell-Vertrag aller FA-Models
 * (PolicyTest); im Alltag wird stillgelegt, nicht gelöscht.
 */
class FoodAlchemistSpeiseplanChip extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Ernährungsformen, die ein Chip prüfen kann — Schlüssel = Merkmal aus diaetMerkmale(). */
    public const DIAETEN = [
        'vegan' => 'Vegan',
        'vegetarisch' => 'Vegetarisch',
        'fleisch' => 'Fleisch',
        'fisch' => 'Fisch',
        'schwein' => 'Schwein',
        'rind' => 'Rind',
    ];

    protected $table = 'foodalchemist_menu_plan_chips';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'kriterien' => 'array',
        'default_min' => 'integer',
        'default_max' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'deleted_at' => 'datetime',
    ];
}
