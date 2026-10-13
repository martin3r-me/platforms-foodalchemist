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
 * @ai.description Eingefrorener Stand eines Angebots (Spec 82 §12 B3): Fassungs-Stand, EK mit Status und Allergenbild je Gericht mit Abo-Fassung,
 * festgehalten beim Wechsel auf „angebot“. Eine aktive Einfrierung je Angebot, verworfene bleiben als Historie.
 */
class FoodAlchemistAngebotEinfrierung extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_angebot_einfrierungen';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'eingefroren_at' => 'datetime', 'verworfen_at' => 'datetime'];

    public function angebot(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistAngebot::class, 'angebot_id');
    }

    public function zeilen(): HasMany
    {
        return $this->hasMany(FoodAlchemistAngebotEinfrierZeile::class, 'einfrierung_id')->orderBy('id');
    }

    public function istAktiv(): bool
    {
        return $this->verworfen_at === null;
    }
}
