<?php

namespace Platform\FoodAlchemist\Services\Preis;

/** Merkt sich während eines Preis-Pfads, ob ein letzter Preis eines eingestellten Artikels beigetragen hat (Rezept-Hinweis, Lead-Kennzeichen, Inventur). */
class LetzterPreisSpur
{
    private bool $genutzt = false;

    /** Artikel hat zum Preis beigetragen. */
    public function merke(object $la): void
    {
        $this->genutzt = $this->genutzt || $la->preis_quelle === LetzterPreisService::QUELLE_LETZTER;
    }

    public function zuruecksetzen(): void
    {
        $this->genutzt = false;
    }

    public function genutzt(): bool
    {
        return $this->genutzt;
    }

    /** Stand lesen und zurücksetzen (zwischen zwei Preis-Pfaden derselben Zutat). */
    public function abholen(): bool
    {
        $g = $this->genutzt;
        $this->genutzt = false;

        return $g;
    }
}
