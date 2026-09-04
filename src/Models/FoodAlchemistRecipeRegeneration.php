<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description V-19 (M6-01, D-6 §2.3): Multi-Komponenten-Regeneration —
 * ein Programm je Komponente (Label, Gerät, °C, min, Kerntemp), zeilenbasierte
 * KI-Lineage. UI/Services lesen NUR diese Tabelle, nie die Alt-Skalarspalten.
 *
 * Spec 51: `ingredient_id` ist seit der Anlage im Schema und war bis dahin tot — kein Leser,
 * kein Schreiber. Sie trägt jetzt die Bedeutung der Zeile:
 *
 *   ingredient_id GESETZT  am Gericht → Override für GENAU diese Komponente (Rang 1)
 *   ingredient_id NULL     am Gericht → »Gesamt«: das Gericht wird als Ganzes regeneriert
 *                                        (Lasagne, Auflauf, Wrap) — Rang 0
 *   ingredient_id NULL     am Basisrezept → »das bin ich«: der Default, den Gerichte erben
 *
 * Vertrag zum Modus (es gibt kein `modus`-Feld): `device_vocab_id IS NULL` heisst
 * »kalt servieren«. KEINE Zeile heisst »fehlt« — das ist der Unterschied zwischen einer
 * getroffenen Entscheidung und einer Lücke, und er muss sichtbar bleiben.
 */
class FoodAlchemistRecipeRegeneration extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_regenerations';

    protected $guarded = ['id'];

    /** Regenerations-Gerät (Kombidämpfer, Bain Marie …) — für Editor UND Druck. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabRegenerationDevice::class, 'device_vocab_id');
    }

    /** Deutscher Alias (Modul-Konvention). */
    public function geraet(): BelongsTo
    {
        return $this->device();
    }

    /**
     * Die Komponenten-Zeile, auf die sich diese Regeneration bezieht.
     * NULL = »Gesamt« bzw. »das bin ich«, siehe Klassen-Docblock.
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeIngredient::class, 'ingredient_id');
    }

    /** Kalt servieren ist eine Entscheidung, keine fehlende Angabe. */
    public function istKalt(): bool
    {
        return $this->device_vocab_id === null;
    }

    /** Trägt diese Zeile das ganze Rezept statt einer Komponente? */
    public function istGesamt(): bool
    {
        return $this->ingredient_id === null;
    }
}
