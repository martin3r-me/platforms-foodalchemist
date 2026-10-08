<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Bestellvorlage (Spec 67 B): wiederkehrende Bestellung als Artikel + Standardmenge,
 * auch über mehrere Lieferanten. Anwenden legt je Lieferant einen Entwurf zum Liefertag an.
 */
class FoodAlchemistOrderTemplate extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const WOCHENTAGE = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    protected $table = 'foodalchemist_order_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'weekday' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistOrderTemplateLine::class, 'order_template_id')->orderBy('position')->orderBy('id');
    }
}
