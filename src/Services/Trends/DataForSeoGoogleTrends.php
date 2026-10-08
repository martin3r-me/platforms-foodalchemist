<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 79 · Google Trends über die DataForSEO-Anbindung des Integrations-Moduls
 * (`DataForSeoApiService::getGoogleTrendsExplore`, Live-Endpunkt). Nur lesend genutzt —
 * am Integrations-Modul selbst wird nichts geändert.
 *
 * Verbindung: die in den Einstellungen gewählte, sonst die eines Teammitglieds oder eine fürs Team
 * geteilte. Abgefragt wird im Namen der Verbindungs-Eigentümerin, damit auch der wöchentliche Lauf
 * ohne angemeldeten Benutzer funktioniert.
 */
class DataForSeoGoogleTrends implements GoogleTrendsQuelle
{
    public function __construct(private readonly TeamSettingsService $settings) {}

    public function verfuegbar(): bool
    {
        return class_exists(\Platform\Integrations\Services\DataForSeoApiService::class);
    }

    public function interesse(Team $team, string $begriff): array
    {
        if (! $this->verfuegbar()) {
            throw new \RuntimeException('Die DataForSEO-Anbindung (Integrations-Modul) ist auf dieser Plattform nicht installiert.');
        }
        [$verbindungId, $eigentuemer] = $this->verbindung($team);
        try {
            $ergebnisse = app(\Platform\Integrations\Services\DataForSeoApiService::class)
                ->forConnection($verbindungId)
                ->getGoogleTrendsExplore($eigentuemer, [$begriff]);
        } catch (\Throwable $e) {
            report($e);
            throw new \RuntimeException('Google Trends über DataForSEO fehlgeschlagen: '.$e->getMessage());
        }
        $werte = $ergebnisse[0]->interestOverTime ?? [];

        return is_array($werte) ? array_values($werte) : [];
    }

    /** @return array{0:int, 1:User} */
    private function verbindung(Team $team): array
    {
        $id = $this->settings->trendDataForSeoConnectionId($team);
        $verbindung = $id !== null
            ? \Platform\Integrations\Models\IntegrationConnection::find($id)
            : app(\Platform\Integrations\Services\IntegrationConnectionResolver::class)->resolveForTeam('dataforseo', $team);
        if ($verbindung === null) {
            throw new \RuntimeException('Keine DataForSEO-Verbindung für dieses Team. Unter Integrationen anlegen oder fürs Team freigeben.');
        }
        $eigentuemer = User::find($verbindung->owner_user_id);
        if ($eigentuemer === null) {
            throw new \RuntimeException('Die DataForSEO-Verbindung hat keine Eigentümerin mehr.');
        }

        return [(int) $verbindung->id, $eigentuemer];
    }
}
