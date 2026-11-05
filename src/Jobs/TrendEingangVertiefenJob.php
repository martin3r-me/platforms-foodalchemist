<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendVertiefungService;

/**
 * Vertiefung eines Eintrags im Trend-Eingang: liest die verlinkte Seite bzw. das PDF ({@see TrendVertiefungService::quelleLesen}),
 * ein `propose()` (`trend.eingang_vertiefen`) liefert Kernaussagen mit Zahl, Bezug und Fundstelle; die Übernahme behält nur
 * Aussagen mit belegten Zahlen (Zahlen-Riegel). Bezahlschranke, Bot-Sperre oder unlesbare Seite → kein KI-Aufruf.
 *
 * KI dual (Spec 90 / #1002): Knopf und Tageslauf → Plattform-KI; MCP (`trend_eingang.VERTIEFEN`) dispatcht mit `extern`
 * → KI-Auftrag statt Provider, `ki_auftraege.ANTWORT` startet den Job neu. Der Neustart liest die Seite erneut und prüft die
 * Zahlen gegen diesen Stand; das Ziel ist der Eintrag (KiAuftragService::ZIEL_GILT), kleine Seitenänderungen brechen die Antwort nicht.
 */
class TrendEingangVertiefenJob implements KiExternWiederholbar, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, \Platform\FoodAlchemist\Support\MitKiVerursacher, Queueable, SerializesModels;

    public int $timeout = 150;

    public function __construct(
        public int $eingangId,
        public int $teamId,
        public bool $extern = false,
        public int $versuch = 0,
        public string $ausloeser = 'hand',
    ) {}

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "trend-vertiefen:{$this->teamId}:{$this->eingangId}";
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
        return ['eingang_id' => $this->eingangId, 'team_id' => $this->teamId, 'ausloeser' => $this->ausloeser];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['eingang_id'], (int) $n['team_id'], true, $versuch, (string) ($n['ausloeser'] ?? 'hand'));
    }

    public function handle(AiGatewayService $ki): void
    {
        app(KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($ki));
    }

    private function arbeite(AiGatewayService $ki): void
    {
        $svc = app(TrendVertiefungService::class);
        $e = FoodAlchemistTrendEingang::where('team_id', $this->teamId)->with('quelle:id,name,art')->find($this->eingangId);
        // Idempotent: nur angestoßene Einträge; ein Ergebnis bleibt, bis jemand neu vertieft
        if ($e === null || $e->vertiefung_status !== 'laeuft') {
            return;
        }
        // Eigenes Trend-KI-Budget (wie Erschließen): nur der Plattformweg zählt.
        if (! $this->extern && app(TrendEingangService::class)->budgetErreicht($this->teamId)) {
            $svc->fehlgeschlagen($e, 'fehler', 'Trend-KI-Budget erreicht.');

            return;
        }
        $quelle = $svc->quelleLesen((string) $e->url);
        if ($quelle['zugang'] !== 'frei') {
            $svc->fehlgeschlagen($e, $quelle['zugang'], (string) $quelle['grund']);

            return;
        }
        $kontext = [
            'titel' => $e->titel,
            'herausgeber' => $e->herausgeber ?: $e->quelle?->name,
            'url' => $e->link(),
            'art' => $quelle['art'],
            'text' => mb_substr($quelle['text'], 0, TrendVertiefungService::PROMPT_TEXT_MAX),
        ];
        try {
            $vorschlag = $ki->propose('trend.eingang_vertiefen', $kontext, ['target_table' => 'foodalchemist_trend_eingang', 'target_id' => $e->id]);
        } catch (\Throwable $ex) {
            // Plattformweg: Fehler am Eintrag. Externer Weg: wartet auf den Auftrag, das Schreiben rollt zurück.
            $svc->fehlgeschlagen($e, 'fehler', 'KI nicht erreichbar ('.class_basename($ex).').');

            return;
        }
        // Der Zahlen-Riegel prüft gegen genau den Text, den die KI gesehen hat.
        $svc->uebernehmen($e, (array) ($vorschlag->werte ?? []), $kontext['text'], $quelle['art']);
    }
}
