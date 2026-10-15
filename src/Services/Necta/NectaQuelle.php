<?php

namespace Platform\FoodAlchemist\Services\Necta;

/**
 * Necta-Abgleich: EINZIGER Lesezugang zu necta.one. Produktiv {@see NectaApiQuelle} über Martins Integrations-Modul
 * (Platform\Integrations\Services\NectaApiService, Raw-API, nur lesend); in Tests eine Attrappe mit derselben Antwortform.
 */
interface NectaQuelle
{
    /**
     * Eine Seite einer Raw-API-Ressource.
     *
     * @param  array<string, scalar>  $filter
     * @return array{items: list<array<string,mixed>>, totalCount: int, pageNumber: int, pageSize: int, totalPages: int}
     */
    public function seite(string $ressource, int $seite, int $groesse, array $filter = []): array;
}
