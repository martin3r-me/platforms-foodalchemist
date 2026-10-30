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
 * Lieferanten-Link: Zugang für genau einen Lieferanten und ein Team, ohne Login (Stufe 2). Der Token-Klartext existiert nur beim Erzeugen; gespeichert ist sein Hash.
 */
class FoodAlchemistLieferantenLink extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const BEREICHE = ['preisliste' => 'Preisliste hochladen', 'stammdaten' => 'Stammdaten-Datei hochladen', 'dokumente' => 'Datenblätter und Produktpässe', 'formular' => 'Artikel im Formular pflegen'];

    protected $table = 'foodalchemist_lieferanten_links';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['bereiche' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'first_used_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function ereignisse(): HasMany
    {
        return $this->hasMany(FoodAlchemistLieferantenLinkEreignis::class, 'link_id');
    }

    public function widerrufen(): bool
    {
        return $this->revoked_at !== null;
    }

    public function abgelaufen(): bool
    {
        return $this->expires_at->isPast();
    }

    /** aktiv | abgelaufen | widerrufen (Widerruf gewinnt). */
    public function zustand(): string
    {
        return $this->widerrufen() ? 'widerrufen' : ($this->abgelaufen() ? 'abgelaufen' : 'aktiv');
    }
}
