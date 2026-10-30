<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 93 C · Regeln als Daten (Muster Spec 81): die Schwellen der Trend-Einordnung je Team. Startwerte kommen aus
 * `foodalchemist.trends.automatik` und sind Annahmen des Kurators, keine belegten Zahlen — die Oberfläche zeigt sie mit
 * „Startwert, nicht belegt“. Je Team überschreibbar (team_settings.trend_automatik_regeln), geprüft gegen Grenzen.
 */
final class TrendAutomatikRegeln
{
    /** Schlüssel → [Beschriftung, kleinster Wert, größter Wert, ganzzahlig] */
    public const SCHLUESSEL = [
        'kandidat_sichtungen' => ['Kandidat: Sichtungen', 1, 100, true],
        'kandidat_quellen' => ['Kandidat: verschiedene Quellen', 1, 50, true],
        'kandidat_tage' => ['Kandidat: Zeitraum in Tagen', 7, 365, true],
        'hype_sichtungen_30t' => ['Hype: Sichtungen in 30 Tagen', 1, 200, true],
        'trend_monate' => ['Trend: Monate mit Sichtung (12 Monate)', 1, 12, true],
        'trend_quellenarten' => ['Trend: Quellenarten', 1, 10, true],
        'tiefe_schwelle' => ['Anteil „Bedürfnis“ (0–1)', 0.0, 1.0, false],
        'konsum_sparten' => ['Konsum-Ebene: Sparten', 1, 6, true],
        'konsum_monate' => ['Konsum-Ebene: Monate', 1, 12, true],
        'abklingen_monate' => ['Abklingen: Monate ohne Sichtung', 1, 24, true],
        'max_neue_trends_je_lauf' => ['Neue Trends je Lauf höchstens', 0, 50, true],
        'quelle_fehler_signal_ab' => ['Signal ab Fehlern einer Quelle in Folge', 1, 50, true],
    ];

    public const STUFEN = [
        'aus' => 'Aus — nur Eingang und Erschließen, keine Trends',
        'bis_geprueft' => 'Bis „Geprüft“ — legt Trends an, nie aufs Radar',
        'radar' => 'Bis aufs Radar — wenn die Radar-Regel erfüllt ist',
    ];

    /** @return array<string, int|float> Startwerte aus der Config */
    public static function startwerte(): array
    {
        $cfg = (array) config('foodalchemist.trends.automatik', []);
        $out = [];
        foreach (self::SCHLUESSEL as $k => [, , , $ganz]) {
            $out[$k] = $ganz ? (int) ($cfg[$k] ?? 0) : (float) ($cfg[$k] ?? 0);
        }

        return $out;
    }

    /**
     * Wirksame Werte: Team-Werte über den Startwerten, unbekannte Schlüssel fallen weg.
     *
     * @param  array<string,mixed>|null  $team
     * @return array<string, int|float>
     */
    public static function wirksam(?array $team): array
    {
        return array_merge(self::startwerte(), self::pruefen($team ?? [], false));
    }

    /**
     * Prüft Team-Werte gegen die Grenzen. Mit $streng wirft ein ungültiger Wert, sonst fällt er weg.
     *
     * @param  array<string,mixed>  $werte
     * @return array<string, int|float>
     */
    public static function pruefen(array $werte, bool $streng = true): array
    {
        $out = [];
        foreach ($werte as $k => $v) {
            if (! isset(self::SCHLUESSEL[$k]) || $v === null || $v === '') {
                if ($streng && ! isset(self::SCHLUESSEL[$k])) {
                    throw new \RuntimeException('Unbekannte Schwelle „'.$k.'“. Erlaubt: '.implode(', ', array_keys(self::SCHLUESSEL)).'.');
                }

                continue;
            }
            [$label, $min, $max, $ganz] = self::SCHLUESSEL[$k];
            $zahl = is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : null;
            if ($zahl === null || $zahl < $min || $zahl > $max) {
                if ($streng) {
                    throw new \RuntimeException($label.': erlaubt ist '.$min.' bis '.$max.'.');
                }

                continue;
            }
            $out[$k] = $ganz ? (int) round($zahl) : round($zahl, 3);
        }

        return $out;
    }
}
