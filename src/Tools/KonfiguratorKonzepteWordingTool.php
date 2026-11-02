<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 Stufe 5b · foodalchemist.konfigurator_konzepte.WORDING (wie format_slots.WORDING, Eigentümer Konfigurator-Konzept) */
class KonfiguratorKonzepteWordingTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'WORDING';
    }

    protected function beschreibung(): string
    {
        return 'Setzt/leert das konfigurator-lokale Wording eines Gerichts (konzept_id = Konfigurator-Konzept, concept_slot_id = Position im Lunch- oder Dinner-Concept). '
            . 'text weglassen/leer = zurück auf die Wording-Kette (Concept-Slot → Kundenname des Gerichts). Concept und andere Ausgaben bleiben unverändert.';
    }

    protected function felder(): array
    {
        return [['konzept_id' => ['type' => 'integer'], 'concept_slot_id' => ['type' => 'integer'], 'text' => ['type' => 'string']], ['konzept_id', 'concept_slot_id']];
    }

    protected function beispiele(): array
    {
        return ['Überschreibe im Konfigurator-Konzept 9 das Wording der Position 120 mit „Rinderfilet vom Grill“.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->wordingSetzen($team, self::id($a, 'konzept_id'), self::id($a, 'concept_slot_id'),
            isset($a['text']) ? (string) $a['text'] : null);

        return ['konzept_id' => $k->id, 'wording_overrides' => $k->payload_json['wording_overrides'] ?? []];
    }
}
