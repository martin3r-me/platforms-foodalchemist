<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendThemenService;

/**
 * Spec 93 B · Erschließt einen Artikel im Trend-Eingang: ein `propose()` (`trend.eingang_erschliessen`) liefert Merkmale —
 * relevant, eigene Kurz-Zusammenfassung, Thema (an vorhandene Themen angeglichen), Kategorie, Sparten, Tiefe, passender
 * Trend (nur aus der mitgegebenen Liste). Übernahme, Prüfung und Bündeln macht der TrendEingangService.
 *
 * KI dual (Spec 90 / #1002): Plattform-Abruf und Klick in der Oberfläche → Plattform-KI; ein MCP-Aufruf (trend_eingang.POST,
 * trend_eingang.ERSCHLIESSEN) dispatcht mit `extern` → KI-Auftrag statt Provider, Rollback bis zur Antwort,
 * `ki_auftraege.ANTWORT` startet den Job neu. Der Prompt ist stabil sortiert (Themen, Trends), damit der Fingerabdruck hält.
 */
class TrendEingangErschliessenJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $timeout = 120;

    public function __construct(
        public int $eingangId,
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
        return "trend-erschliessen:{$this->teamId}:{$this->eingangId}";
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
        return ['eingang_id' => $this->eingangId, 'team_id' => $this->teamId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['eingang_id'], (int) $n['team_id'], true, $versuch);
    }

    public function handle(AiGatewayService $ki): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($ki));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(AiGatewayService $ki): void
    {
        $e = FoodAlchemistTrendEingang::where('team_id', $this->teamId)->with('quelle')->find($this->eingangId);
        // Idempotent: nur offene oder gescheiterte Einträge; Verworfenes und Erschlossenes bleibt, wie es ist
        if ($e === null || ! in_array($e->status, ['neu', 'fehler'], true)) {
            return;
        }
        $trends = FoodAlchemistTrend::where('team_id', $this->teamId)->whereNotIn('status', ['verworfen', 'archiviert'])
            ->orderBy('id')->limit(150)->get(['id', 'name'])->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all();
        $kontext = [
            'titel' => $e->titel,
            'teaser' => $e->teaser,
            'quelle' => $e->quelle ? array_filter(['name' => $e->quelle->name, 'art' => $e->quelle->art,
                'schwerpunkte' => array_values(array_merge($e->quelle->schwerpunkt_kategorien ?? [], $e->quelle->schwerpunkt_sparten ?? []))]) : null,
            'themen' => app(TrendThemenService::class)->themenNamen($this->teamId),
            'trends' => $trends,
        ];

        // Spec 93 C: eigenes Trend-KI-Budget (null = kein eigener Deckel). Nur der Plattformweg zählt; der MCP-Weg kostet keine Plattform-KI.
        if (! $this->extern && app(\Platform\FoodAlchemist\Services\TrendEingangService::class)->budgetErreicht($this->teamId)) {
            app(TrendEingangService::class)->erschliessungFehlgeschlagen($e, 'Trend-KI-Budget erreicht');

            return;
        }

        try {
            $vorschlag = $ki->propose('trend.eingang_erschliessen', $kontext);
        } catch (\Throwable $ex) {
            // Plattformweg: roter Call = Fehler am Eintrag (nicht still). Externer Weg: wartet auf den Auftrag, das Schreiben rollt zurück.
            app(TrendEingangService::class)->erschliessungFehlgeschlagen($e, 'KI nicht erreichbar ('.class_basename($ex).')');

            return;
        }

        app(TrendEingangService::class)->erschliessungUebernehmen($e, (array) ($vorschlag->werte ?? []), array_column($trends, 'id'));
    }
}
