<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Protokollzeile des Abgleich-Dienstes (Lieferantenartikel → GP). Bewusst ohne Team-Hierarchie-Trait: das Log gehört dem Team, das entschieden hat
 * (`team_id`), und wird nur über den Dienst gelesen/geschrieben.
 */
class FoodAlchemistAbgleichLog extends Model
{
    protected $table = 'foodalchemist_abgleich_log';

    protected $guarded = ['id'];

    protected $casts = [
        'automatisch' => 'boolean',
        'ausgefuehrt' => 'boolean',
        'abweichungen' => 'array',
        'kandidaten' => 'array',
        'felder' => 'array',
        'sicherheit' => 'float',
        'rueckgenommen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->uuid ??= (string) Str::uuid();
        });
    }
}
