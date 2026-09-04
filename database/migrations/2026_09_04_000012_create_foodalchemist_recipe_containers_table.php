<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 51 A — Behälter hängen am ZWECK, nicht am Rezept.
 *
 * Der Auslöser aus der Küche: »Eine Suppe macht man ja nicht im GN warm, sondern im Kipper,
 * Topf — und füllt sie dann um.« Die Suppe kocht im Kipper, kommt in 10-l-Eimer, kühlt ab,
 * lagert, und geht erst am Einsatztag ins GN. Zwei Skalar-Spalten am Rezept
 * (`container_warm_vocab_id` / `container_cold_vocab_id`) können das nicht abbilden — sie
 * kennen nur »warm« und »kalt«, was eine Temperatur-Achse ist und keine Prozess-Achse.
 *
 * Vier Zwecke, ein Vokabular — dieselben Werte stehen in `vocab_containers.eignung`:
 *   abfuellen     direkt nach der Produktion (Eimer, Kanne, Vakuumbeutel, GN mit Deckel)
 *   regenerieren  am Einsatztag (GN im Konvektomat, Bain-Marie, Blech)
 *   ausgabe       am Pass / Buffet (Chafing-GN, Platte, Schale)
 *   transport     dazwischen (Thermobox, Isolierbehälter) — ein Träger, kein Füllbehälter
 *
 * »Kühlen« ist bewusst KEIN Zweck: im Kühlhaus steht der Abfüllbehälter, es gibt keinen
 * Behälterwechsel.
 *
 * Der häufigste gute Fall braucht keine Sonderregel: zeigen `abfuellen` und `regenerieren` auf
 * DENSELBEN Behälter (Ragout im GN mit Deckel, das direkt in den Ofen geht), erkennt der
 * Rechner das als durchgängig und zählt EINMAL — »kein Umfüllen«. Zeigen sie auf verschiedene,
 * zählt er beide und nennt den Umfüll-Schritt. Fehlt die Regenerations-Zeile, obwohl der
 * Abfüllbehälter dafür nicht freigegeben ist, meldet er eine LÜCKE statt stumm weiterzurechnen —
 * genau der Planungsfehler, bei dem am Einsatztag niemand weiss, worin die Suppe warm wird.
 *
 * `referenz_menge_kg` ist die Stammdaten-Pflege in einer Zahl: »in DIESEN Behälter passen X kg
 * von diesem Produkt«. Sie enthält die Schüttdichte bereits — Gulasch, Blattsalat und Sauce
 * ergeben von selbst verschiedene Werte, ohne dass jemand kg/l schätzen muss. Angegeben wird sie
 * am GRÖSSTEN praktikablen Behälter; nach unten skaliert es sauberer als nach oben. Fehlt sie,
 * greift `recipes.dichteklasse` als Rang 2.
 *
 * `skalierung` sagt, was beim Wechsel auf ein anderes Format mitwächst:
 *   tiefer_fuellbar  darf im tieferen Behälter proportional höher stehen (Suppe, Sauce, Püree)
 *   hoehe_gebunden   nur die Fläche skaliert (Gulasch, Gemüse, Reis, Blattsalat)
 *   lagenware        wird gelegt statt geschüttet → Stückpfad (Papadam, Schnitzel, Tartelettes)
 *
 * FK auf den Katalog ist restrictOnDelete — anders als die Bestands-FKs, die alle nullOnDelete
 * sind und beim Löschen einer Vokabel still den Behälter aus Rezepten und Darreichungen
 * entfernen. Die Bestands-FKs bleiben wie sie sind (es kann Zeilen geben, die auf soft-gelöschte
 * Vokabeln zeigen; eine nachträgliche restrict-Migration würde daran scheitern) — geschützt wird
 * dort über die korrigierte Referenz-Zählung in Settings/Behaelter.
 */
return new class extends Migration
{
    private const INDEX = 'fa_recipe_containers_ein_zweck';

    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_containers')) {
            return;
        }

        Schema::create('foodalchemist_recipe_containers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()->comment('Besitzer-Team (D1)');
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();

            $table->string('zweck', 16)->comment('abfuellen | regenerieren | ausgabe | transport');
            $table->foreignId('container_vocab_id')->nullable()
                ->constrained('foodalchemist_vocab_containers')->restrictOnDelete();

            $table->decimal('referenz_menge_kg', 8, 3)->nullable()
                ->comment('Rang 1: so viel passt in GENAU diesen Behälter — enthält die Schüttdichte');
            $table->string('skalierung', 20)->nullable()
                ->comment('tiefer_fuellbar | hoehe_gebunden | lagenware');
            $table->decimal('max_schichthoehe_mm', 8, 1)->nullable()
                ->comment('optional: präzisiert das Kappen beim Wechsel auf einen flacheren Behälter');
            $table->unsignedInteger('stueck_je_behaelter')->nullable()
                ->comment('nur skalierung=lagenware; bezogen auf container_vocab_id');

            $table->text('note')->nullable();
            $table->string('source', 16)->nullable()->comment('Lineage-Trio GL-07 §3');
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_reasoning')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipe_id', 'zweck'], 'fa_recipe_containers_recipe_zweck_idx');
        });

        $this->partiellerUnique();
    }

    /**
     * »Ein Behälter je Rezept und Zweck«. Partielle Indizes gibt es nur in SQLite/PostgreSQL;
     * unter MySQL trägt der Service die Invariante (Präzedenz: 2026_09_04_000001, wo genau diese
     * Asymmetrie eine Testbasis strenger machte als die Wirklichkeit auf demo).
     */
    private function partiellerUnique(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON foodalchemist_recipe_containers (recipe_id, zweck)'
            .' WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_containers');
    }
};
