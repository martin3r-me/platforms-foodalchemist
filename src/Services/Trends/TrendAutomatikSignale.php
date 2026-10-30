<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\TrendThemenService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 C · Signale des Trendradars, eingehängt in {@see \Platform\FoodAlchemist\Services\SignalDetektorService::laufen()}.
 * Je Team und Ereignis ein Signal (dedup_key), jedes schließt sich selbst, sobald der Zustand weg ist:
 *
 * - Ereignisse der Automatik (neuer Hype/Trend, Hype wird Trend, aufs Radar) an eigenen Trends — bis ein Mensch den Trend
 *   anfasst (Feld in manuell_felder);
 * - Thema abgeklungen (Vorschlag archivieren, nie automatisch löschen);
 * - Quelle mit Fehlern in Folge (ab `quelle_fehler_signal_ab`), MCP-Quelle fällig, aber nicht besucht;
 * - Pinnwand-Karte passt zu einem Trend der Automatik (Regel 3: Vorschlag, die Karte wird nie umgehängt);
 * - Kandidaten über der Lauf-Grenze (nur wenn die Automatik scharf ist).
 */
class TrendAutomatikSignale
{
    private const EREIGNISSE = [
        'neu_hype' => [SignalTyp::TrendradarNeuerHype, 'Neuer Hype erkannt'],
        'neu_trend' => [SignalTyp::TrendradarNeuerTrend, 'Neuer Trend erkannt'],
        'hype_wird_trend' => [SignalTyp::TrendradarHypeWirdTrend, 'Hype wird Trend'],
        'auf_radar' => [SignalTyp::TrendradarAufRadar, 'Automatisch aufs Radar gesetzt'],
    ];

    public function __construct(
        private readonly SignalService $signals,
        private readonly TrendAutomatikService $automatik,
        private readonly TrendQuellenService $quellen,
    ) {}

    public function laufen(Team $team): int
    {
        return $this->ereignisse($team) + $this->quellenSignale($team) + $this->themenSignale($team);
    }

    private function ereignisse(Team $team): int
    {
        $keys = array_fill_keys(array_keys(self::EREIGNISSE), []);
        foreach (FoodAlchemistTrend::where('team_id', $team->id)->where('herkunft', 'automatisch')->orderBy('id')->get() as $t) {
            if (($t->manuell_felder ?? []) !== []) {
                continue;   // ein Mensch hat den Trend angefasst → erledigt
            }
            foreach (array_unique(array_column($t->automatik_ereignisse ?? [], 'art')) as $art) {
                if (! isset(self::EREIGNISSE[$art])) {
                    continue;
                }
                [$typ, $titel] = self::EREIGNISSE[$art];
                $key = 'trendradar-'.$art.'-'.$t->id;
                $keys[$art][] = $key;
                $this->signals->erzeuge($team, $typ, SignalSeverity::Info, $titel.': '.$t->name, [
                    'dedup_key' => $key,
                    'description' => 'Die Trend-Automatik hat „'.$t->name.'“ eingeordnet ('.V::label(V::TYPEN, $t->typ).', '.V::label(V::EBENEN, $t->ebene)
                        .', Status '.V::label(V::STATUS, $t->status).'). Bitte prüfen und bei Bedarf korrigieren — eine Korrektur von Hand gilt dauerhaft. '
                        .trim((string) $t->einordnung_begruendung),
                    'payload' => ['trend_id' => (int) $t->id, 'ereignis' => $art],
                ]);
            }
        }
        $n = 0;
        foreach (self::EREIGNISSE as $art => [$typ]) {
            $this->signals->schliesseVerschwundene($team, $typ, 'detektor', $keys[$art], 'Trend wurde von Hand geprüft — automatisch geschlossen');
            $n += count($keys[$art]);
        }

        return $n;
    }

