<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Klassen-Vokabular (M10R-1, Doc 15 §10.3) — freie, team-
 * erweiterbare Klassen-Dimension (NEU neben den Rollen) für Concept UND Paket
 * (z. B. „Buffet" · „3-Gang-Menü" · „Flying"). Wie vocab_rollen: die Klasse wird
 * als String am Concept/Paket gehalten (kein harter FK — „frei/wählbar"), diese
 * Liste dient als Autocomplete/Pflege-Quelle + linkes Filter-Panel.
 * Seit 2026-06-14 ein BAUM (self-`parent_id`, beliebige Tiefe) — gepflegt in den
 * Einstellungen → „Konzept-Taxonomie".
 */
class FoodAlchemistVocabKlasse extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_vocab_klassen';

    protected $guarded = ['id'];

    protected $casts = [
        'is_inactive' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }
}
