<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * V-032 · Spec 22 H3a — ein Massen-Lauf (Anreicherung · Import · Review).
 *
 * `foodalchemist_bulk_runs` und `foodalchemist_bulk_proposals` waren die einzigen
 * Fach-Tabellen des Moduls ohne Eloquent-Model; jeder Zugriff lief über `DB::table(...)`
 * aus inzwischen fünf Dateien, mit handgeschriebener uuid und Magic Strings für `type`
 * und `status`. Die konkrete Folge: **KI-Läufe standen in keinem Activity-Log** — jede
 * andere Schreibaktion des Moduls ist nachvollziehbar, „wer hat wann einen Lauf über
 * 200 Rezepte gestartet" (mit Provider-Kosten je Lauf) war es nicht.
 *
 * Bewusst **ohne** `BelongsToTeamHierarchy`, dieselbe Lesart wie
 * {@see FoodAlchemistRecipeFinding}: ein Lauf ist ein *Vorgang* des Teams, das ihn
 * gestartet hat, kein vererbbarer Katalog-Datensatz. `BulkEnrichService::runStatus` und
 * `IngestStatusService::laeufe` filtern darum strikt auf `team_id`.
 *
 * `SoftDeletes` trägt es nach dem Trait-Vertrag des Moduls (`PolicyTest`) — die Spalte
 * kommt mit derselben Migration. Gelöscht wird hier nichts: ein Lauf ist ein historisches
 * Ereignis, kein Stammdatum. Der Trait ist die Zusicherung, dass er auch künftig nicht
 * hart aus der Buchhaltung verschwindet.
 *
 * ⚠️ `foodalchemist_bulk_proposals` (der Zwilling aus V-032) hat bewusst noch KEIN Model:
 * seine acht Zugriffs-Stellen hängen alle an `DB::table` mit handgeschriebenem
 * `json_encode`/`json_decode` — ein `value`-Cast ist dort ein Verhaltenswechsel an acht
 * Stellen gleichzeitig und braucht einen eigenen Riegel. Ein Model ohne einen einzigen
 * Aufrufer wäre Vorrat statt Naht (Klasse V-025). Nachzuziehen in 22·H3c.
 */
class FoodAlchemistBulkRun extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_bulk_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'type' => BulkRunType::class,
        'status' => BulkRunStatus::class,
        'total' => 'integer',
        'done' => 'integer',
        'failed' => 'integer',
        'context' => 'array',
    ];

    /**
     * Der gemeinsame Einstieg für jede Lauf-Art (V-032: „damit die nächste Lauf-Art nur
     * den Enum-Fall ergänzt"). Vier Insert-Blöcke in vier Dateien haben bis hier jeweils
     * ihre eigene uuid erzeugt und ihre eigenen Strings gesetzt.
     *
     * @param  array<string, mixed>  $context  Gegenstand des Laufs (V-047) — Datei, Lieferant,
     *                                         Pass, Schritte; leer wird als NULL abgelegt,
     *                                         damit „kein Kontext" und „leerer Kontext"
     *                                         dieselbe Antwort geben.
     */
    public static function starte(
        ?int $teamId,
        BulkRunType $type,
        int $total,
        array $context = [],
        ?int $userId = null,
    ): self {
        return self::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'type' => $type,
            'status' => BulkRunStatus::Running,
            'total' => $total,
            'context' => $context === [] ? null : $context,
        ]);
    }
}
