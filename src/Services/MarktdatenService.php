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
                // Anzeige-Tabelle: nur Monate mit Wert, neueste zuerst. Zukunft filtert schon der CSV-Leser.
                'tabelle' => array_reverse(array_values(array_map(fn ($p) => ['monat' => $p['monat'], 'wert' => $p['wert'], 'einheit' => $p['einheit']],
                    array_filter($punkte, fn ($p) => $p['wert'] !== null)))),
                'abgerufen_am' => $d?->abgerufen_at?->toIso8601String(), 'versucht_am' => $d?->versucht_at?->toIso8601String(),
                'fehler' => $d?->letzter_fehler, 'quelle' => self::QUELLE, 'team_id' => $d?->team_id,
                'veraltet' => $aktuell !== null && $aktuell['monat'] < now()->subMonthsNoOverflow(3)->format('Y-m')];
        }

        return ['zugang_eingerichtet' => $this->client->eingerichtet(), 'reihen' => $out, 'aussage' => self::aussage($out),
            'hinweis' => 'Verbraucherpreisindizes für Deutschland, keine Einkaufs- oder Betriebskosten. Vorjahresvergleich nur bei gleicher Indexbasis; Original-Kennzeichnungen beachten.'];
    }

    /** Mindestabstand in Prozentpunkten, ab dem die Aussage eine Richtung nennt; darunter „etwa gleich“. */
    public const AUSSAGE_SCHWELLE_PP = 0.5;

    /**
     * Klartext-Aussage NUR aus den abgerufenen Werten. Keine Aussage, wenn eine Reihe keinen Vorjahresvergleich hat,
     * die Bezugsmonate auseinanderfallen oder die Indexbasis der beiden Reihen verschieden ist.
     * Verbraucherpreise ≠ Wareneinsatz: die Formulierung bleibt bei „Lebensmittelpreise im Handel“.
     */
    public static function aussage(array $reihen): ?array
    {
        $r = collect($reihen)->keyBy('serie');
        $gast = $r->get('gaststaettenpreise');
        $lm = $r->get('lebensmittelpreise');
        if (! $gast || ! $lm || $gast['vorjahr_prozent'] === null || $lm['vorjahr_prozent'] === null
            || $gast['aktuell']['monat'] !== $lm['aktuell']['monat'] || $gast['aktuell']['einheit'] !== $lm['aktuell']['einheit']) {
            return null;
        }
        $g = (float) $gast['vorjahr_prozent'];
        $l = (float) $lm['vorjahr_prozent'];
        $fmt = fn (float $v) => ($v > 0 ? '+' : '').number_format($v, 1, ',', '.').' %';
        $abstand = round(abs($g - $l), 1);
        $pp = number_format($abstand, 1, ',', '.').' Prozentpunkte';
        if ($abstand < self::AUSSAGE_SCHWELLE_PP) {
            $folge = 'Beide entwickeln sich etwa gleich (Abstand '.$pp.').';
        } else {
            [$hoch, $tief] = $g > $l ? ['die Restaurantpreise', 'die Lebensmittelpreise im Handel'] : ['die Lebensmittelpreise im Handel', 'die Restaurantpreise'];
            $verb = max($g, $l) > 0 ? 'steigen stärker als' : 'sinken weniger stark als';
            $folge = ucfirst($hoch).' '.$verb.' '.$tief.' (Abstand '.$pp.').';
        }

        return ['text' => 'Restaurantpreise '.$fmt($g).', Lebensmittel '.$fmt($l).' zum Vorjahresmonat: '.$folge,
            'monat' => $gast['aktuell']['monat'], 'basis' => $gast['aktuell']['einheit']];
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
