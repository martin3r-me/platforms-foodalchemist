<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Homogenitäts-Riegel beim Verknüpfen (LeadLaService::guardDeklarationsprofil): Allergene oder Zusatzstoffe des neuen Lieferantenartikels
 * weichen von einem schon verknüpften ab. Typisiert, damit Aufrufer (z. B. der GP-Paket-Import) den Fall am Typ erkennen statt am
 * Wortlaut der Meldung (Spec 84 D1). Erbt von RuntimeException: bestehende catch-Stellen greifen unverändert.
 */
class DeklarationsprofilAbweichtException extends \RuntimeException
{
    /** @param list<string> $abweichungen */
    public function __construct(public readonly array $abweichungen)
    {
        parent::__construct(
            'Allergene oder Zusatzstoffe weichen von einem vorhandenen Lieferantenartikel ab ('
            .implode(', ', array_slice($abweichungen, 0, 3))
            .'). Für diesen Artikel ein neues Grundprodukt anlegen.'
        );
    }
}
