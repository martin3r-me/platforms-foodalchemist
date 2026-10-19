<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 85 Controlling PR-A (Dominique 19.10.): der globale Lead (`lead_la_supplier_item_id`) gilt für alle Teams — ihn setzt nur
 * der Besitzer des Grundprodukts (`isOwnedBy`: eigenes Team, bei globalen Stamm-Zeilen der Master). Andere Teams legen ihren
 * eigenen Leitartikel als Pin fest (folgt mit PR-B). Erbt von RuntimeException, damit bestehende Fehlerwege die Meldung zeigen;
 * MCP-Tools fangen sie gezielt als NUR_BESITZER.
 */
class LeadNurBesitzerException extends \RuntimeException
{
    public function __construct(public readonly int $gpId, string $gpName)
    {
        parent::__construct("Die Bezugsquelle für alle Teams von „{$gpName}“ setzt nur das Team, dem das Grundprodukt gehört."
            . ' Euren eigenen Leitartikel legt ihr als bevorzugten Artikel fest.');
    }
}
