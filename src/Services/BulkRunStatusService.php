<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;

/**
 * Spec 22 · H3c — die **allgemeine Quittung** eines eingereihten Vorgangs (V-055).
 *
 * Asynchron war im Modul gelöst, „nachfragen, was daraus wurde" nicht: den Stand eines
 * Laufs konnte nur lesen, wer die passende fachliche Fläche kannte — der Autopilot seine
 * Livewire-Komponente, der Ingest seit 13·S3a `ingest.STATUS`. Über MCP gab es **keinen**
 * Weg: `ingest.IMPORT` liefert eine `run_id`, die nur `ingest.STATUS` deuten kann, und
 * das auch nur für Läufe des Typs `ingest`. Sobald ein LLM einen schreibenden Vorgang
 * auslöst, ist die Quittung Teil der Fähigkeit — ohne sie kann es weder „ist es durch?"
 * beantworten noch entscheiden, ob es erneut auslösen darf, und fällt entweder ins Blinde
 * oder ins Doppel-Auslösen.
 *
 * **Eine Projektion, zwei Türen.** {@see zeile()} ist die einzige Stelle, die eine
 * Lauf-Zeile in eine Antwort übersetzt; `IngestStatusService::laeufe()` baut seine
 * fachliche Sicht darauf auf und ergänzt nur, was ein Import zusätzlich hat (Datei,
 * Lieferant). Zwei Mapper für dieselbe Tabelle wären genau die Drift-Klasse, die dieser
 * Cluster abbaut — und der Beweis dafür steht im Test: die Ingest-Sicht wird gegen die
 * allgemeine gehalten, nicht neben ihr geprüft.
 *
 * **Team-strikt, nicht team-hierarchisch** — dieselbe Lesart wie `BulkEnrichService::runStatus`
 * und `IngestStatusService::laeufe`: ein Lauf ist ein *Vorgang* des Teams, das ihn gestartet
 * hat, kein vererbbarer Katalog-Datensatz.
 *
 * ⚠️ **Diese Fläche kennt nur `foodalchemist_bulk_runs`.** Die zweite Ablage aus V-055 —
 * der Cache-Eintrag des Rezept-Generators (`GenerateRecipeJob`, 15-Minuten-TTL, gelesen von
 * `HatGeneratorLauf`) — ist bewusst **nicht** hier eingezogen: das wäre ein Verhaltenswechsel
 * am Polling-Pfad einer lebenden Oberfläche und gehört in einen eigenen, beaufsichtigten
 * Schritt. Bis dahin gilt: was hier steht, ist vollständig für die fünf Lauf-Arten aus
 * {@see BulkRunType}, und der Generator-Lauf ist über diese Fläche nicht abfragbar.
 */
final class BulkRunStatusService
{
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 10;

    /**
     * Die letzten Läufe des Teams — optional auf eine Art, einen einzelnen Lauf oder auf
     * die noch offenen eingeschränkt.
     *
     * @return list<array<string, mixed>>
     */
    public function laeufe(
        Team $team,
        ?int $runId = null,
        ?BulkRunType $typ = null,
        int $limit = self::DEFAULT_LIMIT,
        bool $nurOffene = false,
    ): array {
        $q = FoodAlchemistBulkRun::query()->where('team_id', $team->id);

        if ($runId !== null) {
            $q->whereKey($runId);
        }
        if ($typ !== null) {
            $q->where('type', $typ->value);
        }
        if ($nurOffene) {
            $q->where('status', BulkRunStatus::Running->value);
        }

        return $q->orderByDesc('id')
            ->limit(max(1, min(self::MAX_LIMIT, $limit)))
            ->get()
            ->map(fn (FoodAlchemistBulkRun $r) => $this->zeile($r))
            ->all();
    }

    /**
     * Die allgemeine Sicht auf einen Lauf. Enthält bewusst **beides**: die Spalten-Auskunft
     * (`status`) und das Urteil, das ein Leser braucht (`zustand`, `offen`, `verwaist`).
     *
     * `offen` ist das Entscheidungs-Feld für einen aufrufenden Client: nur solange es `true`
     * ist, lohnt Warten. Ein verwaister Lauf steht in der Spalte weiter auf `running` (kein
     * Reaper ohne Beweis, 22·H3b), zählt hier aber **nicht** mehr als offen — sonst wartete
     * ein Client auf ein Ergebnis, das niemand mehr schreibt.
     *
     * @return array<string, mixed>
     */
    public function zeile(FoodAlchemistBulkRun $r): array
    {
        $kontext = is_array($r->context) ? $r->context : [];
        $verwaist = $r->istVerwaist();

        return [
            'run_id' => (int) $r->id,
            'typ' => $r->type->value,
            'typ_label' => $r->type->label(),
            // V-032: die Trennlinie für „wer hat wann Provider-Geld ausgegeben".
            'ki_lauf' => $r->type->istKiLauf(),
            'status' => $r->status->value,
            'zustand' => $r->zustandLabel(),
            'offen' => $r->status->istOffen() && ! $verwaist,
            'verwaist' => $verwaist,
            'umfang' => (int) $r->total,
            'verarbeitet' => (int) $r->done,
            'fehler' => (int) $r->failed,
            'fehler_grund' => $kontext['fehler'] ?? null,
            'fehler_klasse' => $kontext['fehler_klasse'] ?? null,
            // V-073: „sofort fertig, weil es nichts zu tun gab" — der einzige Weg, diesen
            // Fall von einem regulär durchgelaufenen Lauf zu unterscheiden.
            'hinweis' => $kontext['hinweis'] ?? null,
            'ausgeloest_von' => $r->user_id !== null ? (int) $r->user_id : null,
            'gestartet' => (string) $r->created_at,
            'beendet' => $r->status === BulkRunStatus::Running ? null : (string) $r->updated_at,
            // V-047: der Gegenstand, roh — welche Schlüssel darin liegen, hängt an der
            // Lauf-Art (Datei/Lieferant beim Import, Schrittfolge bei der Anreicherung,
            // Pass/Limit beim Review). Ausgang und Hinweis stehen oben als eigene Felder
            // und werden hier nicht doppelt ausgegeben.
            'gegenstand' => $this->gegenstand($kontext),
        ];
    }

    /**
     * Der Kontext ohne die Felder, die schon als eigene Antwort-Zeilen ausgegeben werden.
     * Leer ⇒ `null`, damit „kein Gegenstand" und „leerer Gegenstand" dieselbe Antwort geben
     * (dieselbe Regel wie beim Schreiben in {@see FoodAlchemistBulkRun::starte}).
     *
     * @param  array<string, mixed>  $kontext
     * @return array<string, mixed>|null
     */
    private function gegenstand(array $kontext): ?array
    {
        unset($kontext['fehler'], $kontext['fehler_klasse'], $kontext['hinweis']);

        return $kontext === [] ? null : $kontext;
    }
}
