<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\GpService;

/**
 * Konformitäts-Adapter für Grundprodukte (GP) — prüft gegen das Regelwerk Grundprodukte
 * (§3 Warengruppen, §6 Benennungsschema/Singular, §8 Pflichtangaben, §9 Zustand, §11 Derivate).
 *
 * v1 OHNE Selbstheilung: GP hat keinen Freitext-Revise wie ein Rezept — §6-Naming/§8-
 * Pflichtangaben zu korrigieren ist eine gezielte Feld-Änderung, kein Umschreiben. Bis
 * ein GP-Feld-Revise steht, meldet der Critic Verstöße nur als Hinweis (unterstuetztHeilung=false).
 */
class GpConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'gp';
    }

    public function unterstuetztHeilung(): bool
    {
        return false;
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $gp = app(GpService::class)->find($id, $team);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht gefunden oder nicht sichtbar.');
        }

        $kontext = [
            'artefakt_typ' => 'Grundprodukt (GP)',
            'name' => $gp->name,
            'warengruppe' => $gp->commodity_group_code,          // §3
            'sub_kategorie' => $gp->sub_category,
            'zustand' => $gp->condition,                          // §9 frisch|TK|trocken|konserviert
            'bio' => $gp->bio,
            'verarbeitung' => $gp->processing,
            'form' => $gp->form,
            'ist_derivat' => (bool) $gp->is_derivat,              // §11
            'ist_platzhalter' => (bool) $gp->is_platzhalter,     // Platzhalter: §3/§8 bewusst ausgenommen
        ];

        return [
            'kontext' => $kontext,
            'regelwerk_praefixe' => ['regelwerk-gp-'],
            'target_table' => 'foodalchemist_gps',
        ];
    }

    public function revise(Team $team, int $id, string $direktive): void
    {
        // v1: kein autonomer GP-Feld-Revise → No-Op (unterstuetztHeilung=false, die
        // Selbstheil-Runde wird ohnehin übersprungen). Verstöße bleiben Hinweis.
    }
}
