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
 * @ai.description Angebot-Kapitel (#380 Composer) — Makro-Struktur der Angebots-
 * Zusammenstellung als BAUM (self-FK parent_id), NACH dem Foodbook-Kapitel-Vorbild,
 * aber in eigener Tabelle. Ein Kapitel mit gesetztem `format_id` ist ein LEBENDES
 * Format-Kapitel: es rendert Identität + Editionen live aus dem verknüpften Format
 * (spiegelt {@see FoodAlchemistFoodbookKapitel::istFormatKapitel}).
 */
class FoodAlchemistOfferChapter extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_offer_chapters';

    protected $guarded = ['id'];

    /** Preis-Modus des Kapitels — weiche Prüfung, Vokabular-Pflicht (Foodbook-Parität). */
    public const PRICE_MODES = ['auto', 'manuell'];

    /** Fortschritts-Stufen des Kapitels (Foodbook-Parität). */
    public const FORTSCHRITT_STUFEN = ['offen', 'in_arbeit', 'fertig'];

    /** Kreativ-Modi der Kapitel-Erstellung (Foodbook-Parität). */
    public const CREATIVE_MODES = ['voll_kreativ', 'hybrid', 'datenbank'];
    public const CREATIVE_MODE_DEFAULT = 'hybrid';

    /**
     * Nur für Format-Kapitel (format_id gesetzt): wie die Editionen des Formats in den
     * Angebotspreis einfallen — additiv = Σ €/Person der Editions-Concepts (Tages-VA,
     * Kunde bekommt alles); alternativen = Preis-Spanne min–max (Showcase, wie Foodbook).
     */
    public const FORMAT_PRICE_MODES = ['additiv', 'alternativen'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'price_per_person' => 'decimal:2',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistAngebot::class, 'offer_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(FoodAlchemistOfferBlock::class, 'chapter_id')->orderBy('position');
    }

    /** Format-Kapitel (live): gesetztes `format_id` ⇒ Kapitel rendert die Format-Editionen. */
    public function format(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFormat::class, 'format_id');
    }

    /** Diskriminator: rendert dieses Kapitel seinen Inhalt live aus dem verknüpften Format? */
    public function istFormatKapitel(): bool
    {
        return $this->format_id !== null;
    }

    /** Kapitel-Servierform-Override (Scharnier DarreichungResolver::fuerBlock). */
    public function servingForm(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistServierform::class, 'serving_form_id');
    }

    /** Kapitel-Einsatzmoment-Override (Foodbook-Parität) — sonst Default in der Kaskade. */
    public function serviceMoment(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistEinsatzmoment::class, 'service_moment_id');
    }

    /** Schreibstil-Override PRO KAPITEL (Foodbook-Parität). NULL = erbt Standard aus den Concepten. */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
    }

    /** Zusätzliche Präsentations-Bilder (kleine Galerie neben dem Kapitel-Bild image_*). */
    public function images(): HasMany
    {
        return $this->hasMany(FoodAlchemistOfferChapterImage::class, 'chapter_id')->orderBy('sort_order')->orderBy('id');
    }
}
