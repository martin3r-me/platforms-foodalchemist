<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Schreibstil (M10R-1) — Brand-Voice-Hülle (GL-06) für die
 * Marketing-Veredelung. `sprach_duktus` ist das Prompt-Material; 11 Stile sind
 * gepflegt. Bisher nur via DB::table genutzt (Settings\Schreibstile) — als Model
 * angelegt, damit Concept/Foodbook eine saubere belongsTo-Relation haben
 * (Wording-Kaskade §10.8: Gericht neutral → Stil am Concept → Foodbook-Override).
 */
class FoodAlchemistWritingStyle extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_writing_styles';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_inactive' => 'boolean',
        'sort_order' => 'integer',
    ];
}
