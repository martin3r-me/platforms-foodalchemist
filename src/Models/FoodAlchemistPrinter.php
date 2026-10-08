<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Druckerprofil (Spec 78): Modell aus der Kompatibilitätsliste, Etikettenformat (oder eigenes Maß),
 * Feinkorrektur der Ränder, Betrieb und Arbeitsplatz (Küche/Lager/Büro). Etikettenvorlagen können einen Drucker wählen.
 */
class FoodAlchemistPrinter extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_printers';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'breite_mm' => 'float', 'hoehe_mm' => 'float', 'versatz_x_mm' => 'float', 'versatz_y_mm' => 'float', 'is_default' => 'boolean'];
}
