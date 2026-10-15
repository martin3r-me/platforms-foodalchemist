<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Services\Necta\NectaApiQuelle;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;

/**
 * Necta-Attrappe in der echten Raw-API-Antwortform ({items,totalCount,pageNumber,pageSize,totalPages}, Felder aus der Stichprobe
 * vom 15.10.2026). Filtert wie Necta nach supplierId / supplierItemId; changeDateFrom wird nur protokolliert.
 */
class NectaAttrappe implements NectaQuelle
{
    /** @var array<string,int> */
    public array $aufrufe = [];

    /** @var list<array{0:string, 1:array<string,mixed>}> */
    public array $filter = [];

    public ?\Throwable $fehler = null;

    /** @param array<string, list<array<string,mixed>>> $daten  @param array<int,int> $artikelLieferant Necta-Artikel => Necta-Lieferant */
    public function __construct(public array $daten, public array $artikelLieferant) {}

    public function seite(string $ressource, int $seite, int $groesse, array $filter = []): array
    {
        if ($this->fehler !== null) {
            throw $this->fehler;
        }
        $this->aufrufe[$ressource] = ($this->aufrufe[$ressource] ?? 0) + 1;
        $this->filter[] = [$ressource, $filter];
        $zeilen = array_values(array_filter($this->daten[$ressource] ?? [], function (array $r) use ($ressource, $filter) {
            if (isset($filter['supplierItemId']) && (int) ($r['supplierItemId'] ?? $r['id']) !== (int) $filter['supplierItemId']) {
                return false;
            }
            if (isset($filter['supplierId'])) {
                $lieferant = $ressource === 'supplier-items' ? (int) $r['supplierId'] : ($this->artikelLieferant[(int) ($r['supplierItemId'] ?? 0)] ?? 0);

                return $lieferant === (int) $filter['supplierId'];
            }

            return true;
        }));
        $seiten = (int) max(1, ceil(count($zeilen) / $groesse));

        return NectaApiQuelle::normalisiere(['items' => array_slice($zeilen, ($seite - 1) * $groesse, $groesse),
            'totalCount' => count($zeilen), 'pageNumber' => $seite, 'pageSize' => $groesse, 'totalPages' => $seiten], $seite, $groesse);
    }

    /** Necta-Artikel (Raw-API) mit Standardwerten; $mehr überschreibt. */
    public static function artikel(int $id, int $lieferant, string $name, array $mehr = []): array
    {
        return $mehr + ['id' => $id, 'supplierId' => $lieferant, 'articleNumber' => ' 99', 'designation' => $name, 'text' => null,
            'packagingUnit' => 'ST', 'orderingUnit' => 'ST', 'quantityOrderingUnitsPerPackagingUnit' => 1, 'quantity' => 1, 'unitId' => 1005,
            'eanPackagingUnit' => null, 'eanOrderingUnit' => '4001234567890', 'originCountryId' => 2, 'brand' => null, 'isOrganic' => true,
            'isVegan' => false, 'isVegetarian' => true, 'isAlcohol' => false, 'isHalal' => false, 'isGmoFree' => false, 'isPreorder' => false,
            'preorderDays' => null];
    }

    /** Necta-Preiszeile (Raw-API). */
    public static function preis(int $id, int $artikel, float $preis, array $mehr = []): array
    {
        return $mehr + ['id' => $id, 'supplierItemId' => $artikel, 'tenantId' => 1494, 'status' => '0', 'price' => $preis,
            'pricePartial' => $preis, 'validTo' => null, 'statusValidFrom' => '2026-10-15T00:00:00', 'isBlocked' => false, 'note' => null,
            'changeDate' => '2026-10-15T12:21:15', 'creationDate' => '2026-10-15T12:21:15'];
    }
}
