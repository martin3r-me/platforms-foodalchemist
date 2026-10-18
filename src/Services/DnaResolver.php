<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;

/**
 * Spec 82 · Schritt 6 (§7) — Team-DNA FELDWEISE über die Team-Kette.
 *
 * Vorher: „nächster Canvas der Kette gewinnt“ (früher CanvasService::findFoodDna, gelöscht Spec 85) — ein Unter-Team mit
 * einem einzigen ausgefüllten Feld verlor damit die gesamte DNA des Oberteams. Jetzt gilt je FELD:
 *
 *  - Skalar: ein VERBINDLICHER Wert des obersten Teams gewinnt (und sperrt alle Teams darunter für dieses
 *    Feld), sonst der nächstliegende nicht-leere Wert (Unter-Team vor Oberteam).
 *  - Repeatable (Geschmackswelten …): Vereinigung über die Ebenen, Oberteam zuerst, gleiche Einträge
 *    einmal. Ein verbindlicher Eintrag bleibt immer (er fällt nie heraus, auch nicht beim Kürzen).
 *    OFFEN (Rückfrage Kurator/Dominique): ob ein Unter-Team an einem Repeatable-Feld mit verbindlichen
 *    Einträgen weiter ergänzen darf. Hier: ja (reine Vereinigung); bei Skalaren sperrt Verbindlich dagegen.
 *
 * Die Kette endet an der Kundengrenze ({@see FaRechte::kundenHauptTeam}): ein Mandant erbt nie die DNA des
 * Master-Teams. Zyklen und Tiefe sind gedeckelt. Reine Lese-Logik, schreibt nichts.
 *
 * Rückgabe mit Herkunft (`quelle_team_id`, `verbindlich`), damit die Oberfläche „geerbt von …“ zeigen und
 * der KontextResolver Verbindliches vor dem Kürzen schützen kann.
 */
class DnaResolver
{
    private const MAX_TIEFE = 32;

    public function __construct(private CanvasService $canvas, private FaRechte $rechte)
    {
    }

    /**
     * Team-IDs der Kette von OBEN (Oberteam, an der Kundengrenze) nach UNTEN (eigenes Team).
     *
     * @return list<int>
     */
    public function teamIds(Team $team): array
    {
        $grenze = (int) $this->rechte->kundenHauptTeam($team)->id;
        $ids = [];
        $current = $team;
        for ($i = 0; $current !== null && $i < self::MAX_TIEFE; $i++) {
            $id = (int) $current->id;
            if (in_array($id, $ids, true)) {
                break;   // Zyklus
            }
            $ids[] = $id;
            if ($id === $grenze) {
                break;
            }
            $current = $current->parent_team_id ? $current->parentTeam : null;
        }

        return array_reverse($ids);
    }

    /**
     * Aufgelöste Team-DNA je Feld, in der Reihenfolge der Feld-Definitionen (Kern + Zusatzfelder).
     *
     * @return array<string, array{
     *   feld: array<string, mixed>,
     *   wert: string|null|list<array{id: int, value: string, meta: array<string, mixed>, quelle_team_id: int, verbindlich: bool}>,
     *   quelle_team_id: int|null,
     *   verbindlich: bool
     * }>
     */
    public function foodDna(Team $team): array
    {
        $kette = $this->teamIds($team);
        $canvases = [];   // team_id => Einträge je field_key
        foreach ($kette as $teamId) {
            $canvas = $this->canvas->find('food_dna', 'team', $teamId);
            $canvases[$teamId] = $canvas === null ? collect() : $canvas->entries->groupBy('field_key');
        }

        $out = [];
        foreach ($this->canvas->felder('food_dna', $team) as $feld) {
            $key = $feld['key'];
            $out[$key] = ($feld['type'] ?? 'text') === 'repeatable'
                ? $this->repeatable($feld, $kette, $canvases)
                : $this->skalar($feld, $kette, $canvases);
        }

        return $out;
    }

