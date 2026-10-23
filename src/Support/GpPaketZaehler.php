<?php

namespace Platform\FoodAlchemist\Support;

/**
 * GP-Paket-Import — Kennzahlen je Warengruppe und gesamt (Plan §4.6 / §0.9).
 */
final class GpPaketZaehler
{
    /** Anzeige-Reihenfolge und Beschriftung der Kennzahlen. */
    public const BESCHRIFTUNG = [
        'gp_paket' => 'GPs im Paket (im Lauf)',
        'gp_neu' => 'GPs neu',
        'gp_neu_approved' => '… davon approved',
        'gp_neu_tentative' => '… davon tentative',
        'gp_unveraendert' => 'GPs unverändert',
        'gp_geaendert' => 'GPs geändert',
        'gp_konflikt' => 'GPs mit Konflikt (von Hand geändert, nichts überschrieben)',
        'gp_abgelehnt' => 'GPs abgelehnt',
        'derivate' => 'Derivate (angenommen)',
        'platzhalter' => 'Platzhalter (angenommen)',
        'gp_ohne_artikel' => 'GPs ohne Artikel',
        'gp_ohne_artikel_requires_la' => '… davon requires_la=1',
        'tentative_nun_mit_artikel' => 'bestehende tentative GPs mit inzwischen verknüpftem Artikel (nur gemeldet)',
        'kind_neu' => 'Kind-Zeilen neu (Formen/Texturen/Einheiten/Äquivalente)',
        'kind_unveraendert' => 'Kind-Zeilen unverändert',
        'kind_abweichend' => 'Kind-Zeilen abweichend (nicht überschrieben)',
        'kind_abgelehnt' => 'Kind-Zeilen abgelehnt',
        'v_neu' => 'Verknüpfungen neu',
        'v_bestehend' => 'Verknüpfungen bestehend',
        'v_umgehaengt' => 'Verknüpfungen umgehängt',
        'v_umhaengen_noetig' => 'Artikel an anderem GP (Umhängen nötig)',
        'v_uebersprungen' => '… ohne --umhaengen übersprungen',
        'v_ohne_treffer' => 'Verknüpfungen ohne Artikel-Treffer',
        'v_mehrdeutig' => 'Verknüpfungen mehrdeutig',
        'v_guard_abgelehnt' => 'Verknüpfungen vom Allergen-Guard abgelehnt',
        'struktur_neu' => 'Struktur-Zeilen neu',
        'lead_unveraendert' => 'Lead bleibt (löst auf)',
        'lead_uebernommen' => 'Lead aus Paket übernommen',
        'lead_neu' => 'Lead neu gewählt (im Dry-Run: Schätzung = Obergrenze)',
        'lead_park' => 'Lead Park (kein bepreister Artikel)',
        'n_las_erwartet' => 'Σ n_las_total (erwartet)',
        'anker_gps' => 'GPs mit Anker-Zeilen',
        'anker_neu' => 'Anker neu/geändert',
        'anker_unveraendert' => 'Anker unverändert',
        'anker_abgelehnt' => 'Anker abgelehnt',
    ];

    /** @var array<string, array<string,int>> */
    public array $wg = [];

    public function inc(?string $wg, string $key, int $n = 1): void
    {
        $wg = $wg === null || $wg === '' ? '--' : $wg;
        $this->wg[$wg][$key] = ($this->wg[$wg][$key] ?? 0) + $n;
    }

    public function setze(?string $wg, string $key, int $wert): void
    {
        $wg = $wg === null || $wg === '' ? '--' : $wg;
        $this->wg[$wg][$key] = $wert;
    }

    public function summe(string $key): int
    {
        return array_sum(array_map(fn ($z) => (int) ($z[$key] ?? 0), $this->wg));
    }

    /** @return array<string,int> */
    public function gesamt(): array
    {
        $out = [];
        foreach (array_keys(self::BESCHRIFTUNG) as $k) {
            $out[$k] = $this->summe($k);
        }

        return $out;
    }

    /** @return array<string, array<string,int>> nach Warengruppe sortiert, alle Kennzahlen gefüllt */
    public function je(): array
    {
        $out = [];
        $codes = array_keys($this->wg);
        sort($codes, SORT_STRING);
        foreach ($codes as $c) {
            foreach (array_keys(self::BESCHRIFTUNG) as $k) {
                $out[$c][$k] = (int) ($this->wg[$c][$k] ?? 0);
            }
        }

        return $out;
    }
}
