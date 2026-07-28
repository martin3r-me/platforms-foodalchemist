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
     * Ab wann gilt ein `running`-Lauf als verwaist (22·H3b · V-054)?
     *
     * ⚠️ **Annahme, keine Messung.** Auf der Dev-DB gibt es 0 offene Läufe (D-016) — die
     * Schwelle ist dort nicht messbar und darum aus dem **längsten Job-Timeout des Moduls**
     * abgeleitet: `BulkEnrichJob`/`BulkEnrichGpJob` 3600 s (die übrigen 900 / 600 / 300 s).
     * Ein Lauf, der doppelt so lange nichts mehr gemeldet hat, kann nicht mehr am Leben
     * sein — der Worker hätte ihn längst abgeschossen. Doppelt statt einfach, weil
     * `updated_at` erst je fertigem Item fortschreibt und ein einzelnes Item das Timeout
     * ausschöpfen darf: die Schwelle darf einen langsamen Lauf nicht für tot erklären.
     *
     * Bewusst **nur ein Lese-Kriterium**: niemand schreibt daraufhin `failed`. Ein Reaper
     * ohne Beweis würde einen noch lebenden Lauf für abgebrochen erklären, und das wäre
     * dieselbe Lüge wie das ewige „läuft gerade", nur in die andere Richtung. Wer liest,
     * erfährt „vermutlich abgebrochen"; wer den Lauf wirklich beendet, ist der Job selbst
     * ({@see markiereGescheitert}).
     */
    public const VERWAIST_NACH_STUNDEN = 2;

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

    /**
     * Das Ende ohne Erfolg (22·H3b · V-054). Bis hier war die Statusmenge faktisch
     * `running | done` und der einzige Schreiber von `done` der Erfolgspfad — ein
     * abgestürzter Lauf blieb für immer „läuft gerade" und beantwortete damit die Frage
     * „ist der Quartals-Import durch?" dauerhaft falsch.
     *
     * Ein **beendeter** Lauf wird nicht rückdatiert: läuft `handle()` durch und stirbt
     * erst der Nachlauf, ist das Teilergebnis echt und `done` die wahre Antwort. Darum
     * greift der Fehl-Pfad nur auf `running` und meldet per Rückgabewert, ob er gegriffen
     * hat (idempotent — `failed()` darf mehrfach kommen).
     *
     * Der Grund landet im Kontext-Feld aus H3a (`fehler` + `fehler_klasse`), nicht in einer
     * neuen Spalte: der Lauf trägt seinen Gegenstand schon, sein Ausgang gehört daneben.
     */
    public static function markiereGescheitert(int $runId, \Throwable|string|null $grund = null): bool
    {
        $lauf = self::whereKey($runId)->first();
        if ($lauf === null || $lauf->status !== BulkRunStatus::Running) {
            return false;
        }

        $kontext = is_array($lauf->context) ? $lauf->context : [];
        if ($grund instanceof \Throwable) {
            $kontext['fehler'] = mb_strimwidth($grund->getMessage(), 0, 500, '…');
            $kontext['fehler_klasse'] = $grund::class;
        } elseif (is_string($grund) && trim($grund) !== '') {
            $kontext['fehler'] = mb_strimwidth($grund, 0, 500, '…');
        }

        $lauf->status = BulkRunStatus::Failed;
        $lauf->context = $kontext === [] ? null : $kontext;
        $lauf->save();

        return true;
    }

    /**
     * Ein `running`-Lauf, von dem seit {@see VERWAIST_NACH_STUNDEN} nichts mehr kam.
     * Kein Zustand in der Spalte, sondern ein Urteil beim Lesen — die Begründung steht
     * an der Konstante.
     */
    public function istVerwaist(): bool
    {
        return $this->status === BulkRunStatus::Running
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subHours(self::VERWAIST_NACH_STUNDEN));
    }

    /**
     * Das Etikett, das ein Leser sehen soll — inklusive des Urteils über verwaiste Läufe.
     * `status->label()` bleibt die reine Spalten-Auskunft; wer den Lauf **anzeigt**, will
     * die hier.
     */
    public function zustandLabel(): string
    {
        return $this->istVerwaist() ? 'abgebrochen (keine Rückmeldung)' : $this->status->label();
    }
}
