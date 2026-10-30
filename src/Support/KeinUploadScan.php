<?php

namespace Platform\FoodAlchemist\Support;

/** Standard-Scanner: kein Virenscan (der Rest der Dateiprüfung läuft trotzdem, siehe LieferantenUploadService). */
class KeinUploadScan implements UploadScanner
{
    public function pruefe(string $pfad, string $endung): ?string
    {
        return null;
    }
}