    /**
     * Die DNA als KI-Kontext-Block im Format von {@see CanvasService::promptKontext} (null = leer).
     * Ohne verbindliche Felder und mit nur EINEM Team in der Kette identisch zu dessen Canvas.
     */
    public function promptKontext(Team $team): ?string
    {
        $felder = [];
        $werte = [];
        foreach ($this->foodDna($team) as $key => $zeile) {
            $felder[] = $zeile['feld'];
            $werte[$key] = $zeile['wert'];
        }

        return $this->canvas->promptKontextAusWerten('food_dna', $felder, $werte);
    }

    /** Index (in der Kette, 0 = oben) des obersten Teams mit einem verbindlichen, gefüllten Eintrag — oder null. */
    private function sperrIndex(string $key, array $kette, array $canvases): ?int
    {
        foreach ($kette as $i => $teamId) {
            foreach ($canvases[$teamId]->get($key, collect()) as $e) {
                if ($e->is_binding && $e->value !== null && trim((string) $e->value) !== '') {
                    return $i;
                }
            }
        }

        return null;
    }

    private function skalar(array $feld, array $kette, array $canvases): array
    {
        $key = $feld['key'];
        $sperre = $this->sperrIndex($key, $kette, $canvases);
        if ($sperre !== null) {
            $eintrag = $canvases[$kette[$sperre]]->get($key, collect())->first(
                fn ($e) => $e->is_binding && $e->value !== null && trim((string) $e->value) !== '' && (int) $e->position === 0
            ) ?? $canvases[$kette[$sperre]]->get($key)->first();

            return ['feld' => $feld, 'wert' => (string) $eintrag->value, 'quelle_team_id' => $kette[$sperre], 'verbindlich' => true];
        }

        // nächstliegender nicht-leerer Wert: von unten (eigenes Team) nach oben
        for ($i = count($kette) - 1; $i >= 0; $i--) {
            $e = $canvases[$kette[$i]]->get($key, collect())->first(fn ($e) => (int) $e->position === 0);
            if ($e !== null && $e->value !== null && trim((string) $e->value) !== '') {
                return ['feld' => $feld, 'wert' => (string) $e->value, 'quelle_team_id' => $kette[$i], 'verbindlich' => false];
            }
        }

        return ['feld' => $feld, 'wert' => null, 'quelle_team_id' => null, 'verbindlich' => false];
    }

    private function repeatable(array $feld, array $kette, array $canvases): array
    {
        $key = $feld['key'];
        $sperre = $this->sperrIndex($key, $kette, $canvases);
        $bis = count($kette) - 1;

        $items = [];
        $gesehen = [];
        $verbindlichGesamt = false;
        for ($i = 0; $i <= $bis; $i++) {
            $teamId = $kette[$i];
            foreach ($canvases[$teamId]->get($key, collect())->sortBy('position') as $e) {
                $norm = mb_strtolower(trim((string) $e->value));
                if ($norm === '') {
                    continue;
                }
                $verbindlich = (bool) $e->is_binding;
                $verbindlichGesamt = $verbindlichGesamt || $verbindlich;
                if (isset($gesehen[$norm])) {
                    // gleicher Eintrag auf mehreren Ebenen: einmal, aber „verbindlich“ geht nicht verloren
                    if ($verbindlich) {
                        $items[$gesehen[$norm]]['verbindlich'] = true;
                    }

                    continue;
                }
                $gesehen[$norm] = count($items);
                $items[] = [
                    'id' => (int) $e->id, 'value' => (string) $e->value, 'meta' => $e->meta ?? [],
                    'quelle_team_id' => $teamId, 'verbindlich' => $verbindlich,
                ];
            }
        }

        return [
            'feld' => $feld,
            'wert' => $items,
            'quelle_team_id' => $sperre !== null ? $kette[$sperre] : null,
            'verbindlich' => $verbindlichGesamt,
        ];
    }
}
