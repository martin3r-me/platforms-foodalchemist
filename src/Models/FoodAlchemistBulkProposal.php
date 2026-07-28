<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * V-032 · Spec 22 H3c-2 — EIN Vorschlag eines Anreicherungs-Laufs am **Rezept**.
 *
 * Mit H3a bekam der Lauf sein Model; sein Vorschlags-Speicher blieb bewusst zurück, weil
 * ein `value`-Cast ein Verhaltenswechsel an acht Zugriffs-Stellen gleichzeitig ist und
 * einen eigenen Riegel braucht. Der liegt jetzt vor
 * ({@see \Platform\FoodAlchemist\Tests\Feature ·`BulkProposalSpeicherGoldenTest`}, friert
 * die Ablage-Form **auf der Spalte** ein, nicht nur den Round-Trip) — damit hört die
 * Tabelle auf, die letzte Fach-Tabelle des Moduls ohne Eloquent zu sein.
 *
 * Was der Umbau konkret einlöst: die uuid wird nicht mehr an jeder Insert-Stelle von Hand
 * erzeugt, `status` ist ein Vokabular statt vier Magic Strings ({@see BulkProposalStatus}),
 * und die Vorschlags-Zeilen eines KI-Laufs stehen im Activity-Log — bei Provider-Kosten je
 * Lauf ist „wer hat wann welchen Vorschlag übernommen" genau die Frage, die rückwirkend
 * gestellt wird.
 *
 * **Der `array`-Cast ist ausdrücklich formgleich zum Handbetrieb**, kein neues Verhalten:
 * Laravel kodiert beim Setzen mit `json_encode` (und lässt `null` als SQL-NULL stehen —
 * die Fehler-Zeile bleibt also NULL, nicht der String `'null'`) und dekodiert beim Lesen
 * mit `json_decode(..., true)`. `value` trägt je Feld einen anderen Typ (String bei Texten,
 * int bei `category`, Array bei `speisen_klasse`) — der Name „array" ist Laravels
 * Cast-Name für „JSON", keine Zusicherung über den Wert-Typ.
 *
 * Bewusst **ohne** `BelongsToTeamHierarchy`, dieselbe Lesart wie {@see FoodAlchemistBulkRun}:
 * ein Vorschlag gehört zum Vorgang des Teams, das ihn ausgelöst hat, und nicht in den
 * vererbbaren Katalog — sonst fände ein Kind-Team die offenen Vorschläge des Eltern-Teams
 * in seiner eigenen Review-Liste und könnte über sie entscheiden. `BulkEnrichService`
 * filtert darum strikt auf `team_id`. ⚠️ Die Review-Queue tut das **nicht** (sie liest über
 * die Team-Kette) — dieselbe Tabelle, zwei Sicht-Weiten; als Befund hochgegeben, hier nicht
 * geändert, weil das eine Sichtbarkeits-Entscheidung wäre.
 *
 * Der GP-Zwilling {@see FoodAlchemistBulkGpProposal} ist Zeile für Zeile derselbe Speicher
 * mit anderem Fremdschlüssel. Die **Mechanik** darüber (Ablage, Fehlerzeile, Leer-Bewertung)
 * einmal statt zweimal zu schreiben ist V-072 und ausdrücklich nicht Teil dieses Umbaus:
 * die beiden Hälften bewerten „leerer Vorschlag" heute verschieden, und das anzugleichen
 * ist eine Auswahl-Regel-Änderung, kein Model-Umbau.
 *
 * Relationen sind bewusst **keine** deklariert: jeder Leser holt Rezept bzw. Lauf heute
 * über einen team-gescopten Query (`visibleToTeam`), den eine `belongsTo` umginge — und
 * eine Relation ohne Aufrufer ist genau der Fund, an dem H3b die tote
 * `FoodAlchemistBulkRun::proposals()` entfernt hat (V-025).
 */
class FoodAlchemistBulkProposal extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_bulk_proposals';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'value' => 'array',
        'confidence' => 'float',
        'status' => BulkProposalStatus::class,
        'run_id' => 'integer',
        'recipe_id' => 'integer',
        'call_log_id' => 'integer',
    ];
}
