<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Quelle des Trendradars (Spec 93): Fachpresse, Branchenquelle oder Feed eines Teams. Abruf-Weg
 * plattform (Feed oder Übersichtsseite wird ohne KI geholt) oder mcp (ein MCP-Client sichtet und reicht ein), Rhythmus
 * je Quelle, Stichwort-Filter vor dem Eingang.
 */
class FoodAlchemistTrendQuelle extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_trend_quellen';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string', 'aktiv' => 'boolean', 'letzter_abruf_at' => 'datetime',
        'schwerpunkt_kategorien' => 'array', 'schwerpunkt_sparten' => 'array',
        'stichworte_ein' => 'array', 'stichworte_aus' => 'array',
        'fehler_in_folge' => 'integer', 'gefiltert_letzter_abruf' => 'integer', 'gefiltert_gesamt' => 'integer',
    ];

    public function eingaenge(): HasMany
    {
        return $this->hasMany(FoodAlchemistTrendEingang::class, 'quelle_id');
    }
}
