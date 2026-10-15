<?php

namespace Platform\FoodAlchemist\Services\Necta;

/**
 * Produktiver Zugang zu necta.one über das Integrations-Modul (Martin). Kein eigener HTTP-Client: Authentifizierung, Retry und
 * Verbindungsstatus liegen in `NectaApiService::list()`. Das FA-Modul setzt das Integrations-Modul nicht als Composer-Abhängigkeit
 * voraus (der Host stellt es bereit) — fehlt es, bricht der Lauf mit einer klaren Meldung ab.
 */
class NectaApiQuelle implements NectaQuelle
{
    private const SERVICE = 'Platform\\Integrations\\Services\\NectaApiService';

    private const VERBINDUNG = 'Platform\\Integrations\\Models\\IntegrationConnection';

    private ?object $verbindung = null;

    public function __construct(private int $verbindungId) {}

    public function seite(string $ressource, int $seite, int $groesse, array $filter = []): array
    {
        if (! class_exists(self::SERVICE) || ! class_exists(self::VERBINDUNG)) {
            throw new \RuntimeException('Necta-Abgleich: Das Integrations-Modul (NectaApiService) ist auf diesem Server nicht installiert.');
        }
        $this->verbindung ??= (self::VERBINDUNG)::find($this->verbindungId)
            ?? throw new \RuntimeException("Necta-Abgleich: Verbindung {$this->verbindungId} nicht gefunden.");

        $antwort = app(self::SERVICE)->list($this->verbindung, $ressource, $seite, $groesse, $filter);

        return self::normalisiere($antwort, $seite, $groesse);
    }

    /**
     * Raw-API antwortet {items,totalCount,pageNumber,pageSize,totalPages} (klein); ältere Exporte großgeschrieben. Beides lesen.
     *
     * @return array{items: list<array<string,mixed>>, totalCount: int, pageNumber: int, pageSize: int, totalPages: int}
     */
    public static function normalisiere(mixed $antwort, int $seite, int $groesse): array
    {
        $a = is_array($antwort) ? $antwort : [];
        $items = $a['items'] ?? $a['Items'] ?? (array_is_list($a) ? $a : []);

        return [
            'items' => array_values(array_filter(is_array($items) ? $items : [], 'is_array')),
            'totalCount' => (int) ($a['totalCount'] ?? $a['TotalCount'] ?? 0),
            'pageNumber' => (int) ($a['pageNumber'] ?? $a['PageNumber'] ?? $seite),
            'pageSize' => (int) ($a['pageSize'] ?? $a['PageSize'] ?? $groesse),
            'totalPages' => (int) ($a['totalPages'] ?? $a['TotalPages'] ?? 0),
        ];
    }
}
