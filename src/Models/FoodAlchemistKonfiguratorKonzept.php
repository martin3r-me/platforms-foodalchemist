<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 94 · Eintrag im Konfigurator: Hauptkonzept (Lunch- und/oder Dinner-Concept aus dem Concepter),
 * Add-on (ein Concept, Abschnitt, Bindung, Tageszeit) oder Getränkepaket (Spec 94c: je Zeitfenster ein eigenes Concept — Lunch für
 * tagsüber, Dinner für abends; Pauschale je Event oder je Stunde, optional Staffel). Concepts werden verknüpft, nicht kopiert.
 */
class FoodAlchemistKonfiguratorKonzept extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ROLLEN = ['haupt', 'addon', 'getraenk'];

    /** Spec 94c: Getränkepaket — Abrechnung (pauschale im Preis pro Person, verbrauch als zzgl. mit optionalem Deckel) und Dauer. */
    public const GETRAENK_ABRECHNUNG = ['pauschale', 'verbrauch'];

    public const GETRAENK_DAUER = ['event', 'stunde'];

    public const ADDON_TAGESZEITEN = ['lunch', 'dinner', 'beide'];

    protected $table = 'foodalchemist_konfigurator_konzepte';

    protected $guarded = ['id'];

    protected $casts = [
        'indikativ_lunch' => 'boolean',
        'indikativ_dinner' => 'boolean',
        'position' => 'integer',
        'grund_stunden' => 'decimal:2',
        'folgestunde_satz' => 'decimal:2',
        'verbrauch_deckel' => 'decimal:2',
        'payload_json' => 'array',   // Stufe 5b: wording_overrides je Concept-Slot-Id (wie Foodbook-Block)
    ];

    public function konfigurator(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfigurator::class, 'konfigurator_id');
    }

    public function lunchConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'lunch_concept_id');
    }

    public function dinnerConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'dinner_concept_id');
    }

    /** Spec 94c: Concept eines Getränkepakets zum Zeitfenster (Dominique 03.11.: je Zeitfenster ein eigenes Paket, kein Rückfall). */
    public function getraenkConceptId(string $t): ?int
    {
        $id = $t === 'lunch' ? $this->lunch_concept_id : $this->dinner_concept_id;

        return $id !== null ? (int) $id : null;
    }

    public function istHaupt(): bool
    {
        return $this->rolle === 'haupt';
    }
}
