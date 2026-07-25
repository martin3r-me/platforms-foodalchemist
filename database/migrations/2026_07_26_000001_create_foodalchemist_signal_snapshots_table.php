<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21 · E1 — Signal-Zeitreihe. Bis hierher war die Datenqualitäts-Ampel eine
 * Momentaufnahme: man sah 252 offene Signale, aber nicht, ob es letzte Woche 340
 * oder 180 waren. „Ständig im Blick behalten" (Auftrag Dominique) braucht Trend.
 *
 * Eine Zeile = ein gemessener Zähler zu einem Lauf-Zeitpunkt. Zwei Quellen teilen
 * die Tabelle (`source`), weil beide dieselbe Frage beantworten (steigt oder fällt
 * dieser Befund?) und ein gemeinsamer Trend-Reader billiger ist als zwei:
 *  - `data-quality` → eine Lücken-Metrik des DataQualityService (`metric_key` =
 *    dessen Metrik-Key, z. B. `rezept_mengen_luecke`). **Auch 0 wird geschrieben** —
 *    ohne Null-Zeile ist „behoben" nicht von „nie gemessen" zu unterscheiden.
 *  - `signals` → offene Signale je SignalTyp (inkl. Detektor-Typen, die die Ampel
 *    nicht kennt: Preis-Anomalie, Vertragsfrist …), Aufschlüsselung in
 *    `severity_counts`.
 *
 * Strikt `team_id`-gescoped (kein visibleToTeam): der Zähler gilt für die Sicht des
 * messenden Teams. Ein Kind-Team, das den Eltern-Katalog mitzählt, hat eine andere
 * Zahl als das Eltern-Team — würde man vererben, addierten sich fremde Zeitreihen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_signal_snapshots')) {
            return;
        }
        Schema::create('foodalchemist_signal_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('source', 32);                       // data-quality | signals
            $table->string('metric_key', 64);                    // DQ-Metrik-Key bzw. SignalTyp-Wert
            $table->string('signal_type', 64)->nullable();       // SignalTyp, wenn die Metrik einen führt
            $table->unsignedInteger('count');
            $table->json('severity_counts')->nullable();         // nur source=signals
            $table->timestamp('measured_at');
            $table->timestamps();
            $table->softDeletes();

            // Trend-Leserichtung: „Serie einer Metrik über die Zeit" ist die einzige Abfrage.
            $table->index(['team_id', 'metric_key', 'measured_at'], 'fa_signal_snap_series_idx');
            // Ein Lauf schreibt je Metrik genau eine Zeile (Doppellauf überschreibt, statt zu doppeln).
            $table->unique(['team_id', 'source', 'metric_key', 'measured_at'], 'fa_signal_snap_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_signal_snapshots');
    }
};
