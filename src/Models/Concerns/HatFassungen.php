<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate: gemeinsame Fassungs-Basis (wie FoodAlchemistRecipe).
 * Fassung = eingefrorene Zeile (ist_fassung = 1, fassung_von_id = Arbeitszeile). Der Scope blendet sie überall aus;
 * ausdrücklich gelesen wird mit mitFassungen(). Verweise (belongsTo) lösen auch Fassungen auf.
 */
trait HatFassungen
{
    public static function bootHatFassungen(): void
    {
        static::addGlobalScope(new OhneFassungenScope);
    }

    /** Ausdrücklich MIT Fassungs-Zeilen lesen (Fassungs-Services, Änderungsprotokoll, Abo-Hülle). */
    public static function mitFassungen(): Builder
    {
        return static::withoutGlobalScope(OhneFassungenScope::class);
    }

    public function initializeHatFassungen(): void
    {
        $this->mergeCasts([
            'ist_fassung' => 'boolean',
            'fassung_allergen_geaendert' => 'boolean',
            'veroeffentlicht_at' => 'datetime',
        ]);
    }

    /** Veröffentlichte Fassungen dieser Arbeitszeile, neueste zuerst. */
    public function fassungen(): HasMany
    {
        return $this->hasMany(static::class, 'fassung_von_id')
            ->withoutGlobalScope(OhneFassungenScope::class)
            ->where('ist_fassung', true)
            ->orderByDesc('fassung_nr');
    }

    public function istFassung(): bool
    {
        return (bool) $this->ist_fassung;
    }
}
