<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasPresentation;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeStatus;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeZuordnung;

/**
 * @ai.description Speisekarte — dritte Ausgabeform (Gastronomie-à-la-carte-Karte)
 * neben Foodbook (Catering) und Speiseplan (GV). Komponiert Gerichte, Fix-Menüs
 * (Concepts) und Getränke zu einer druckbaren Restaurantkarte. Freistehendes
 * Dokument; Outlet-Zuordnung optional. team-eigen.
 */
class FoodAlchemistSpeisekarte extends Model
{
    use HasUuidV7, HatAusgabeStatus, HatAusgabeZuordnung, HasPresentation, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_cards';

    protected $guarded = ['id'];

    /** Karten-Typen (steuern das Druck-Layout). */
    public const KARTEN_TYPEN = ['alacarte', 'tageskarte', 'saisonkarte', 'getraenkekarte', 'weinkarte'];

    /** #7 (2026-08-27): Brutto-Rundungs-Modi der Ausgabe. keine = auf den Cent. */
    public const RUNDUNGEN = ['keine', 'auf_10', 'auf_50', 'auf_90'];

    protected $casts = [
        'uuid' => 'string',
        'gueltig_von' => 'date',
        'gueltig_bis' => 'date',
        'preis_anzeige_brutto' => 'boolean',
        'phase_override_at' => 'datetime',
    ];

    /**
     * ALLE Rubriken (flach, jede Ebene) nach position — nicht nur Top-Rubriken.
     * Den n-tiefen Baum bauen Service/UI aus `parent_id`.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeisekarteRubrik::class, 'menu_card_id')->orderBy('position');
    }

    /** Deutscher Alias → sections(). */
    public function rubriken(): HasMany
    {
        return $this->sections();
    }

    /** Schreibstil-Override für KI-Wording. */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
    }
}
