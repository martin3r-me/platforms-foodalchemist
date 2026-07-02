<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Katalog-Äquivalenz zweier Realisierungen eines Bausteins (make-or-buy
 * + Artikel-Ersatz). POLYMORPH: source/alt sind je GP oder Rezept (kind-Diskriminator).
 * Deckt GP↔Rezept (Ersatz-Rezept), GP↔GP (Ersatz-Artikel), Rezept↔Rezept ab. Einmal
 * team-weit gepflegt; ermöglicht Tausch der Zutat-Realisierung mit Mengen-Umrechnung
 * über `umrechnungsfaktor`. Schreibwege über ComponentEquivalentService.
 */
class FoodAlchemistComponentEquivalent extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_component_equivalents';

    protected $guarded = ['id'];

    protected $casts = [
        'umrechnungsfaktor' => 'decimal:4',
        'match_confidence' => 'decimal:3',
    ];

    public const KIND_GP = 'gp';
    public const KIND_RECIPE = 'recipe';
    public const SEITE_SOURCE = 'source';
    public const SEITE_ALT = 'alt';

    private const KIND_MODEL = [
        self::KIND_GP => FoodAlchemistGp::class,
        self::KIND_RECIPE => FoodAlchemistRecipe::class,
    ];

    /** Löst eine Seite (GP oder Rezept) zum konkreten Model auf. */
    public static function resolve(string $kind, int $id): ?Model
    {
        $class = self::KIND_MODEL[$kind] ?? null;
        return $class ? $class::find($id) : null;
    }

    public function source(): ?Model
    {
        return self::resolve($this->source_kind, (int) $this->source_id);
    }

    public function alt(): ?Model
    {
        return self::resolve($this->alt_kind, (int) $this->alt_id);
    }

    /** (kind, id) der katalogweit als Default markierten Realisierung. */
    public function defaultSide(): array
    {
        return $this->standard_seite === self::SEITE_ALT
            ? ['kind' => $this->alt_kind, 'id' => (int) $this->alt_id]
            : ['kind' => $this->source_kind, 'id' => (int) $this->source_id];
    }

    /** Menge beim Wechsel source→alt (bzw. zurück) umrechnen. */
    public function convertMenge(float $menge, string $vonSeite): float
    {
        $f = (float) $this->umrechnungsfaktor ?: 1.0;
        return $vonSeite === self::SEITE_SOURCE ? $menge * $f : $menge / $f;
    }

    /** Findet für eine Realisierung (kind,id) die Gegenseite dieser Äquivalenz. */
    public function counterpartOf(string $kind, int $id): ?array
    {
        if ($this->source_kind === $kind && (int) $this->source_id === $id) {
            return ['kind' => $this->alt_kind, 'id' => (int) $this->alt_id, 'von' => self::SEITE_SOURCE];
        }
        if ($this->alt_kind === $kind && (int) $this->alt_id === $id) {
            return ['kind' => $this->source_kind, 'id' => (int) $this->source_id, 'von' => self::SEITE_ALT];
        }
        return null;
    }
}
