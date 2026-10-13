<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Pflegbares ZUSATZ-Feld eines Canvas-Typs (Spec 82 §7, Variante A): die Kern-Felder bleiben in
 * CanvasService::TEMPLATES, diese Tabelle ergänzt Felder je canvas_type. Oberteam-eigen (team_id), die Unter-Teams
 * sehen sie über die Team-Kette; der Master gibt Vorlagen per Kopie mit (Schritt 8).
 */
class FoodAlchemistCanvasTemplate extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_canvas_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'sub' => 'array',
        'position' => 'integer',
        'active' => 'boolean',
    ];

    /** Feld-Definition im Format der TEMPLATES-Konstante. */
    public function alsFeld(): array
    {
        $feld = ['key' => $this->field_key, 'label' => $this->label, 'group_name' => $this->group_name, 'type' => $this->type ?: 'longtext', 'zusatz' => true];
        if ($this->type === 'repeatable') {
            $feld['sub'] = $this->sub ?: ['claim' => 'Claim', 'description' => 'Beschreibung'];
        }

        return $feld;
    }
}
