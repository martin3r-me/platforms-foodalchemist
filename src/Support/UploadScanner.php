<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Einhängepunkt für einen Virenscanner der Lieferanten-Uploads (Portal, Stufe 2b). Der Standard ({@see KeinUploadScan}) prüft nichts;
 * ein Betreiber bindet über `foodalchemist.lieferanten_link.scanner` eine eigene Klasse ein (z. B. ClamAV). Die Datei liegt zu diesem Zeitpunkt
 * nur als Temp-Datei vor und wird nie ausgeführt oder geöffnet, nur gelesen.
 */
interface UploadScanner
{
    /** @return string|null null = sauber, sonst der Ablehnungsgrund (kurz, ohne technische Details für den Lieferanten) */
    public function pruefe(string $pfad, string $endung): ?string;
}
