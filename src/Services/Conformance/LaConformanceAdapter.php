<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Konformitäts-Adapter für Lieferantenartikel (LA) — prüft gegen das Regelwerk
 * Lieferantenartikel (§4 Necta-Quellfelder, §7 LA-Granularität, §10 Allergen-Aggregation,
 * §12 Status-Flags). Beurteilt die GESPIEGELTEN Necta-Felder, keine Bewertung des Preises.
 *
 * v1 OHNE Selbstheilung: der LA ist Necta-gespiegelt und der Vault-Sync ist EINBAHN (§9) —
 * ein autonomer LA-Revise würde beim nächsten Import überschrieben. Verstöße gehen darum
 * als Hinweis in die Ablage (unterstuetztHeilung=false).
 */
class LaConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'la';
    }

    public function unterstuetztHeilung(): bool
    {
        return false;
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier')->find($id);
        if ($la === null) {
            throw new \RuntimeException('Lieferantenartikel nicht gefunden oder nicht sichtbar.');
        }

        $kontext = [
            'artefakt_typ' => 'Lieferantenartikel (LA)',
            'bezeichnung' => $la->designation,
            'marketing_name' => $la->marketing_name,
            'regulierter_name' => $la->regulated_name,
            'artikel_nr' => $la->article_number,
            'marke' => $la->brand,                               // §4: KEIN Match-Key
            'hersteller' => $la->manufacturer,                   // §4: KEIN Match-Key
            'herkunft' => $la->origin,
            'lieferant' => $la->supplier?->name,
            'gebinde' => $la->packaging_unit,
            'bestelleinheit' => $la->ordering_unit,
            'gebinde_menge' => $la->qty !== null ? (float) $la->qty : null,
            'bio' => $la->is_organic,
            'vegan' => $la->is_vegan,
            'vegetarisch' => $la->is_vegetarian,
            'alkohol' => $la->is_alcohol,
        ];

        return [
            'kontext' => $kontext,
            'regelwerk_praefixe' => ['regelwerk-la-'],
            'target_table' => 'foodalchemist_supplier_items',
        ];
    }

    public function revise(Team $team, int $id, string $direktive): void
    {
        // v1: kein autonomer LA-Revise (Necta-Spiegel, Vault-Sync EINBAHN §9) → No-Op.
    }
}
