<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;

/**
 * @ai.description Marketing-Bild eines Formats (Hero/Galerie). Bildfeld-Paar
 * `path` (legacy) + `context_file_id` (core ContextFile). `is_hero` markiert das
 * Marken-Hero (max. 1 je Format). Spiegelt FoodAlchemistRecipeStepPhoto.
 */
class FoodAlchemistFormatImage extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_format_images';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_hero' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function format(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFormat::class, 'format_id');
    }

    public function contextFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'context_file_id');
    }

    public function url(): string
    {
        return app(FoodAlchemistMediaService::class)->url($this->context_file_id, $this->path);
    }

    public function dataUri(): ?string
    {
        return app(FoodAlchemistMediaService::class)->dataUri($this->context_file_id, $this->path);
    }
}
