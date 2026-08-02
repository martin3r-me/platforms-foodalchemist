<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Enums\AusgabeStatus;

/**
 * Spec 33 · P0 — ein Status-Vokabular für die drei Ausgabeformen.
 *
 * Vorher lag in denselben Spalten ein Mischmasch: `draft` neben `entwurf`, `active` neben
 * `aktiv`, dazu `versendet`/`veroeffentlicht` und — im Dev-Bestand tatsächlich vorgefunden —
 * ein `final`, das keine einzige Quelle kennt. Ursache: `status` steht in den `FELDER`-Listen
 * der drei Services und war ohne Validierung mit jedem String beschreibbar (auch über MCP).
 *
 * Diese Migration bildet den Bestand auf {@see AusgabeStatus} ab. Die Abbildungsregel steht
 * bewusst NICHT hier, sondern in `AusgabeStatus::normalisiere()` — dieselbe Funktion dient als
 * Sicherheitsnetz beim Lesen, und zwei Kopien derselben Regel driften auseinander.
 *
 * **Idempotent:** ein zweiter Lauf findet nur noch gültige Werte und schreibt nichts.
 *
 * **Protokoll:** jede Umschreibung wird gezählt und geloggt. Unbekannte Werte landen auf
 * `entwurf` (der einzige Zustand, der nichts behauptet) — wer nach dem Lauf einen Datensatz
 * vermisst, findet im Log, was er vorher war.
 */
return new class extends Migration
{
    /** Tabelle => Klartext fürs Protokoll. */
    private const TABELLEN = [
        'foodalchemist_foodbooks' => 'Foodbook',
        'foodalchemist_menu_cards' => 'Speisekarte',
        'foodalchemist_menu_plans' => 'Speiseplan',
    ];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle => $klartext) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'status')) {
                continue;
            }

            // Ist-Stand VOR dem Schreiben festhalten — ohne das ist hinterher nicht mehr
            // nachvollziehbar, was der Lauf angefasst hat.
            $vorher = DB::table($tabelle)->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')->pluck('n', 'status')->all();

            $geaendert = [];
            foreach ($vorher as $roh => $anzahl) {
                $neu = AusgabeStatus::normalisiere((string) $roh)->value;
                if ((string) $roh === $neu) {
                    continue;
                }

                DB::table($tabelle)->where('status', $roh)->update(['status' => $neu]);

                $unbekannt = ! in_array(mb_strtolower(trim((string) $roh)), AusgabeStatus::bekannteRohwerte(), true);
                $geaendert[] = sprintf('%s → %s (%d%s)', (string) $roh === '' ? '(leer)' : $roh,
                    $neu, $anzahl, $unbekannt ? ', UNBEKANNTER Wert' : '');
            }

            // NULL separat: `whereNull` greift nicht über den Gruppierungs-Weg oben.
            $nullen = DB::table($tabelle)->whereNull('status')->count();
            if ($nullen > 0) {
                DB::table($tabelle)->whereNull('status')->update(['status' => AusgabeStatus::Entwurf->value]);
                $geaendert[] = sprintf('NULL → %s (%d)', AusgabeStatus::Entwurf->value, $nullen);
            }

            Log::info('[Spec 33 P0] Status normalisiert: ' . $klartext, [
                'tabelle' => $tabelle,
                'vorher' => $vorher,
                'geaendert' => $geaendert === [] ? 'nichts (bereits normalisiert)' : $geaendert,
                'nachher' => DB::table($tabelle)->selectRaw('status, COUNT(*) as n')
                    ->groupBy('status')->pluck('n', 'status')->all(),
            ]);
        }
    }

    /**
     * Kein Rückweg. Die Ursprungswerte waren mehrdeutig (`draft` und `entwurf` meinten dasselbe,
     * `final` gar nichts Definiertes) — sie wiederherzustellen hieße raten. Wer den Vorher-Stand
     * braucht, findet ihn im Log des `up()`-Laufs.
     */
    public function down(): void
    {
        // absichtlich leer
    }
};
