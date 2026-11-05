<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdaten as Daten;
use Platform\FoodAlchemist\Services\Trends\GenesisClient;
use Platform\FoodAlchemist\Services\Trends\GenesisCsvLeser;

class MarktdatenService
{
    public const SERIEN = [
        'lebensmittelpreise' => ['code' => 'CC13-01', 'name' => 'Nahrungsmittel & alkoholfreie Getränke'],
        'gaststaettenpreise' => ['code' => 'CC13-111', 'name' => 'Gaststättendienstleistungen'],
    ];

    public const QUELLE = 'https://genesis.destatis.de/datenbank/online/statistic/61111/table/61111-0004';

    public function __construct(private readonly FaRechte $rechte, private readonly GenesisClient $client, private readonly GenesisCsvLeser $leser) {}

    public function lesen(Team $team): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Marktdaten lesen');
        $daten = Daten::visibleToTeam($team)->orderByDesc('abgerufen_at')->get();
        $out = [];
        foreach (self::SERIEN as $serie => $meta) {
            // Eigener Snapshot hat Vorrang; geerbte Daten bleiben lesbar, niemals überschreibbar.
            $d = $daten->first(fn ($d) => $d->serie === $serie && (int) $d->team_id === (int) $team->id)
                ?? $daten->firstWhere('serie', $serie);
            $punkte = $d?->punkte ?? [];
            $bekannte = array_values(array_filter($punkte, fn ($p) => $p['wert'] !== null));
            $aktuell = $bekannte !== [] ? $bekannte[array_key_last($bekannte)] : ($punkte === [] ? null : $punkte[array_key_last($punkte)]);
            $offeneMonate = $aktuell === null ? 0 : count(array_filter($punkte, fn ($p) => $p['monat'] > $aktuell['monat'] && $p['wert'] === null));
            $vorjahr = $aktuell ? collect($punkte)->firstWhere('monat', ((int) substr($aktuell['monat'], 0, 4) - 1).substr($aktuell['monat'], 4)) : null;
            $vergleich = $aktuell !== null && $vorjahr !== null && $aktuell['wert'] !== null && $vorjahr['wert'] > 0
                && $aktuell['einheit'] === $vorjahr['einheit']
                ? round(($aktuell['wert'] / $vorjahr['wert'] - 1) * 100, 1) : null;
            $out[] = $meta + ['serie' => $serie, 'aktuell' => $aktuell, 'neuere_monate_ohne_wert' => $offeneMonate, 'vorjahr_prozent' => $vergleich, 'punkte' => $punkte,
                'abgerufen_am' => $d?->abgerufen_at?->toIso8601String(), 'versucht_am' => $d?->versucht_at?->toIso8601String(),
                'fehler' => $d?->letzter_fehler, 'quelle' => self::QUELLE, 'team_id' => $d?->team_id,
                'veraltet' => $aktuell !== null && $aktuell['monat'] < now()->subMonthsNoOverflow(3)->format('Y-m')];
        }

        return ['zugang_eingerichtet' => $this->client->eingerichtet(), 'reihen' => $out,
            'hinweis' => 'Verbraucherpreisindizes für Deutschland, keine Einkaufs- oder Betriebskosten. Vorjahresvergleich nur bei gleicher Indexbasis; Original-Kennzeichnungen beachten.'];
    }

    /** Nur feste Pilotserien, aktuelles Team; kein Hintergrundabruf aus einem Render-/GET-Pfad. */
    public function abrufen(Team $team, bool $apply = false): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Marktdaten abrufen');
        $out = [];
        foreach (self::SERIEN as $serie => $meta) {
            try {
                $punkte = $this->leser->lesen($this->client->laden($meta['code']), $meta['code']);
                if ($apply) {
                    Daten::updateOrCreate(['team_id' => $team->id, 'serie' => $serie], [
                        'punkte' => $punkte, 'abgerufen_at' => now(), 'versucht_at' => now(), 'letzter_fehler' => null,
                    ]);
                }
                $bekannte = array_values(array_filter($punkte, fn ($p) => $p['wert'] !== null));
                $out[] = ['serie' => $serie, 'monate' => count($punkte), 'monate_mit_wert' => count($bekannte),
                    'letzter_monat' => $punkte[array_key_last($punkte)]['monat'],
                    'letzter_wert_monat' => $bekannte === [] ? null : $bekannte[array_key_last($bekannte)]['monat'], 'fehler' => null];
            } catch (\RuntimeException $e) {
                if ($apply) {
                    Daten::updateOrCreate(['team_id' => $team->id, 'serie' => $serie], [
                        'versucht_at' => now(), 'letzter_fehler' => mb_substr($e->getMessage(), 0, 500),
                    ]);
                }
                $out[] = ['serie' => $serie, 'monate' => 0, 'fehler' => $e->getMessage()];
            }
        }

        return ['gespeichert' => $apply, 'probelauf' => ! $apply, 'ergebnisse' => $out];
    }
}
