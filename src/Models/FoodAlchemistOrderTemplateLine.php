<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Position einer Bestellvorlage (Spec 68): Grundprodukt + Menge (kg/g/stk) — der Artikel
 * kommt beim Anwenden aus der Lead-Strategie —, Rezept/Gericht + Portionen/Ansätze/kg (Bedarf aus der
 * Rezeptur), fester Lieferantenartikel + Gebinde oder Konzept/Paket × Personen (Spec 73).
 */
class FoodAlchemistOrderTemplateLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const TYPEN = ['gp', 'recipe', 'supplier_item', 'concept', 'paket'];

    public const EINHEITEN = [
        'gp' => ['kg' => 'kg', 'g' => 'g', 'stk' => 'Stück'],
        'recipe' => ['portions' => 'Portionen', 'ansaetze' => 'Ansätze', 'kg' => 'kg'],
        'supplier_item' => ['gebinde' => 'Gebinde'],
        'concept' => ['persons' => 'Personen'],
        'paket' => ['persons' => 'Personen'],
    ];

    /** Spalte des Bezugs je Typ. */
    public const SPALTE = ['gp' => 'gp_id', 'recipe' => 'recipe_id', 'supplier_item' => 'supplier_item_id', 'concept' => 'concept_id', 'paket' => 'paket_id'];

    protected $table = 'foodalchemist_order_template_lines';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'qty' => 'decimal:3'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrderTemplate::class, 'order_template_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function supplierItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'paket_id');
    }

    /** Quelle im Format der Bestellrunde (OrderService::previewFromSources). */
    public function alsQuelle(): array
    {
        return [
            'type' => $this->type,
            'id' => (int) $this->{self::SPALTE[$this->type] ?? 'supplier_item_id'},
            'qty' => (float) $this->qty,
            'unit' => $this->unit,
        ];
    }

    public function bezeichnung(): string
    {
        return (string) match ($this->type) {
            'gp' => $this->gp?->name,
            'recipe' => $this->recipe?->name,
            'concept' => $this->concept?->name,
            'paket' => $this->paket?->name,
            default => $this->supplierItem?->designation,
        } ?: '— nicht mehr vorhanden —';
    }
}
