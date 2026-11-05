<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdaten;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdatenEinordnung as Einordnung;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar;
use Platform\FoodAlchemist\Services\MarktdatenService;
use Platform\FoodAlchemist\Services\MarktEinordnungService;

/**
 * KI-Einordnung der Marktdaten: ein `propose()` (`markt.einordnung`) mit den abgerufenen Kennzahlen und den Regelsätzen,
 * Übernahme mit Zahlen- und Prognose-Riegel ({@see MarktEinordnungService::uebernehmen}).
 *
 * KI dual (Spec 90): Abruf aus der Oberfläche bzw. Plattform → Plattform-KI; marktdaten.ABRUF per MCP dispatcht mit `extern`
 * → KI-Auftrag; `ki_auftraege.ANTWORT` startet den Job neu. Der Kontext wird beim Neustart aus denselben gespeicherten Werten
 * gebaut (kein Netz), der Fingerabdruck hält.
 */
class MarktEinordnungJob implements KiExternWiederholbar, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, \Platform\FoodAlchemist\Support\MitKiVerursacher, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $einordnungId, public int $teamId, public bool $extern = false, public int $versuch = 0) {}

    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "markt-einordnung:{$this->teamId}:{$this->einordnungId}";
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
        return ['einordnung_id' => $this->einordnungId, 'team_id' => $this->teamId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['einordnung_id'], (int) $n['team_id'], true, $versuch);
    }

    public function handle(AiGatewayService $ki): void
    {
        app(KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($ki));
    }

    private function arbeite(AiGatewayService $ki): void
    {
        $e = Einordnung::where('team_id', $this->teamId)->find($this->einordnungId);
        $team = Team::find($this->teamId);
        if ($e === null || $team === null || $e->status !== 'laeuft') {
            return;
        }
        $svc = app(MarktEinordnungService::class);
        $kontext = MarktEinordnungService::kontext(app(MarktdatenService::class)->lesenFuerTeam($team));
        if (MarktEinordnungService::hash($kontext) !== $e->werte_hash) {
            // Zwischenzeitlich neue Werte: diese Einordnung gehört zu einem alten Stand, der neue Abruf hat eine eigene.
            $svc->fehlgeschlagen($e, 'Werte haben sich seit dem Anstoß geändert.');

            return;
        }
        try {
            $vorschlag = $ki->propose('markt.einordnung', $kontext, ['target_table' => 'foodalchemist_marktdaten_einordnung', 'target_id' => $e->id]);
        } catch (\Throwable $ex) {
            // Plattformweg: Fehler (die Regelsätze bleiben sichtbar). Externer Weg: wartet auf den Auftrag, Rollback.
            $svc->fehlgeschlagen($e, 'KI nicht erreichbar ('.class_basename($ex).').');

            return;
        }
        $svc->uebernehmen($e, (array) ($vorschlag->werte ?? []), $kontext);
    }
}
