<?php

namespace Platform\FoodAlchemist\Services\Concerns;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;

/**
 * Externer KI-Weg der Kaskade (Lauf 62, io 13.11.2026): Ein per MCP gestarteter Lauf wartet nicht auf den Worker, sondern auf
 * offene KI-Aufträge (ki_auftraege.ANTWORT). Die Job-Transaktion rollt beim Warten zurück, auch die Phase — die offenen Aufträge
 * selbst sind die Wahrheit. Ein wartender Schritt ist auch nicht verwaist (Auftrag gilt 24 h). Dazu der Lauf-Status-Hinweis. Aus {@see \Platform\FoodAlchemist\Services\PlanningCascadeService} gezogen (Größen-Wächter Spec 85 §4).
 */
trait KaskadeKiWarten
{
    /** Phasen-Text eines Schritts, der auf externe KI-Antworten wartet (Status per MCP und Cockpit, gleicher Wortlaut). */
    public static function kiWartetText(int $anzahl): string
    {
        return 'wartet auf KI-Antwort (' . ($anzahl === 1 ? '1 Auftrag offen' : $anzahl . ' Aufträge offen') . ')';
    }

    /**
     * Lauf-Schlüssel, unter denen die Jobs eines Schritts im externen Weg ihre KI-Aufträge anlegen
     * ({@see \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar::externSchluessel()} der Kaskaden-Jobs). Ein Schlüssel ohne
     * Auftrag trifft schlicht nichts — die Liste darf großzügig sein.
     *
     * @return list<string>
     */
    private function kiLaufKeys(FoodAlchemistCascadeRunStep $s): array
    {
        $team = (int) $s->team_id;
        $id = (int) $s->id;
        $keys = [];
        foreach (['recipe-plan', 'dish-proposal', 'dish-revise', 'build-zukauf', 'fanout-concept', 'materialize-cell', 'materialize-position'] as $praefix) {
            $keys[] = "{$praefix}:{$team}:{$id}";
        }
        // Gericht aus einer Idee (MaterializeConceptIdeaJob, Schlüssel je Idee statt je Schritt) — fehlte: Lauf 65 (io 13.11.2026)
        // zeigte „wartet auf Worker“, obwohl ein vk.generator-Auftrag offen war.
        $idee = (int) ((is_array($s->context_snapshot) ? $s->context_snapshot : [])['dish_idea_id'] ?? 0);
        if ($idee > 0) {
            $keys[] = "materialize-idea:{$team}:{$idee}";
        }
        $gen = trim((string) ($s->generator_run_id ?? ''));
        if ($gen !== '') {
            $keys[] = "generate-recipe:{$gen}";
            $keys[] = "enrich-generated:{$gen}";
            $keys[] = "generate-concept:{$team}:{$gen}";
        }
        if ($s->ref_type === 'recipe' && $s->ref_id !== null) {
            $keys[] = "enrich-recipe:{$team}:" . (int) $s->ref_id;
        }

        return $keys;
    }

