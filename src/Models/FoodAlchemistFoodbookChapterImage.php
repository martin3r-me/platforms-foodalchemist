<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 43 (Bild-Epic) — zusätzliches Bild eines Foodbook-Kapitels (kleine Galerie neben
 * dem Kapitel-Bild image_* auf foodalchemist_foodbook_chapters). team_id erbt vom Kapitel.
 */
class FoodAlchemistFoodbookChapterImage extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $table = 'foodalchemist_foodbook_chapter_images';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }
}
