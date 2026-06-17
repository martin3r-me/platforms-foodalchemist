<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Canvas-Eintrag: ein semantisches Feld (field_key) eines Canvas.
 * Nicht-wiederholbar = 1 Zeile (position 0); repeatable (Geschmackswelten) = n Zeilen
 * mit position + meta-JSON (Sub-Felder).
 */
class FoodAlchemistCanvasEntry extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_canvas_entries';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'position' => 'integer', 'meta' => 'array'];

    public function canvas(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistCanvas::class, 'canvas_id');
    }
}