    /**
     * Welche Schritte warten gerade auf eine externe KI-Antwort (offener, nicht abgelaufener KI-Auftrag eines ihrer Jobs)?
     * Rein lesend, fail-soft (die Auskunft ist Anzeige, kein Arbeitsschritt).
     *
     * @param  iterable<FoodAlchemistCascadeRunStep>  $steps
     * @return array<int, array{anzahl: int, features: list<string>, auftrag_ids: list<string>}> Step-ID → offene Aufträge
     */
    public function kiWartend(iterable $steps): array
    {
        $jeTeam = [];
        foreach ($steps as $s) {
            foreach ($this->kiLaufKeys($s) as $key) {
                $jeTeam[(int) $s->team_id][$key] = (int) $s->id;
            }
        }
        $aus = [];
        try {
            foreach ($jeTeam as $teamId => $keys) {
                foreach (app(KiAuftragService::class)->offeneZuLaeufen($teamId, array_keys($keys)) as $key => $info) {
                    $stepId = $keys[$key];
                    if (! isset($aus[$stepId])) {
                        $aus[$stepId] = $info;

                        continue;
                    }
                    $aus[$stepId]['auftrag_ids'] = array_values(array_unique([...$aus[$stepId]['auftrag_ids'], ...$info['auftrag_ids']]));
                    $aus[$stepId]['features'] = array_values(array_unique([...$aus[$stepId]['features'], ...$info['features']]));
                    $aus[$stepId]['anzahl'] = count($aus[$stepId]['auftrag_ids']);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $aus;
    }

    /**
     * Die offenen KI-Aufträge eines Laufs (für ki_auftraege.GET mit run_id). null = Lauf für das Team nicht sichtbar.
     *
     * @return list<string>|null Auftrags-IDs (uuid)
     */
    public function kiAuftragIdsFuerLauf(Team $team, int $runId): ?array
    {
        $run = $this->lauf($team, $runId);

        return $run === null ? null : self::kiIds($this->kiWartend($run->steps));
    }

    /**
     * @param  array<int, array{auftrag_ids: list<string>}>  $kiWartend
     * @return list<string>
     */
    private static function kiIds(array $kiWartend): array
    {
        return $kiWartend === [] ? [] : array_values(array_unique(array_merge(...array_column($kiWartend, 'auftrag_ids'))));
    }

    /** Block `ki_auftraege_offen` für laufStatus — null, wenn der Lauf auf keine Antwort wartet. */
    private static function kiAuftraegeOffenBlock(array $kiWartend, int $runId): ?array
    {
        if ($kiWartend === []) {
            return null;
        }
        $kiIds = self::kiIds($kiWartend);

        return [
            'anzahl' => count($kiIds),
            'features' => array_values(array_unique(array_merge(...array_column($kiWartend, 'features')))),
            'auftrag_ids' => $kiIds,
            'naechster_schritt' => 'Am einfachsten planung_kaskade.WEITER(run_id=' . $runId . '): liefert die Fragen und nimmt die Antworten an. '
                . 'Einzeln: foodalchemist.ki_auftraege.GET(run_id=' . $runId . ') holen, per ki_auftraege.ANTWORT beantworten, dann '
                . 'planung_kaskade.GET erneut lesen. Je Stufe können neue Aufträge folgen — wiederholen, bis ki_auftraege_offen fehlt.',
        ];
    }

    /**
     * Hinweis-Satz im externen Weg: solange Aufträge offen sind, rechnet KEIN Worker (geht jedem anderen Satz vor). Ohne offene
     * Aufträge, aber per MCP gestartet und `running`: Zusatz, wo man nachsieht. Sonst null.
     */
    private static function kiHinweis(int $kiOffen, int $runId, string $status, string $createdVia): ?string
    {
        if ($kiOffen > 0) {
            return 'Der Lauf wartet auf ' . ($kiOffen === 1 ? '1 KI-Antwort' : $kiOffen . ' KI-Antworten') . ' (externer Weg), kein Worker rechnet. '
                . 'foodalchemist.ki_auftraege.GET(run_id=' . $runId . ') holen, mit ki_auftraege.ANTWORT beantworten, danach planung_kaskade.GET erneut lesen — '
                . 'der Lauf startet sich selbst neu und legt je Stufe weitere Aufträge an.';
        }

        return $status === 'running' && $createdVia === 'mcp' && $runId > 0
            ? ' Per MCP gestartet: die Jobs rufen keine Plattform-KI, sondern legen KI-Aufträge an, sobald der Worker sie aufnimmt — '
                . 'steht hier nach kurzer Zeit weiter nichts, mit foodalchemist.ki_auftraege.GET(run_id=' . $runId . ') nachsehen.'
            : null;
    }

    /** Übersetzt den Run-Status in einen Handlungs-Satz (Freigabe/Fortsetzen bleiben human-only). */
    private function laufStatusHinweis(string $status, mixed $steps = null, int $kiOffen = 0, int $runId = 0, string $createdVia = ''): string
    {
        if ($kiOffen > 0) {   // externer KI-Weg: kein Worker rechnet, geht jedem anderen Satz vor
            return (string) self::kiHinweis($kiOffen, $runId, $status, $createdVia);
        }

        $uebernahme = $steps !== null
            ? $this->zaehleUebernommenUnreif($steps)
            : ['gesamt' => 0, 'unreif' => 0, 'unreif_labels' => []];

        // Ein Lauf, der nur noch wegen einer UNREIFEN ÜBERNAHME in `review` haengt, braucht einen
        // anderen Satz als „Entwuerfe warten auf Freigabe" — sonst sucht man die Freigabe-Aktion,
        // die es dort nicht gibt (eine `skipped`-Zeile ist nicht freigebbar).
        if ($status === 'review' && $steps !== null) {
            $offeneEntwuerfe = collect($steps)->whereIn('status', ['done', 'geplant'])->count();
            if ($uebernahme['unreif'] > 0 && $offeneEntwuerfe === 0) {
                return 'Übernommene Bestands-Rezepte sind nicht produktionsreif ('
                    .implode(', ', $uebernahme['unreif_labels'])
                    .'). Eigene Entwürfe werden bei der Freigabe automatisch angereichert; fremde oder '
                    .'freigegebene Rezepte brauchen eine bewusste Entscheidung im Cockpit '
                    .'(„Bestand anreichern"). Details je Schritt: uebernahme_reif / uebernahme_luecken.';
            }
        }

        // Task 6: „Lauf abgeschlossen" hieß bisher auch dann so, wenn ein übernommener Bestand
        // NICHT produktionsreif ist (Lauf 65) — der Satz nennt die Zahl jetzt explizit, statt
        // Vollständigkeit zu behaupten, die reifegrad() nicht bestätigt.
        $uebernahmeZusatz = $uebernahme['gesamt'] > 0
            ? sprintf(
                ' %d Rezept%s aus dem Bestand übernommen, davon %d unfertig (nicht produktionsreif).',
                $uebernahme['gesamt'], $uebernahme['gesamt'] === 1 ? '' : 'e', $uebernahme['unreif'],
            )
            : '';

        return match ($status) {
            'running' => 'Der Worker rechnet noch (Steps queued/running). Auf Abschluss warten, dann prüfen/freigeben.' . self::kiHinweis(0, $runId, $status, $createdVia),
            'review' => 'Fertige Entwürfe warten auf die menschliche Freigabe (Gate 2); geplante Sub-Rezepte auf die Freigabe der Stufe darüber. Freigeben/Verwerfen ist human-only, kein MCP-Trigger.',
            'done' => 'Lauf abgeschlossen — alle Artefakte freigegeben oder als Bestand übernommen.' . $uebernahmeZusatz,
            'failed' => 'Lauf fehlgeschlagen — gescheiterte Schritte lassen sich gebündelt fortsetzen (human-only im Cockpit).',
            default => $status,
        };
    }
}
