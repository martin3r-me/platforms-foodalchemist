<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Services\Knowledge\Wissensart;

/**
 * Spec 52 · Runde D — erreicht ein EINGEORDNETES Dossier überhaupt noch einen Schritt?
 *
 * Der Umbau läuft auf zwei Spuren: uneingeordnete Dossiers bedient das Kategorie-Routing,
 * eingeordnete das Arten-Routing. Ein Dossier wechselt die Spur in dem Moment, wo es eine
 * Art bekommt. **Fehlt für diese Art eine Arten-Zeile, fällt es ins Leere** — ohne
 * Fehlermeldung, weil beide Mechanismen für sich korrekt arbeiten.
 *
 * Das ist die einzige Reihenfolge-Falle des ganzen Umbaus: erst die Spur, dann taggen.
 * Statt diese Regel jemandem zum Merken zu geben, sagt sie hier das System.
 *
 * **Abgrenzung zu den beiden anderen Wächtern:** `wissen-deckung` prüft die KORPUS-Richtung
 * (nennt ein Prompt einen §, muss es ein Dossier geben), `wissen-versorgung` die
 * VERSORGUNGS-Richtung (erreicht diesen Prompt Wissen). Dieser hier prüft die
 * SPUR-Richtung — und die gibt es erst, seit es Arten gibt.
 *
 * Rein lesend. Exit 1, sobald eine Art Dossiers trägt, aber keinen Schritt erreicht.
 */
class WissenSpurenCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-spuren
        {--json : Maschinenlesbar ausgeben}';

    protected $description = 'Runde D: meldet Arten, die Dossiers tragen, aber von keinem Schritt geroutet werden';

    /**
     * Arten, die KEINE Arten-Zeile brauchen — mit Begründung, damit niemand sie „nachrüstet".
     *
     * @var array<string, string>
     */
    private const OHNE_SPUR = [
        Wissensart::REGEL => 'wird über den Kanon zugeordnet, nicht über Routing',
        Wissensart::ABLAUF => 'gehört in keinen Prompt — erreicht Agenten über ablauf.GET',
    ];

    public function handle(): int
    {
        if (! Schema::hasColumn('foodalchemist_knowledge_documents', 'art')
            || ! Schema::hasColumn('foodalchemist_knowledge_routings', 'art')) {
            $this->warn('Arten-Spalten fehlen — nichts zu prüfen.');

            return self::SUCCESS;
        }

        $bestand = DB::table('foodalchemist_knowledge_documents')
            ->whereNotNull('art')->where('active', 1)->whereNull('deleted_at')
            ->select('art', DB::raw('count(*) as n'))->groupBy('art')->pluck('n', 'art');

        $spuren = DB::table('foodalchemist_knowledge_routings')
            ->whereNotNull('art')->where('mode', '!=', 'none')
            ->select('art', 'feature')->get()
            ->groupBy('art')->map(fn ($g) => $g->pluck('feature')->unique()->sort()->values()->all());

        $zeilen = [];
        $befunde = 0;
        foreach ($bestand as $art => $anzahl) {
            $grund = self::OHNE_SPUR[$art] ?? null;
            $features = $spuren[$art] ?? [];
            $offen = $grund === null && $features === [];
            $befunde += $offen ? 1 : 0;
            $zeilen[] = ['art' => (string) $art, 'dossiers' => (int) $anzahl,
                'schritte' => $features, 'braucht_spur' => $grund === null,
                'grund' => $grund, 'befund' => $offen];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['befunde' => $befunde, 'arten' => $zeilen],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $befunde === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($zeilen === []) {
            $this->line('Noch kein Dossier eingeordnet — nichts zu prüfen.');

            return self::SUCCESS;
        }
        foreach ($zeilen as $z) {
            $wo = $z['grund'] !== null
                ? '— '.$z['grund']
                : ($z['schritte'] === [] ? '⚠ KEINE SPUR — diese Dossiers erreichen keinen Schritt' : implode(', ', $z['schritte']));
            $this->line(sprintf('%-12s %5d Dossiers   %s', $z['art'], $z['dossiers'], $wo));
        }
        $this->line('');
        $this->line($befunde === 0
            ? '✓ jede eingeordnete Art wird geroutet'
            : "⚠ {$befunde} Art(en) ohne Spur — erst `knowledge_routings.PUT` mit art=…, dann weiter einordnen");

        return $befunde === 0 ? self::SUCCESS : self::FAILURE;
    }
}
