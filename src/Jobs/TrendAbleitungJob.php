<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\Trends\TrendAbleitungService;

/**
 * Spec 93 D · „Was heißt das für uns“ an einem Trend: ein `propose()` (`trend.ableitung`) liefert Kurzfazit, Bedeutung je Sparte
 * und 3–5 Impulse; Prüfung und Übernahme macht der TrendAbleitungService (nur ableitung*-Spalten, nie über eine manuelle).
 *
 * KI dual (Spec 90 / #1002): Automatik-Lauf, Klick → Plattform-KI (mit Trend-KI-Budget, gemeinsamer Topf mit dem Erschließen);
 * ein MCP-Aufruf (trends.ABLEITUNG, trend_automatik.LAUF) dispatcht mit `extern` → KI-Auftrag, Rollback bis zur Antwort.
 */
class TrendAbleitungJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $timeout = 120;

    public function __construct(
        public int $trendId,
        public int $teamId,
        public bool $extern = false,
        public int $versuch = 0,
    ) {
    }

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "trend-ableitung:{$this->teamId}:{$this->trendId}";
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['trend_id' => $this->trendId, 'team_id' => $this->teamId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['trend_id'], (int) $n['team_id'], true, $versuch);
    }

    public function handle(AiGatewayService $ki): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($ki));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(AiGatewayService $ki): void
    {
        $t = FoodAlchemistTrend::where('team_id', $this->teamId)->find($this->trendId);
        $svc = app(TrendAbleitungService::class);
        if ($t === null || $t->ableitung_quelle === 'manuell') {
            return;
        }
        // Trend-KI-Budget (gemeinsamer Topf mit dem Erschließen); nur der Plattformweg zählt
        if (! $this->extern && app(TrendEingangService::class)->budgetErreicht($this->teamId)) {
            $svc->fehler($t, 'Trend-KI-Budget erreicht');

            return;
        }
        try {
            $vorschlag = $ki->propose('trend.ableitung', $svc->kontext($t));
        } catch (\Throwable $ex) {
            // Plattformweg: roter Call = Fehler am Trend (nicht still). Externer Weg: wartet auf den Auftrag, das Schreiben rollt zurück.
            $svc->fehler($t, 'KI nicht erreichbar ('.class_basename($ex).')');

            return;
        }
        $svc->uebernehmen($t, (array) ($vorschlag->werte ?? []));
    }
}
