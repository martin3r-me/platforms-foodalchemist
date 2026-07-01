<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Warengruppe (§3 GP-Regelwerk) — Lookup für die GP-Klassifikation, global (D1).
 */
class FoodAlchemistLookupWarengruppe extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_lookup_warengruppen';

    protected $fillable = ['uuid', 'team_id', 'code', 'name', 'sort_order'];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
    ];

    public function gps(): HasMany
    {
        return $this->hasMany(FoodAlchemistGp::class, 'warengruppe_code', 'code');
    }

    /**
     * Anzeige-Label „<Code> <Name>" ohne Doppel-Code: trägt der Name den Code
     * schon (Seed-Altlast: name = "02 Obst"), wird er unverändert gezeigt.
     * Fixt die Baum-/Dropdown-Doppelung „02 02 Obst" (Detail-Panel nutzt nur name).
     */
    public function codedLabel(): string
    {
        $name = trim((string) $this->name);
        $code = trim((string) $this->code);

        return ($code === '' || \Illuminate\Support\Str::startsWith($name, $code))
            ? $name
            : trim("{$code} {$name}");
    }
}
