<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PriceService;

/** GP-Paket-Import · Preissicht des Master-Teams (globale Stamm-Artikel haben ohne Team KEINE sichtbaren Preise, PriceService::stammFilter). */
class GpPaketPreisSicht
{
    private array $preisCache = [];

    private ?Team $preisTeam = null;

    public function __construct(private readonly PriceService $preise) {}

    /** Setzt die Sicht (Master-Team) und leert den Cache — am Anfang jedes Laufs. */
    public function setze(?Team $team): void
    {
        $this->preisTeam = $team;
        $this->preisCache = [];
    }

    public function hatPreis(int $itemId): bool
    {
        if (! array_key_exists($itemId, $this->preisCache)) {
            $p = $this->preise->activeFor($itemId, $this->preisTeam);
            $this->preisCache[$itemId] = $p !== null && (float) $p->price > 0;
        }

        return $this->preisCache[$itemId];
    }
}