    private function quellenSignale(Team $team): int
    {
        $ab = (int) config('foodalchemist.trends.automatik.quelle_fehler_signal_ab', 3);
        $fehlerKeys = [];
        $faelligKeys = [];
        foreach (FoodAlchemistTrendQuelle::where('team_id', $team->id)->where('aktiv', true)->orderBy('id')->get() as $q) {
            if ($q->abruf_weg === 'plattform' && (int) $q->fehler_in_folge >= $ab) {
                $key = 'trendradar-quelle-fehler-'.$q->id;
                $fehlerKeys[] = $key;
                $this->signals->erzeuge($team, SignalTyp::TrendradarQuelleFehler, SignalSeverity::Warnung,
                    'Quelle „'.$q->name.'“ liefert seit '.$q->fehler_in_folge.' Abrufen Fehler', [
                        'dedup_key' => $key,
                        'description' => 'Letzter Fehler: '.$q->letzter_fehler.' — im Reiter „Quellen“ neu prüfen oder auf MCP umstellen.',
                        'payload' => ['quelle_id' => (int) $q->id, 'fehler_in_folge' => (int) $q->fehler_in_folge],
                    ]);
            }
            if ($q->abruf_weg === 'mcp' && $this->quellen->istFaellig($q)) {
                $key = 'trendradar-mcp-faellig-'.$q->id;
                $faelligKeys[] = $key;
                $this->signals->erzeuge($team, SignalTyp::TrendradarMcpQuelleUeberfaellig, SignalSeverity::Info,
                    'MCP-Quelle „'.$q->name.'“ ist fällig', [
                        'dedup_key' => $key,
                        'description' => 'Rhythmus '.V::label(V::RHYTHMEN, $q->rhythmus).', zuletzt besucht '.($q->letzter_abruf_at?->format('d.m.Y') ?? 'noch nie')
                            .'. Per MCP sichten (foodalchemist.trend_quellen.GET abruf_weg=mcp) und mit trend_eingang.POST einreichen.',
                        'payload' => ['quelle_id' => (int) $q->id],
                    ]);
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::TrendradarQuelleFehler, 'detektor', $fehlerKeys, 'Quelle liefert wieder — automatisch geschlossen');
        $this->signals->schliesseVerschwundene($team, SignalTyp::TrendradarMcpQuelleUeberfaellig, 'detektor', $faelligKeys, 'Quelle wurde gesichtet — automatisch geschlossen');

        return count($fehlerKeys) + count($faelligKeys);
    }

    private function themenSignale(Team $team): int
    {
        $e = $this->automatik->einstellungen($team);
        $abgeklungen = [];
        $pinnwand = [];
        foreach ($this->automatik->themen($team) as $thema) {
            $k = $this->automatik->kennzahlen($team, $thema, $e['regeln']);
            if ($k['sichtungen'] > 0 && $this->automatik->einordnen($k, $e['regeln'])['abgeklungen']) {
                $key = 'trendradar-abgeklungen-'.$thema->id;
                $abgeklungen[] = $key;
                $this->signals->erzeuge($team, SignalTyp::TrendradarThemaAbgeklungen, SignalSeverity::Info, 'Thema „'.$thema->titel.'“ ist abgeklungen', [
                    'dedup_key' => $key,
                    'description' => 'Keine Sichtung seit '.$e['regeln']['abklingen_monate'].' Monaten (letzte '.$k['letzte_sichtung'].'). Vorschlag: den Trend archivieren — gelöscht wird nichts automatisch.',
                    'payload' => ['thema_id' => (int) $thema->id, 'trend_id' => $k['trend_id']],
                ]);
            }
            // Regel 3: eine Pinnwand-Karte passt zu einem Trend der Automatik → Vorschlag, nie selbst umhängen
            if ($thema->trend_id !== null) {
                $schluessel = (string) ($thema->thema_schluessel ?: TrendThemenService::schluessel((string) $thema->titel));
                foreach ($this->automatik->passendePinnwand($team, $schluessel) as $karte) {
                    if ($karte->trend_id !== null) {
                        continue;
                    }
                    $key = 'trendradar-pinnwand-'.$karte->id.'-'.$thema->trend_id;
                    $pinnwand[] = $key;
                    $this->signals->erzeuge($team, SignalTyp::TrendradarPinnwandPasst, SignalSeverity::Info,
                        'Pinnwand-Karte „'.$karte->titel.'“ passt zu „'.$thema->titel.'“', [
                            'dedup_key' => $key,
                            'description' => 'Die Karte zählt schon als Sichtung. Zuordnen bleibt Handarbeit: in der Pinnwand „An Trend hängen“.',
                            'payload' => ['inspiration_id' => (int) $karte->id, 'trend_id' => (int) $thema->trend_id],
                        ]);
                }
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::TrendradarThemaAbgeklungen, 'detektor', $abgeklungen, 'Thema wieder gesichtet — automatisch geschlossen');
        $this->signals->schliesseVerschwundene($team, SignalTyp::TrendradarPinnwandPasst, 'detektor', $pinnwand, 'Karte zugeordnet oder passt nicht mehr — automatisch geschlossen');

        // Lauf-Grenze: aus dem letzten scharfen Lauf (der nächste Lauf nimmt den Rest und schließt das Signal)
        $gestaut = [];
        $letzter = $e['letzter_lauf'] ?? null;
        if ($e['stufe'] !== 'aus' && ! $e['schatten'] && (int) ($letzter['gestaut'] ?? 0) > 0) {
            $plan = ['gestaut' => (int) $letzter['gestaut']];
            if ($plan['gestaut'] > 0) {
                $gestaut[] = 'trendradar-gestaut';
                $this->signals->erzeuge($team, SignalTyp::TrendradarKandidatenGestaut, SignalSeverity::Info,
                    $plan['gestaut'].' Trend-Kandidaten warten (Grenze je Lauf)', [
                        'dedup_key' => 'trendradar-gestaut',
                        'description' => 'Die Automatik legt höchstens '.$e['regeln']['max_neue_trends_je_lauf'].' neue Trends je Lauf an; der Rest folgt im nächsten Lauf. Die Grenze steht in den Trendradar-Einstellungen.',
                        'payload' => ['gestaut' => $plan['gestaut']],
                    ]);
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::TrendradarKandidatenGestaut, 'detektor', $gestaut, 'Keine wartenden Kandidaten mehr — automatisch geschlossen');

        return count($abgeklungen) + count($pinnwand) + count($gestaut);
    }
}
