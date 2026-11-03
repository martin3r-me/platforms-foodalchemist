<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Angebot-Block (#380 Composer) — polymorphe Inhalts-Zeile eines
 * Angebot-Kapitels, diskriminiert über `type`. Spiegelt FoodAlchemistFoodbookBlock,
 * in eigener Tabelle. concept_ref referenziert ein Concept (live). Format wird NICHT
 * als Block eingesetzt, sondern als Format-Kapitel (Kapitel.format_id) — wie Foodbook.
 */
class FoodAlchemistOfferBlock extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_offer_blocks';

    protected $guarded = ['id'];

    /** Erlaubte Block-Typen (Enum im PHP-Layer). recipe_ref = Parität, im Picker vorerst aus. */
        // Spec 50 · A7: `image` war hier deklariert, aber NIRGENDS beschreibbar oder renderbar
    // (`grep "=> 'image'"` über src/ und resources/ = leer; 0 Zeilen im Bestand). Im
    // Angebot stand der Typ zusätzlich im MCP-Enum: ein Agent konnte ihn setzen und bekam
    // einen Block, den keine Ausgabe zeigt. Bilder leben entitätsweit (`*_images`-Tabellen)
    // bzw. als Presentation-Design-Block — beides unberührt.
    // Spec 94b · position: freie Position Menge × Satz (price_value) mit EK je Einheit (ek_value), pauschal — z. B. Leistungen
    // nach Aufwand aus einer Konfigurator-Anfrage. Kunden-Dokumente zeigen nie den EK.
    public const BLOCK_TYPES = ['concept_ref', 'recipe_ref', 'header', 'header_preis', 'text', 'spacer', 'position'];

    /**
     * Preis-Basen einer position: pauschal (in der Angebotssumme) oder aufwand (zzgl. nach Aufwand, geschätzt — NICHT in Summe,
     * Preis pro Person und gesamt_ek; Dominique 2026-11-03). Ausgewertet nur über {@see istAufwand()}.
     */
    public const POSITION_BASES = ['pauschal', 'aufwand'];

    /** Kanonische Preis-Basen für header_preis (Vokabular-Pflicht). */
    public const PRICE_BASES = ['person', 'pauschal'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'level' => 'integer',
        'visible' => 'boolean',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
        'ek_value' => 'decimal:2',
        'presentation_id' => 'integer',
        'payload_json' => 'array',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOfferChapter::class, 'chapter_id');
    }

    /** concept_ref: das referenzierte Concept (live). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** recipe_ref: einzelnes Gericht (VK-Rezept). */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** Spec 94b: die eine Stelle für „zzgl. nach Aufwand“ — eine Position mit Preis-Basis aufwand zählt nicht in die Summe. */
    public function istAufwand(): bool
    {
        return $this->type === 'position' && $this->price_basis === 'aufwand';
    }

    /** Spec 94b: Einheit einer Position als Text — Vokabular, sonst der mitgegebene Text (z. B. „Std.“ aus dem Konfigurator). */
    public function einheitText(): string
    {
        return (string) ($this->unit?->display_de ?? ($this->payload_json['einheit_text'] ?? ''));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id')->ohneTeamSicht('verweis');
    }

    /** Expliziter Darreichungs-Override (loser Zeiger, keine harte FK). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }
}
