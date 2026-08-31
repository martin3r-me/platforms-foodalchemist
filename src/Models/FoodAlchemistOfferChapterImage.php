<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * A1 (Angebot-Fork) — zusätzliches Bild eines Angebot-Kapitels (kleine Galerie neben
 * dem Kapitel-Bild image_* auf foodalchemist_offer_chapters). Spiegelt
 * FoodAlchemistFoodbookChapterImage; team_id erbt vom Kapitel.
 */
class FoodAlchemistOfferChapterImage extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_offer_chapter_images';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOfferChapter::class, 'chapter_id');
    }
}
