<?php

namespace Platform\FoodAlchemist\Services\Naehrwerte;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\NaehrwertAggregatService;

/**
 * Trockenlauf der Nährwert-Prüfung über den Bestand, den ein Team sieht: wie viele Artikel würden markiert, je Regel und Lieferant,
 * wie viele würden umgerechnet (je Faktor und Lieferant), mit Beispielen. Nur lesend, unabhängig vom Modus — Grundlage für das Go
 * zum Scharfschalten (MCP `naehrwert_pruefung.GET`, Befehl `foodalchemist:naehrwerte-pruefen`).
 */
class NaehrwertPruefLaufService
{
    /**
     * @param  list<int>|null  $gpIds
     * @return array{bericht: array<string, mixed>, zeilen: list<array<string, mixed>>}  zeilen = je Befund eine Zeile (Meldeliste/CSV)
     */
    public function lauf(Team $team, int $limit = 5, ?array $gpIds = null): array
    {
        $ids = FoodAlchemistGp::visibleToTeam($team)->when($gpIds !== null, fn ($q) => $q->whereIn('id', $gpIds))->pluck('name', 'id')->all();
        $pruefung = app(NaehrwertAggregatService::class)->pruefung(array_keys($ids));

        $artikel = [];                       // la_id => true (ein Artikel kann theoretisch an mehreren GPs hängen)
        $markiert = $hinweis = $umgerechnet = $freigegeben = [];
        $gpsBetroffen = [];
        $jeRegel = [];
        $jeLieferant = [];
        $umrechnung = [];
        $zeilen = [];
        foreach ($pruefung as $gpId => $las) {
            foreach ($las as $a) {
                $artikel[$a['la_id']] = true;
                $lieferant = $a['lieferant'] ?? '–';
                if ($a['freigegeben']) {
                    $freigegeben[$a['la_id']] = true;
                }
                if (! $a['zaehlt']) {
                    $markiert[$a['la_id']] = true;
                    $gpsBetroffen[$gpId] = true;
                    $jeLieferant[$lieferant] = ($jeLieferant[$lieferant] ?? 0) + 1;
                }
                if ($a['faktor'] !== null) {
                    $umgerechnet[$a['la_id']] = true;
                    $umrechnung[$lieferant][(string) $a['faktor']] = ($umrechnung[$lieferant][(string) $a['faktor']] ?? 0) + 1;
                }
                foreach ($a['befunde'] as $b) {
                    if ($b->schwere === NaehrwertBefund::HINWEIS) {
                        $hinweis[$a['la_id']] = true;
                    }
                    $r = &$jeRegel[$b->regel];
                    $r ??= ['schwere' => $b->schwere, 'befunde' => 0, 'artikel' => [], 'beispiele' => []];
                    $r['befunde']++;
                    $r['artikel'][$a['la_id']] = true;
                    if (count($r['beispiele']) < $limit) {
                        $r['beispiele'][] = ['gp_id' => $gpId, 'gp' => $ids[$gpId] ?? null, 'la_id' => $a['la_id'], 'lieferant' => $a['lieferant'],
                            'bezeichnung' => $a['bezeichnung'], 'feld' => $b->feld, 'wert' => $b->wert, 'erwartet' => $b->erwartet, 'grund' => $b->grund,
                            'vorschlag' => $b->vorschlag];
                    }
                    unset($r);
                    $zeilen[] = ['regel' => $b->regel, 'schwere' => $b->schwere, 'gp_id' => $gpId, 'gp' => $ids[$gpId] ?? null, 'la_id' => $a['la_id'],
                        'lieferant' => $a['lieferant'], 'bezeichnung' => $a['bezeichnung'], 'feld' => $b->feld, 'wert' => $b->wert, 'erwartet' => $b->erwartet,
                        'grund' => $b->grund, 'faktor' => $b->vorschlag['faktor'] ?? null, 'freigegeben' => $a['freigegeben']];
                }
            }
        }
        ksort($jeRegel);
        arsort($jeLieferant);
        ksort($umrechnung);

        return ['bericht' => [
            'modus' => (string) config('foodalchemist.naehrwert_pruefung.modus'),
            'artikel_geprueft' => count($artikel),
            'artikel_markiert' => count($markiert),
            'artikel_umgerechnet' => count($umgerechnet),
            'artikel_nur_hinweis' => count(array_diff_key($hinweis, $markiert)),
            'artikel_freigegeben' => count($freigegeben),
            'gps_betroffen' => count($gpsBetroffen),
            'je_regel' => array_map(fn ($r) => ['schwere' => $r['schwere'], 'befunde' => $r['befunde'], 'artikel' => count($r['artikel']), 'beispiele' => $r['beispiele']], $jeRegel),
            'markiert_je_lieferant' => $jeLieferant,
            'umrechnung_je_lieferant' => $umrechnung,
            'hinweis' => 'Nur lesend. „markiert“ = zählt im Modus scharf nicht in den GP-Nährwerten (harter Befund, nicht freigegeben). '
                . '„umgerechnet“ = eindeutige Einheiten-Probe, gerechnet wird mit Makros × Faktor; der Rohwert bleibt gespeichert.',
        ], 'zeilen' => $zeilen];
    }
}
