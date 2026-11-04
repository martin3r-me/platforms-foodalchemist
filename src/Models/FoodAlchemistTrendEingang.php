<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Support\TrendUrl;

/**
 * @ai.description Eintrag im Eingang des Trendradars (Spec 93): ein Artikel aus einer Quelle oder per MCP eingereicht —
 * Titel, Link, Datum, gekürzter Teaser, nie der Volltext. Systematische Erfassung, getrennt von der Pinnwand
 * (Inspiration); auf die Pinnwand kommt ein Artikel nur per Klick.
 */
class FoodAlchemistTrendEingang extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_trend_eingang';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string', 'veroeffentlicht_am' => 'datetime', 'zuletzt_gesehen_at' => 'datetime', 'erschlossen_at' => 'datetime',
        'sparten' => 'array', 'relevant' => 'boolean', 'observatorium' => 'array',
    ];

    /** Spec 93 E: Link zum Anzeigen — ohne das interne Fragment `#signal=…` eines Einzelsignals (das bleibt nur im gespeicherten url). */
    public function link(): string
    {
        return TrendUrl::ohneSignal($this->url);
    }

    public function quelle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrendQuelle::class, 'quelle_id');
    }

    /** Spec 93 B: automatisches Thema (Inspiration mit herkunft = automatisch) */
    public function themaInspiration(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrendInspiration::class, 'thema_inspiration_id');
    }

    public function inspiration(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrendInspiration::class, 'inspiration_id');
    }
}
